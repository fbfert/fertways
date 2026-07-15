<?php

namespace App\Console\Commands;

use App\Domain\Transport\Placas;
use App\Models\Vehicle;
use Illuminate\Console\Command;

/**
 * Backfill do Registro de Placas (D-60, §16.3) para os veículos que já existem.
 *
 * Os novos já nascem registrados — o Furgão do kit no `CreateColony`, o Caminhão do governo na
 * linha de montagem. Este comando é só para os que vieram de antes do Ministério existir. Passo à
 * parte do deploy, como o `fertways:slots` (D-59).
 *
 *     artisan fertways:placas            # simula
 *     artisan fertways:placas --aplicar  # emite de verdade
 *
 * As placas saem **na ordem de criação** dos veículos (por `id`), para que o registro do planeta
 * conte a história na ordem em que ela aconteceu. Idempotente: quem já tem placa não recebe outra.
 */
class PlacasDosVeiculos extends Command
{
    protected $signature = 'fertways:placas {--aplicar : emite de verdade; sem isto, só simula}';

    protected $description = 'Emite placa aos veículos que já existem (D-60, §16.3)';

    public function handle(Placas $placas): int
    {
        $aplicar = (bool) $this->option('aplicar');

        $semPlaca = Vehicle::whereNull('plate')->orderBy('id')->get();

        if ($semPlaca->isEmpty()) {
            $this->info('Todos os veículos já têm placa. Nada a fazer.');

            return self::SUCCESS;
        }

        foreach ($semPlaca as $veiculo) {
            $dono = $veiculo->colony_id ? "colônia {$veiculo->colony_id}" : 'governo';

            if ($aplicar) {
                $placa = $placas->registrar($veiculo);
                $this->line("   veículo {$veiculo->id} ({$veiculo->type}, {$dono}) → {$placa}");

                continue;
            }

            // Na simulação não se pode chamar o `emitir` em laço: sem gravar, ele devolveria a
            // mesma placa a todos, porque o maior sequencial não avança. Projeta-se o número.
            $this->line("   veículo {$veiculo->id} ({$veiculo->type}, {$dono}) → receberá placa");
        }

        $this->newLine();
        $this->line($aplicar
            ? $semPlaca->count().' placa(s) emitida(s).'
            : $semPlaca->count().' veículo(s) sem placa. Rode com --aplicar para emitir.');

        return self::SUCCESS;
    }
}
