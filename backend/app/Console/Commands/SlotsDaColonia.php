<?php

namespace App\Console\Commands;

use App\Domain\Colony\Slots;
use App\Models\Building;
use App\Models\BuildQueue;
use App\Models\Colony;
use App\Models\Ledger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Backfill dos 22 slots (D-59, D-105) para as colônias que já existem. As novas já nascem certas,
 * no `CreateColony`. Passo à parte do deploy, como o `fertways:placas` (D-60).
 *
 *   artisan fertways:slots            # simula: mostra o que faria em cada colônia
 *   artisan fertways:slots --aplicar  # migra de verdade
 *
 * O que ele faz em cada colônia, nesta ordem:
 *
 *  1. **Põe as cinco essenciais no miolo, e o Depósito Local no slot 21.** Quem já existe ganha o
 *     slot fixo; quem estiver no nível 0 é promovido ao nível 1 (nascem erguidos — D-59/D-105),
 *     com o custo lançado no ledger como `subsidio_governo`, exatamente como na fundação. O
 *     Depósito é o mesmo tratamento do miolo, só que fora dele: sem ele a colônia já migrada não
 *     teria como ver os recursos, que saíram da barra lateral sempre visível.
 *  2. **Apaga as construções nível 0** que ninguém está erguendo: elas eram o desenho antigo
 *     (16 linhas criadas na fundação) e agora significam "slot vazio". Uma construção que **está
 *     na fila** é preservada e ganha slot — cancelar a obra de alguém seria roubo.
 *  3. **Distribui o que está erguido** pelos slots de fora, preservando o nível.
 *
 * Idempotente: rodar de novo não move nada de lugar nem concede nível duas vezes.
 */
class SlotsDaColonia extends Command
{
    protected $signature = 'fertways:slots {--aplicar : migra de verdade; sem isto, só simula}';

    protected $description = 'Backfill dos 22 slots, do miolo e do Depósito Local erguidos (D-59, D-105) nas colônias existentes';

    public function handle(): int
    {
        $aplicar = (bool) $this->option('aplicar');

        foreach (Colony::orderBy('id')->get() as $colony) {
            $this->line("── colônia {$colony->id} ({$colony->name})");

            if ($aplicar) {
                DB::transaction(fn () => $this->migrar($colony));

                continue;
            }

            // A simulação corre a MESMA rotina e desfaz tudo com um rollback: assim o que ela
            // imprime é o que o --aplicar faria, e não uma segunda implementação que pode divergir
            // da real. O throw é o rollback; ele morre aqui.
            try {
                DB::transaction(function () use ($colony) {
                    $this->migrar($colony);

                    throw new SimulacaoConcluida;
                });
            } catch (SimulacaoConcluida) {
                // esperado: a transação foi desfeita
            }
        }

        $this->newLine();
        $this->line($aplicar
            ? 'Migrado. As colônias agora têm miolo erguido e slots atribuídos.'
            : 'Simulação — nada foi gravado. Rode com --aplicar para migrar.');

        return self::SUCCESS;
    }

    private function migrar(Colony $colony): void
    {
        $agora = now();

        // 1. O miolo, e o Depósito Local junto (D-105) — mesmo tratamento, slot fixo fora dele.
        $fixos = [...Slots::MIOLO, ...Slots::DEPOSITO_LOCAL];

        foreach ($fixos as $tipo => $slot) {
            $b = $colony->buildings()->where('type', $tipo)->first();

            if (! $b) {
                $this->line("   {$tipo}: criada no nível 1, slot {$slot}");
                $b = $colony->buildings()->create(['type' => $tipo, 'level' => 1, 'slot' => $slot]);
                $this->lancarSubsidio($colony, $tipo, $agora);

                continue;
            }

            $promover = $b->level === 0;

            if ($promover) {
                $this->line("   {$tipo}: nível 0 → 1 (miolo nasce erguido), slot {$slot}");
                $this->lancarSubsidio($colony, $tipo, $agora);
            } elseif ($b->slot !== $slot) {
                $this->line("   {$tipo}: nível {$b->level} preservado, vai para o slot {$slot}");
            }

            $b->update(['level' => max($b->level, 1), 'slot' => $slot]);
        }

        // 2. e 3. O resto.
        $naFila = BuildQueue::where('colony_id', $colony->id)->ativos()->pluck('building_id')->flip();
        $livres = Slots::livres();

        // Ordem estável: quem já tinha slot fica onde estava, e o resto entra por id. Sem isto,
        // rodar duas vezes poderia embaralhar o mapa da colônia de um jogador.
        $restantes = $colony->buildings()
            ->whereNotIn('type', [...Building::ESSENCIAIS, ...array_keys(Slots::DEPOSITO_LOCAL)])
            ->orderByRaw('slot IS NULL')->orderBy('slot')->orderBy('id')
            ->get();

        $ocupados = [];

        foreach ($restantes as $b) {
            if ($b->level === 0 && ! $naFila->has($b->id)) {
                $this->line("   {$b->type}: nível 0 e fora da fila → apagada (vira slot vazio)");
                $b->delete();

                continue;
            }

            // Quem já está num slot válido e livre, fica.
            if ($b->slot !== null && in_array($b->slot, $livres, true) && ! in_array($b->slot, $ocupados, true)) {
                $ocupados[] = $b->slot;

                continue;
            }

            $slot = collect($livres)->first(fn (int $s) => ! in_array($s, $ocupados, true));

            if ($slot === null) {
                // 16 slots livres para 12 construções de progressão: só aconteceria se a colônia
                // tivesse cópias demais. Falhar é melhor que empilhar dois prédios num buraco.
                throw new \RuntimeException("colônia {$colony->id}: acabaram os slots livres.");
            }

            $emObra = $b->level === 0 ? ' (em obra, preservada)' : '';
            $this->line("   {$b->type}: nível {$b->level} → slot {$slot}{$emObra}");
            $b->update(['slot' => $slot]);
            $ocupados[] = $slot;
        }
    }

    /**
     * O nível 1 do miolo (e do Depósito Local) é emissão do Governo, como na fundação.
     * `firstOrCreate` no ledger é o que torna o comando idempotente: rodar de novo não lança o
     * subsídio duas vezes.
     */
    private function lancarSubsidio(Colony $colony, string $tipo, $agora): void
    {
        $custo = json_decode(
            DB::table('building_specs')->where(['building_type' => $tipo, 'level' => 1])->value('cost_json'),
            true,
        ) ?? [];

        foreach ($custo as $recurso => $qtd) {
            Ledger::firstOrCreate(
                [
                    'colony_id' => $colony->id,
                    'type' => 'subsidio_governo',
                    'resource_type' => $recurso,
                    'ref' => "build:{$tipo}:n1",
                ],
                ['amount' => $qtd, 'created_at' => $agora],
            );
        }
    }
}

/** Aborta a transação da simulação sem virar erro de verdade. */
class SimulacaoConcluida extends \RuntimeException {}
