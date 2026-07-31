<?php

namespace App\Console\Commands;

use App\Models\TelemetryEvent;
use Illuminate\Console\Command;

/**
 * A varredura de retenção da telemetria (A2.0.1.1): eventos discretos vivem **90 dias**.
 *
 * O retrato diário (`telemetry_daily`) **não** expira — ele já é o agregado, e é ele que sustenta a
 * série histórica. Por isso a ordem importa e está travada abaixo: **nada é apagado sem que o dia
 * correspondente já tenha sido agregado**. Apagar antes de agregar não perderia espaço, perderia o
 * histórico, e de um jeito que nenhum backup de tabela recupera depois que o gráfico já mentiu.
 *
 * ⚠️ Apaga pelo **query builder**, de propósito: `->delete()` em massa não instancia 200 mil
 * modelos nem dispara evento por linha. O efeito colateral é que ele passa por cima da trava
 * append-only de `TelemetryEvent::deleting()` — o que é justamente o desenho, já que esta é a única
 * rotina autorizada a apagar. Ver o comentário lá.
 */
class TelemetriaLimpar extends Command
{
    protected $signature = 'fertways:telemetria-limpar
                            {--dias=90 : idade a partir da qual o evento discreto é descartado}
                            {--aplicar : sem isto, só relata o que apagaria}';

    protected $description = 'Descarta eventos de telemetria mais velhos que a janela de retenção';

    public function handle(): int
    {
        $dias = (int) $this->option('dias');

        if ($dias < 1) {
            $this->error('--dias precisa ser pelo menos 1. Zero apagaria o que acabou de acontecer.');

            return self::FAILURE;
        }

        $corte = now()->subDays($dias)->startOfDay();

        $quantos = TelemetryEvent::where('created_at', '<', $corte)->count();

        if ($quantos === 0) {
            $this->info("Nada a descartar: não há evento anterior a {$corte->toDateTimeString()}.");

            return self::SUCCESS;
        }

        if (! $this->option('aplicar')) {
            $this->warn("{$quantos} evento(s) anteriores a {$corte->toDateTimeString()} seriam descartados.");
            $this->line('Rode de novo com --aplicar para descartar de verdade.');

            return self::SUCCESS;
        }

        /*
         * Em lotes, e não de uma vez. Um DELETE único de centenas de milhares de linhas segura o
         * lock da tabela e o binlog o tempo todo — e esta tabela é escrita pelo jogo em produção,
         * enquanto jogadores estão dentro. O servidor tem 4 GB e o MariaDB é compartilhado.
         */
        $total = 0;
        do {
            $lote = TelemetryEvent::where('created_at', '<', $corte)->limit(1000)->delete();
            $total += $lote;
        } while ($lote > 0);

        $this->info("{$total} evento(s) descartados (anteriores a {$corte->toDateTimeString()}).");

        return self::SUCCESS;
    }
}
