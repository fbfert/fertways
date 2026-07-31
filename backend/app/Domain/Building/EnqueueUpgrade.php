<?php

namespace App\Domain\Building;

use App\Exceptions\DomainRuleException;
use App\Models\Building;
use App\Models\BuildQueue;
use App\Models\Colony;
use App\Models\Ledger;
use Illuminate\Support\Facades\DB;

/**
 * Enfileira a construção ou upgrade de uma estrutura do slot principal.
 *
 * Regras do GDD aplicadas:
 *  - §24.7: as cinco essenciais são custeadas em 100% pelo Governo Central até o nível 3,
 *    "mediante conclusão da tutoria". A partir do nível 4, custo integral. Construções de
 *    progressão "nunca são subsidiadas".
 *  - §4.1: "upgrade em fila mantém o custo cotado no momento da confirmação transacional".
 *    Daí `quoted_cost_json`: um rebalanceamento futuro não recota o que já está na fila.
 *  - Onboarding: fila dupla nos primeiros 5 dias completos de conta; depois, fila única.
 *  - D-10: construção sem tempo publicado no GDD não pode ser enfileirada.
 *
 * Sobre o momento da cobrança: o GDD só diz que o subsídio "é registrado no ledger no momento
 * de concluir". Não diz quando o custo próprio é debitado. Debitamos no enfileiramento — caso
 * contrário o jogador enfileiraria mais do que pode pagar e a conclusão falharia. Ver D-15.
 */
class EnqueueUpgrade
{
    public function __construct(private readonly BuildingSpecs $specs) {}

    public function handle(Colony $colony, Building $building): BuildQueue
    {
        if ($building->colony_id !== $colony->id) {
            throw new DomainRuleException('construcao_de_outra_colonia', 'Esta construção não é sua.');
        }

        return DB::transaction(function () use ($colony, $building) {
            // Trava a colônia: duas requisições simultâneas não podem gastar o mesmo recurso
            // nem ocupar a mesma vaga de fila.
            $colony = Colony::whereKey($colony->id)->lockForUpdate()->firstOrFail();
            $user = $colony->user;

            $naFila = BuildQueue::where('colony_id', $colony->id)->ativos()->get();

            if ($naFila->contains('building_id', $building->id)) {
                throw new DomainRuleException('ja_na_fila', 'Esta construção já está na fila.');
            }

            $vagas = BuildQueue::vagasDe($user);
            if ($naFila->count() >= $vagas) {
                throw new DomainRuleException(
                    'fila_cheia',
                    "A fila comporta {$vagas} " . ($vagas === 1 ? 'item' : 'itens') . ' no momento.',
                );
            }

            $alvo = $building->level + 1;
            $max = $this->specs->nivelMaximo($building->type);

            if ($alvo > $max) {
                throw new DomainRuleException(
                    'nivel_maximo',
                    "{$building->type} já está no nível máximo ({$max}).",
                );
            }

            $spec = $this->specs->para($building->type, $alvo);

            // §24.7: essencial, até o nível 3, e só depois da tutoria.
            $subsidiado = $building->ehEssencial() && $alvo <= 3 && $user->tutoriaConcluida();

            if (! $subsidiado) {
                $this->debitarRecursos($colony, $spec['custo'], $building, $alvo);
            }

            $agora = now();
            $emConstrucao = $naFila->firstWhere('status', 'building');

            $item = BuildQueue::create([
                'colony_id' => $colony->id,
                'building_id' => $building->id,
                'target_level' => $alvo,
                // Máximo entre os ativos, e não sobre a tabela: item concluído tem `position`
                // NULL (D-53), e somar sobre a tabela inteira estouraria o tinyint em 255
                // construções.
                'position' => ($naFila->max('position') ?? 0) + 1,
                'quoted_cost_json' => $spec['custo'],
                'subsidized' => $subsidiado,
                'enqueued_at' => $agora,
                // Só o primeiro item começa a construir; os demais esperam o tick promovê-los.
                'starts_at' => $emConstrucao ? null : $agora,
                'finishes_at' => $emConstrucao ? null : $agora->copy()->addSeconds($spec['tempo_segundos']),
                'status' => $emConstrucao ? 'queued' : 'building',
            ]);

            if ($item->status === 'building') {
                $building->update([
                    'upgrade_started_at' => $item->starts_at,
                    'upgrade_finish_at' => $item->finishes_at,
                ]);
            }

            return $item;
        });
    }

    /** @param array<string,int> $custo */
    private function debitarRecursos(Colony $colony, array $custo, Building $building, int $alvo): void
    {
        $estoque = $colony->resources()->lockForUpdate()->get()->keyBy('resource_type');

        foreach ($custo as $recurso => $qtd) {
            $linha = $estoque->get($recurso);

            if (! $linha || $linha->amount < $qtd) {
                $tem = $linha?->amount ?? 0;
                /*
                 * Telemetria (A2.0.1): a parede em que o colono bateu.
                 *
                 * O ledger não vê isto por definição — ele registra o que ACONTECEU, e isto é o
                 * registro do que NÃO aconteceu. É a métrica mais valiosa da fase: é onde o jogo
                 * trava sem avisar ninguém, e alimenta os "gargalos de cadeia" da A2.0.2.
                 */
                app(\App\Domain\Telemetria\RegistrarEvento::class)->handle(
                    'falta_de_insumo', $colony->user, $colony,
                    ['recurso' => $recurso, 'exige' => $qtd, 'tem' => $tem, 'onde' => 'obra'],
                );

                throw new DomainRuleException(
                    'recursos_insuficientes',
                    "Faltam recursos: {$recurso} exige {$qtd}, você tem {$tem}.",
                );
            }
        }

        foreach ($custo as $recurso => $qtd) {
            $estoque[$recurso]->decrement('amount', $qtd);

            Ledger::create([
                'colony_id' => $colony->id,
                'type' => 'custo_construcao',
                'amount' => -$qtd,
                'resource_type' => $recurso,
                'ref' => "build:{$building->type}:n{$alvo}",
                'created_at' => now(),
            ]);
        }
    }
}
