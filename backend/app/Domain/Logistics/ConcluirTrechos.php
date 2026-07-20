<?php

namespace App\Domain\Logistics;

use App\Domain\Capital\AvisoDoPatio;
use App\Domain\Endurance\DescontoDeEndurance;
use App\Domain\Market\Deposito;
use App\Domain\Trade\CreditarEntrega;
use App\Domain\Transport\Conservacao;
use App\Domain\Transport\MercadoDeUsados;
use App\Models\Colony;
use App\Models\FederationLedger;
use App\Models\Ledger;
use App\Models\NeutralZone;
use App\Models\ResourceType;
use App\Models\TradeAgreement;
use App\Models\Vehicle;
use App\Models\ZoneMaterial;
use Carbon\CarbonInterface;
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
    public function __construct(
        private CreditarEntrega $creditarEntrega,
        private Conservacao $conservacao,
        private MercadoDeUsados $usados,
        private AvisoDoPatio $avisoDoPatio,
        private DescontoDeEndurance $descontoDeEndurance,
    ) {}

    /**
     * Quanto durou o trecho que está terminando, em segundos.
     *
     * Sai da **spec**, não do relógio: `arrives_at - departs_at` daria a viagem inteira quando o
     * trecho é o de volta, e um cron atrasado inflaria o desgaste de quem só teve azar de o
     * servidor engasgar. O trecho tem a duração que a distância manda, e ponto.
     */
    private function duracaoDoTrecho(Vehicle $v): int
    {
        return $this->conservacao->segundosDoTrecho($v, (int) $v->distance_slots);
    }

    /** @return int quantos trechos foram fechados */
    public function handle(): int
    {
        $vencidos = Vehicle::where('status', 'em_rota')
            /*
             * ⚠️ O Drone também fica `em_rota`, e NÃO é desta máquina (D-74): ele não carrega carga,
             * não paga tributo e não passa pelo Pátio — quem fecha as pernas dele é o
             * `Drone\ConcluirMissoes`. Sem este filtro, a chegada de um drone cairia no fluxo de
             * entrega, que perguntaria pela carga dele e acharia o nada.
             */
            ->where('type', '!=', \App\Domain\Drone\DroneSpecs::TIPO)
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

                /*
                 * O desgaste do trecho que acabou de terminar (D-60, §16.4): "por horas de uso
                 * ativo, não por tempo desde a fabricação". Cobrado ANTES de o veículo mudar de
                 * estado, porque a `Conservacao` precisa ler o `trip_purpose` — é ele que isenta as
                 * duas viagens de entrega (fábrica e usado), e o `concluirVolta` o apaga.
                 */
                $this->conservacao->cobrarTrecho($v, $this->duracaoDoTrecho($v));

                $v->leg === 'ida' ? $this->concluirIda($v) : $this->concluirVolta($v);
                $fechados++;
            });
        }

        return $fechados;
    }

    private function concluirIda(Vehicle $v): void
    {
        /*
         * O FRETE PÚBLICO chegou (D-76): o caminhão do governo entrega na colônia de destino —
         * **com tributo, como qualquer entrega física** (a arbitragem do D-32; um frete isento
         * seria rota de fuga) — e dá meia-volta para a Garagem, vazio. O ramo vem ANTES do
         * `$origem`: caminhão do governo não tem colônia de origem.
         */
        if ($v->trip_purpose === 'frete') {
            $destino = Colony::find($v->destination_id);

            if ($destino) {
                foreach ($v->cargo_json ?? [] as $recurso => $qtd) {
                    // Origem e destino são a mesma colônia: é ELA que retira o próprio lote, e é
                    // dela o tributo — exatamente como na retirada com veículo próprio (§25.8).
                    $this->entregar($destino, $destino, $v, $recurso, (int) $qtd, 'frete');
                }
            }

            $this->iniciarVolta($v, manterCarga: false, carga: null);

            return;
        }

        /*
         * Reposicionamento (D-109): o veículo saiu vazio só para mudar de lugar — do Pátio ou de
         * casa, rumo à própria colônia, à Capital, ou a uma Zona Neutra sua. Sem carga, sem
         * tributo, sem volta: ele chega e FICA, onde quer que seja.
         */
        if ($v->trip_purpose === 'reposicionamento') {
            if ($v->destination_type === 'mercado_central') {
                // Mesmo caminho de quem chega com carga e descarrega tudo: estaciona no Pátio,
                // com o mesmo aviso do D-91.
                $this->estacionar($v);

                return;
            }

            $local = $v->destination_type === 'zona_neutra' ? Vehicle::NA_ZONA : Vehicle::EM_CASA;
            $this->terminarViagem($v, $local);

            return;
        }

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

            /*
             * D-65, e é aqui que a decisão do usuário mora: descarregou tudo, **fica estacionado
             * no Pátio**. A viagem era só de ida e acabou.
             *
             * Se sobrou carga (o teto do depósito barrou parte dela), o veículo **volta na hora**
             * com a sobra — o usuário escolheu isso: só estaciona quem descarregou tudo. Essa volta
             * não foi paga no despacho, e **não é cobrada**: quem a causou foi o teto, não o colono,
             * e cobrar energia de uma viagem que ele não pediu seria multar o azar.
             */
            if ($sobra === []) {
                $this->estacionar($v);

                return;
            }

            $v->forceFill(['return_distance_slots' => $v->distance_slots])->save();
            $this->iniciarVolta($v, manterCarga: false, carga: $sobra);

            return;
        }

        /*
         * Contribuição ao fundo da federação (D-114) — mesmo raciocínio geográfico e a mesma regra
         * de "só de ida, estaciona no Pátio" do Mercado Central. Tributada NORMALMENTE (§25.2, como
         * qualquer entrega): a Fatia 1 não mexe em desconto de tributo entre aliados — isso é uma
         * fatia futura, e ela precisa que hoje incida 100% para fazer sentido.
         */
        if ($v->destination_type === 'federacao') {
            if ($origem) {
                foreach ($v->cargo_json ?? [] as $recurso => $qtd) {
                    $this->depositarNaFederacao($origem, $v, $recurso, (int) $qtd);
                }
            }

            $this->estacionar($v);

            return;
        }

        /*
         * Material de obra chegando a uma zona neutra (D-67).
         *
         * Descarrega no **canteiro** (`zone_materials`) e o veículo volta. **Sem tributo**: o §25.2
         * tributa a entrega comercial, e isto não é comércio — é o colono levando o próprio material
         * para o próprio território. Cobrar tributo aqui seria taxá-lo por construir em casa.
         */
        if ($v->destination_type === 'zona_neutra') {
            $zona = NeutralZone::find($v->destination_id);

            /*
             * ⚠️ Correção de 2026-07-19 (D-124): o D-122 tinha dado um teto de rejeição ao
             * canteiro (capacidadeDeposito(), sobra volta na carroceria) — e isso quebrou a
             * entrega de verdade: uma zona de produção já tinha 1.350 no canteiro (herança de
             * quando não havia teto nenhum), a capacidade dela é 500, e `capacidade - ocupado`
             * negativo travava QUALQUER entrega nova em silêncio, sem aviso — "Despachar
             * Material" parecia não fazer nada.
             *
             * O D-66 já tinha passado por este EXATO dilema, do lado da extração, e escolheu o
             * caminho oposto ao que o D-122 tomou aqui: "a extração deixa de parar no teto... o
             * excedente empilha ao relento" — porque um teto de REJEIÇÃO transformaria em zero o
             * que devia ser risco. O canteiro segue o mesmo caminho agora: SEM teto de entrega —
             * tudo que chega é aceito, como sempre foi —, e o risco de acumular demais é o
             * saqueável do D-122 (`Protegido::saqueDetalhado()`), não uma porta fechada.
             */
            if ($zona) {
                foreach ($v->cargo_json ?? [] as $recurso => $qtd) {
                    ZoneMaterial::query()
                        ->firstOrCreate(
                            ['zone_id' => $zona->id, 'resource_type' => $recurso],
                            ['amount' => 0],
                        )
                        ->increment('amount', (int) $qtd);
                }
            }

            // Se a zona sumiu no trajeto, a carga se perde — como já acontece com a colônia apagada.
            $this->iniciarVolta($v, manterCarga: false);

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

        /*
         * Só de ida (D-65): o caminhão que saiu do Pátio para a **própria** colônia acabou de
         * chegar em casa. Não há volta — ele já está onde ia ficar.
         */
        if ($v->return_distance_slots === null) {
            $this->terminarViagem($v, Vehicle::EM_CASA);

            return;
        }

        $this->iniciarVolta($v, manterCarga: false);
    }

    /**
     * O veículo fica no Pátio Logístico da Capital (D-65).
     *
     * `parked_at` é o relógio da vaga, e `patio_cobrado_ate` nasce igual a ele: a hora começa a
     * correr da chegada, não do próximo tick — senão um veículo que chegasse logo depois de um
     * tick ganharia uma hora de graça.
     */
    private function estacionar(Vehicle $v): void
    {
        $chegada = $v->arrives_at->copy();

        $this->terminarViagem($v, Vehicle::NO_PATIO, $chegada);

        // D-91: a Capital avisa pelo rádio assim que o relógio da tarifa começa a correr.
        $this->avisoDoPatio->estacionou($v->fresh());
    }

    /** Fim de viagem: o veículo para, onde quer que seja, e esquece tudo o que era da viagem. */
    private function terminarViagem(Vehicle $v, string $local, ?CarbonInterface $chegada = null): void
    {
        // Estacionado numa zona (D-109), `destination_type`/`destination_id` NÃO se limpam — viram
        // "em qual zona está", em vez de "para onde vai". É a mesma dupla de colunas, sem migração
        // nova; `Vehicle::naZona()` é quem sabe reinterpretar.
        $naZona = $local === Vehicle::NA_ZONA;

        $v->forceFill([
            'status' => 'ocioso',
            'local' => $local,
            'leg' => null,
            'trip_purpose' => null,
            'destination_type' => $naZona ? $v->destination_type : null,
            'destination_id' => $naZona ? $v->destination_id : null,
            'distance_slots' => null,
            'return_distance_slots' => null,
            'departs_at' => null,
            'arrives_at' => null,
            'parked_at' => $local === Vehicle::NO_PATIO ? $chegada : null,
            'patio_cobrado_ate' => $local === Vehicle::NO_PATIO ? $chegada : null,
            'cargo_json' => null,
            'trade_agreement_id' => null,
        ])->save();
    }

    private function iniciarVolta(Vehicle $v, bool $manterCarga, ?array $carga = null): void
    {
        // A perna da volta pode ter distância própria (D-65): o caminhão que saiu do Pátio entregou
        // num colono e agora vai para **casa**, que não fica à mesma distância. `distance_slots`
        // passa a ser a da volta, porque é o trecho que está sendo rodado — é dela que saem o
        // tempo e o desgaste.
        $volta = (int) ($v->return_distance_slots ?? $v->distance_slots);

        $v->forceFill([
            'leg' => 'volta',
            'distance_slots' => $volta,
            'cargo_json' => $manterCarga ? $v->cargo_json : $carga,
            // A volta parte de quando a ida terminou, não de `now()`: se o cron atrasar, o
            // atraso não pode encurtar nem alongar o trecho de volta.
            //
            // Pela Conservacao: a ida acabou de cobrar o seu desgaste, então a volta já é um pouco
            // mais lenta que a ida foi. É o §16.4 a morder dentro da própria viagem.
            'arrives_at' => $v->arrives_at->copy()->addSeconds(
                $this->conservacao->segundosDoTrecho($v, $volta),
            ),
        ])->save();
    }

    private function concluirVolta(Vehicle $v): void
    {
        // O caminhão do governo voltou à Garagem (D-76): livre para o próximo frete.
        if ($v->trip_purpose === 'frete') {
            $v->forceFill([
                'status' => 'ocioso',
                'local' => Vehicle::NO_PATIO,
                'leg' => null,
                'trip_purpose' => null,
                'destination_type' => null,
                'destination_id' => null,
                'distance_slots' => null,
                'return_distance_slots' => null,
                'departs_at' => null,
                'arrives_at' => null,
                'cargo_json' => null,
            ])->save();

            return;
        }

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
        } elseif ($v->trip_purpose === 'venda_usado') {
            /*
             * O usado chegou (D-60, fatia 3). O veículo já é do comprador desde a compra — o que
             * faltava era **pagar o vendedor**, e é agora: o Ministério solta o escrow que reteve.
             * Só a chegada libera, e é isso que torna o calote impossível neste canal.
             *
             * A entrega de fábrica (`entrega_de_fabrica`) não cai aqui e não precisa: ela não tem
             * vendedor a quem pagar, e o veículo só tinha de chegar.
             */
            $this->usados->concluirEntrega($v);
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

        // A volta é sempre para casa — inclusive a do caminhão que saiu do Pátio e entregou num
        // colono. Quem quiser o veículo de volta na Capital manda-o depositar de novo.
        $this->terminarViagem($v, Vehicle::EM_CASA);
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
     * Credita o líquido no fundo da FEDERAÇÃO DA COLÔNIA NA HORA DA CHEGADA (D-114), não a de
     * quando o veículo partiu — a viagem não carrega um `destination_id` fixo (ver
     * `DespacharVeiculo::resolverDestino`). Se a colônia tiver saído da federação no meio do
     * trajeto, a carga se perde — mesmo tratamento que "a colônia de destino sumiu no trajeto" já
     * recebe em `entregar()`; não há para onde devolvê-la.
     *
     * Sem `Ledger` novo na colônia de origem: o débito de saída já foi lançado no despacho
     * (`transferencia`, em `DespacharVeiculo::debitarEstoque`), e o fundo não é mais "dela" — é
     * como uma entrega a outro colono (`entregar()`), não como o depósito do Mercado
     * (`depositarNoMercado`), que é a mesma colônia depositando em si mesma.
     */
    private function depositarNaFederacao(Colony $origem, Vehicle $v, string $recurso, int $qtd): void
    {
        $tipo = ResourceType::find($recurso);

        if (! $tipo || $origem->federation_id === null) {
            return;
        }

        $chave = $this->chave('federacao', $v, $recurso);
        $tributo = intdiv($qtd * $tipo->tax_bps, 10_000);
        $liquido = $qtd - $tributo;

        if (! $this->tributar($chave, $origem, $recurso, $qtd, $tipo->tax_bps, $tributo)) {
            return;
        }

        DB::table('federation_holdings')->insertOrIgnore([
            'federation_id' => $origem->federation_id,
            'resource_type' => $recurso,
            'amount' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('federation_holdings')
            ->where('federation_id', $origem->federation_id)
            ->where('resource_type', $recurso)
            ->increment('amount', $liquido);

        FederationLedger::create([
            'federation_id' => $origem->federation_id,
            'colony_id' => $origem->id,
            'type' => 'credito',
            'amount' => $liquido,
            'resource_type' => $recurso,
            'ref' => $chave,
            'created_at' => now(),
        ]);

        $this->lancarTributo($origem, $tributo, $recurso, $chave);
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
        $bps = $this->aliquota($origem, $destino, $tipo->tax_bps);

        // Truncamento: o tributo é retido em unidades inteiras do próprio recurso (D-12), e
        // arredondar para cima cobraria mais do que a alíquota em cargas pequenas.
        $tributo = intdiv($qtd * $bps, 10_000);
        $liquido = $qtd - $tributo;

        if (! $this->tributar($chave, $origem, $recurso, $qtd, $bps, $tributo)) {
            return null;
        }

        $destino->resources()->where('resource_type', $recurso)->increment('amount', $liquido);

        $this->lancar($destino, 'transferencia', $liquido, $recurso, $chave);
        $this->lancarTributo($origem, $tributo, $recurso, $chave);

        return $liquido;
    }

    /**
     * A alíquota efetiva, com o desconto entre aliados do §04/§07 (D-114, D-120: "50% de desconto
     * nos tributos entre aliadas", v3.0).
     *
     * Só se aplica a uma entrega de VERDADE entre DUAS colônias diferentes, e as duas na MESMA
     * federação. `entregar()` também é chamada com `$origem === $destino` para o frete público
     * (D-76) e a retirada do Mercado (§25.8) — a colônia recebendo o próprio lote não é "comércio
     * entre aliados", é ela mesma, e não pode dar desconto em si própria.
     */
    private function aliquota(Colony $origem, Colony $destino, int $bpsCheio): int
    {
        $aliados = $origem->id !== $destino->id
            && $origem->federation_id !== null
            && $origem->federation_id === $destino->federation_id;

        if ($aliados) {
            $desconto = \App\Models\FederationSetting::singleton()->desconto_tributo_aliados_bps;
            $bpsCheio = intdiv($bpsCheio * (10_000 - $desconto), 10_000);
        }

        // D-132: o desconto de peças da Endurance é pessoal da colônia que entrega — vem por cima
        // do que o desconto de aliados já reduziu, nunca antes dele.
        return $this->descontoDeEndurance->aplicar($bpsCheio, $origem);
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
            app(\App\Domain\Treasury\Tesouro::class)->creditarRecurso($recurso, $tributo, "tributo_transporte:{$ref}");
        }
    }
}
