<?php

namespace App\Console\Commands;

use App\Domain\Admin\PlanoFounders;
use App\Domain\Logistics\MapaFertways;
use App\Models\Colony;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Realoca as colônias existentes para slots de founder do disco central (D-51, Fatia 2).
 *
 * **Por que um comando à parte, e não uma migration.** Mover uma colônia é seguro só quando os seus
 * veículos estão **ociosos**: uma viagem em curso guarda a distância calculada no despacho, e mudar
 * a origem por baixo dela deixaria a viagem apontando para um número que já não existe. Uma migration
 * roda sozinha no deploy, sem conferir isso; este comando confere e **recusa** se achar veículo em
 * rota. A conferência é do momento — **reconfira** antes de rodar em produção.
 *
 * As colônias de produção são, de fato, as primeiras a chegar; por isso ganham os slots de founder,
 * as melhores posições, perto do Mercado (D-51). A designação é determinística: a mais antiga (menor
 * id) recebe o primeiro slot populável na ordem canônica de `MapaFertways::slotsFounder`.
 *
 *   artisan fertways:realocar-founders            # só simula: mostra o plano
 *   artisan fertways:realocar-founders --force     # aplica
 */
class RealocarFounders extends Command
{
    protected $signature = 'fertways:realocar-founders {--force : aplica; sem isto, só simula}';

    protected $description = 'Realoca as colônias para slots de founder do disco central (D-51)';

    public function handle(PlanoFounders $planos): int
    {
        if (Colony::count() === 0) {
            $this->info('Nenhuma colônia para realocar.');

            return self::SUCCESS;
        }

        /*
         * A regra vive em `Domain\Admin\PlanoFounders`, e não aqui, desde 2026-07-13: o painel passou
         * a **mostrar o plano antes de aplicar**, e duas cópias da mesma regra fariam a simulação da
         * tela mentir em relação ao que este comando faz.
         */

        // Guarda dos veículos ociosos. Um veículo em rota torna o remanejamento inseguro (§25.5).
        $bloqueios = $planos->bloqueios();

        if ($bloqueios->isNotEmpty()) {
            $this->error('ABORTADO: há veículo fora do pátio. Realocar agora quebraria a viagem.');
            $bloqueios->each(fn ($b) => $this->line(
                "  colônia {$b['colony_id']} ({$b['colonia']}): veículo {$b['veiculo']} "
                ."[{$b['placa']}] está '{$b['status']}'"
                .($b['chega_at'] ? ", chega {$b['chega_at']}" : ''),
            ));

            return self::FAILURE;
        }

        if (($fora = $planos->semSlot()) > 0) {
            $this->error("ABORTADO: {$fora} colônia(s) não cabem nos slots populáveis do disco.");

            return self::FAILURE;
        }

        $plano = $planos->plano()->all();

        if (empty($plano)) {
            $this->info('Todas as colônias já estão no seu slot de founder. Nada a fazer.');

            return self::SUCCESS;
        }

        $this->table(
            ['id', 'colônia', 'de', 'para'],
            array_map(fn ($p) => [
                $p['colony']->id, $p['colony']->name,
                "({$p['colony']->x}, {$p['colony']->y})", "({$p['x']}, {$p['y']})",
            ], $plano),
        );

        if (! $this->option('force')) {
            $this->warn('Simulação. Rode com --force para aplicar.');

            return self::SUCCESS;
        }

        // Células-tampão livres para a primeira passada. Estão na borda x=RAIO, coluna que nenhuma
        // colônia deslocada pode ocupar (o deslocamento −50 leva o x máximo a RAIO−1). Confere
        // mesmo assim: uma fundação nova na janela entre migrar e realocar poderia tê-las tomado.
        $tampoes = [];
        foreach ($plano as $i => $_) {
            $tampoes[] = ['x' => MapaFertways::RAIO, 'y' => MapaFertways::RAIO - $i];
        }
        $ocupadas = Colony::whereIn('x', array_column($tampoes, 'x'))
            ->get(['x', 'y'])->map(fn ($c) => "{$c->x}:{$c->y}")->flip();
        foreach ($tampoes as $t) {
            if ($ocupadas->has("{$t['x']}:{$t['y']}")) {
                $this->error("ABORTADO: a célula-tampão ({$t['x']}, {$t['y']}) está ocupada. Rode de novo.");

                return self::FAILURE;
            }
        }

        // Duas passadas numa transação: primeiro todos para os tampões, depois para os slots. Assim
        // o disco central esvazia antes de ser repovoado e o `unique(x,y)` nunca colide no meio.
        DB::transaction(function () use ($plano, $tampoes) {
            foreach ($plano as $i => $p) {
                $p['colony']->forceFill(['x' => $tampoes[$i]['x'], 'y' => $tampoes[$i]['y']])->save();
            }
            foreach ($plano as $p) {
                $p['colony']->forceFill(['x' => $p['x'], 'y' => $p['y']])->save();
            }
        });

        $this->info(count($plano).' colônia(s) realocada(s) para slots de founder.');

        return self::SUCCESS;
    }
}
