<?php

namespace App\Domain\Logistics;

use App\Domain\Market\Deposito;
use App\Domain\Trade\CreditarEntrega;
use App\Models\Colony;
use App\Models\Ledger;
use App\Models\ResourceType;
use App\Models\TradeAgreement;
use App\Models\Vehicle;
use Illuminate\Support\Facades\DB;

/**
 * Fecha os trechos de viagem vencidos. Chamado pelo tick.
 *
 * Fica fora do laço por colônia de propósito: um veículo da colônia A entrega na colônia B, e o
 * relógio da viagem não tem relação com o `last_tick_at` de nenhuma das duas.
 *
 * Trecho de **ida** (§25.3, passo 4): a carga é entregue e o tributo sobre o volume incide nesse
 * exato momento. O usuário decidiu (2026-07-08) que vale o §25.2 — "incide sempre que um veículo
 * entrega carga em qualquer destino" — e não o §8.3, que falava em cobrar na saída. Um veículo
 * perdido antes de entregar não gera lançamento tributário.
 *
 * Trecho de **volta** (§25.5): só ao completá-lo o veículo volta a ficar disponível. Numa
 * **retirada** do Mercado (§25.8) é também nele que a carga chega ao slot do colono, e é aí que
 * o tributo incide — "mesma lógica de distância e tributo na chegada".
 */
class ConcluirTrechos
{
    public function __construct(private CreditarEntrega $creditarEntrega) {}

    /** @return int quantos trechos foram fechados */
    public function handle(): int
    {
        $vencidos = Vehicle::where('status', 'em_rota')
            ->where('arrives_at', '<=', now())
            ->orderBy('arrives_at')
            ->get();

        $fechados = 0;

        foreach ($vencidos as $veiculo) {
            DB::transaction(function () use ($veiculo, &$fechados) {
                // Relê com lock: o tick pode rodar concorrente com outra instância do cron.
                $v = Vehicle::whereKey($veiculo->id)->lockForUpdate()->first();

                if (! $v || $v->status !== 'em_rota' || $v->arrives_at > now()) {
                    return;
                }

                $v->leg === 'ida' ? $this->concluirIda($v) : $this->concluirVolta($v);
                $fechados++;
            });
        }

        return $fechados;
    }

    private function concluirIda(Vehicle $v): void
    {
        $origem = Colony::find($v->colony_id);

        /*
         * Retirada: o veículo chegou vazio ao Mercado e embarca a carga que já reservou no
         * despacho. Nada é entregue e nada é tributado aqui — a carga ainda não chegou a
         * lugar nenhum. Por isso `cargo_json` sobrevive ao trecho.
         */
        if ($v->trip_purpose === 'retirada') {
            $this->iniciarVolta($v, manterCarga: true);

            return;
        }

        if ($v->destination_type === 'mercado_central') {
            $sobra = [];

            if ($origem) {
                foreach ($v->cargo_json ?? [] as $recurso => $qtd) {
                    $excedente = $this->depositarNoMercado($origem, $v, $recurso, (int) $qtd);

                    // D-58: o que não coube no teto não foi entregue. Volta na carroceria, e o
                    // tributo já foi calculado só sobre o que entrou.
                    if ($excedente > 0) {
                        $sobra[$recurso] = $excedente;
                    }
                }
            }

            $this->iniciarVolta($v, manterCarga: false, carga: $sobra ?: null);

            return;
        }

        $destino = Colony::find($v->destination_id);

        // A colônia de destino pode ter sido apagada durante o trajeto. A carga se perde; o
        // veículo volta. Não há regra no GDD para isso, e evaporar é melhor que travar o veículo.
        if ($destino && $origem) {
            // D-41: só a carga que aponta um acordo o abate. Um presente casual entre os mesmos
            // colonos não paga promessa nenhuma.
            $acordo = $v->trade_agreement_id ? TradeAgreement::find($v->trade_agreement_id) : null;

            foreach ($v->cargo_json ?? [] as $recurso => $qtd) {
                $liquido = $this->entregar($origem, $destino, $v, $recurso, (int) $qtd, 'entrega');

                // `null` significa lote já tributado numa execução anterior do tick: a carga
                // também já foi creditada, e o acordo também. Creditar de novo pagaria em dobro.
                if ($acordo && $liquido !== null) {
                    $this->creditarEntrega->handle($acordo, $origem->id, $destino->id, $recurso, $liquido);
                }
            }
        }

        $this->iniciarVolta($v, manterCarga: false);
    }

    private function iniciarVolta(Vehicle $v, bool $manterCarga, ?array $carga = null): void
    {
        $v->forceFill([
            'leg' => 'volta',
            'cargo_json' => $manterCarga ? $v->cargo_json : $carga,
            // A volta parte de quando a ida terminou, não de `now()`: se o cron atrasar, o
            // atraso não pode encurtar nem alongar o trecho de volta.
            'arrives_at' => $v->arrives_at->copy()->addSeconds(
                VeiculoSpecs::segundosDoTrecho($v->type, $v->distance_slots),
            ),
        ])->save();
    }

    private function concluirVolta(Vehicle $v): void
    {
        /*
         * §25.8: o colono "precisa enviar um veículo próprio para retirá-lo e levá-lo até seu
         * slot — mesma lógica de distância e tributo na chegada". A chegada é aqui: origem e
         * destino são a mesma colônia, e ela paga o tributo sobre o que retirou.
         */
        if ($v->trip_purpose === 'retirada') {
            $colonia = Colony::find($v->colony_id);

            if ($colonia) {
                foreach ($v->cargo_json ?? [] as $recurso => $qtd) {
                    $this->entregar($colonia, $colonia, $v, $recurso, (int) $qtd, 'retirada');
                }
            }
        } elseif ($v->cargo_json) {
            /*
             * D-58: carga que sobrou de um depósito no Mercado, por não caber no teto. Ela volta
             * ao estoque **sem tributo**: o tributo incide na entrega física (§25.8), e esta carga
             * não foi entregue a lugar nenhum — cobrá-la aqui faturaria uma entrega que não houve.
             * Não precisa de `tax_event`: a idempotência vem do `lockForUpdate` e do estado do
             * veículo, que sai de `em_rota` dentro da mesma transação.
             */
            $colonia = Colony::find($v->colony_id);

            if ($colonia) {
                foreach ($v->cargo_json as $recurso => $qtd) {
                    $colonia->resources()->where('resource_type', $recurso)->increment('amount', (int) $qtd);
                    $this->lancar($colonia, 'devolucao_deposito', (int) $qtd, $recurso, "sobra:{$v->id}:{$v->departs_at->getTimestamp()}:{$recurso}");
                }
            }
        }

        $v->forceFill([
            'status' => 'ocioso',
            'leg' => null,
            'trip_purpose' => null,
            'destination_type' => null,
            'destination_id' => null,
            'distance_slots' => null,
            'departs_at' => null,
            'arrives_at' => null,
            'cargo_json' => null,
            'trade_agreement_id' => null,
        ])->save();
    }

    /**
     * Depósito na conta do colono no Mercado Central (§25.8). É uma entrega física como
     * qualquer outra: "Tributo de transporte cobrado na entrega, exatamente como qualquer
     * outro destino". Quem credita é a conta, não o estoque da colônia.
     */
    private function depositarNoMercado(Colony $origem, Vehicle $v, string $recurso, int $qtd): int
    {
        $tipo = ResourceType::find($recurso);

        if (! $tipo) {
            return 0;
        }

        /*
         * D-58: o depósito tem teto. O despacho já recusa o que não cabe, mas a viagem demora — e
         * outra entrega, ou uma compra executada, pode ter enchido o depósito no meio do caminho.
         * Quem cabe é o **líquido**, e é o **bruto** que decide o tributo: por isso o cálculo é
         * inverso, em `Deposito::brutoQueCabe()`.
         */
        $bruto = Deposito::brutoQueCabe($qtd, (int) $tipo->tax_bps, Deposito::livre($origem->id, $recurso));
        $excedente = $qtd - $bruto;

        if ($bruto === 0) {
            return $excedente;
        }

        $chave = $this->chave('deposito', $v, $recurso);
        $tributo = intdiv($bruto * $tipo->tax_bps, 10_000);
        $liquido = $bruto - $tributo;

        if (! $this->tributar($chave, $origem, $recurso, $bruto, $tipo->tax_bps, $tributo)) {
            return 0;
        }

        // A conta pode não existir ainda: este é o primeiro depósito deste recurso. O
        // `insertOrIgnore` deixa duas entregas simultâneas do mesmo recurso criarem a linha
        // sem que uma delas estoure no índice único.
        DB::table('market_accounts')->insertOrIgnore([
            'colony_id' => $origem->id,
            'resource_type' => $recurso,
            'amount' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('market_accounts')
            ->where('colony_id', $origem->id)
            ->where('resource_type', $recurso)
            ->increment('amount', $liquido);

        $this->lancar($origem, 'deposito_mercado', $liquido, $recurso, $chave);
        $this->lancarTributo($origem, $tributo, $recurso, $chave);

        return $excedente;
    }

    /**
     * @return int|null quanto entrou de fato no estoque do destino, líquido de tributo; `null`
     *                  se este lote já fora entregue numa execução anterior do tick
     */
    private function entregar(Colony $origem, Colony $destino, Vehicle $v, string $recurso, int $qtd, string $prefixo): ?int
    {
        $tipo = ResourceType::find($recurso);

        if (! $tipo) {
            return null;
        }

        $chave = $this->chave($prefixo, $v, $recurso);

        // Truncamento: o tributo é retido em unidades inteiras do próprio recurso (D-12), e
        // arredondar para cima cobraria mais do que a alíquota em cargas pequenas.
        $tributo = intdiv($qtd * $tipo->tax_bps, 10_000);
        $liquido = $qtd - $tributo;

        if (! $this->tributar($chave, $origem, $recurso, $qtd, $tipo->tax_bps, $tributo)) {
            return null;
        }

        $destino->resources()->where('resource_type', $recurso)->increment('amount', $liquido);

        $this->lancar($destino, 'transferencia', $liquido, $recurso, $chave);
        $this->lancarTributo($origem, $tributo, $recurso, $chave);

        return $liquido;
    }

    /**
     * "Uma incidência por fato econômico/lote" (GDD, seção 0 e §25.9) não é regra de
     * aplicação, é invariante de dados. A chave deriva do **evento de entrega** — veículo e
     * instante de partida identificam a viagem, e o recurso identifica o lote. Um retry do
     * tick, ou dois crons concorrentes, colidem no índice único e não tributam duas vezes.
     *
     * O prefixo separa os dois fatos tributáveis de uma mesma viagem de retirada: nenhum é
     * gerado, mas a ida de uma entrega e a volta de uma retirada nunca poderiam colidir.
     */
    private function chave(string $prefixo, Vehicle $v, string $recurso): string
    {
        return "{$prefixo}:{$v->id}:{$v->departs_at->getTimestamp()}:{$recurso}";
    }

    /** @return bool false se este lote já foi tributado numa execução anterior do tick */
    private function tributar(string $chave, Colony $origem, string $recurso, int $qtd, int $bps, int $tributo): bool
    {
        $inserido = DB::table('tax_events')->insertOrIgnore([
            'economic_event_key' => $chave,
            'kind' => 'transporte_entrega',
            'colony_id' => $origem->id,
            'resource_type' => $recurso,
            'base_amount' => $qtd,
            'tax_bps' => $bps,
            'tax_amount' => $tributo,
            'created_at' => now(),
        ]);

        // Já tributado numa execução anterior: a carga também já foi creditada. Sair sem creditar
        // de novo é o que torna o tick seguro para repetir.
        return $inserido !== 0;
    }

    private function lancar(Colony $colonia, string $tipo, int $valor, string $recurso, string $ref): void
    {
        Ledger::create([
            'colony_id' => $colonia->id,
            'type' => $tipo,
            'amount' => $valor,
            'resource_type' => $recurso,
            'ref' => $ref,
            'created_at' => now(),
        ]);
    }

    private function lancarTributo(Colony $origem, int $tributo, string $recurso, string $ref): void
    {
        if ($tributo > 0) {
            $this->lancar($origem, 'tributo', -$tributo, $recurso, $ref);
            // O tributo não some mais: entra no Ministério do Tesouro (§2.1, D-57). Só aqui, depois de
            // `tributar()` ter aprovado o `tax_event` — a idempotência do tick já está garantida.
            app(\App\Domain\Treasury\Tesouro::class)->creditarRecurso($recurso, $tributo);
        }
    }
}
