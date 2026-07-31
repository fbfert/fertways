<?php

namespace App\Console\Commands;

use App\Domain\Telemetria\DirecaoDoLedger;
use App\Models\Ledger;
use App\Models\TelemetryDaily;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * O retrato diário de fluxo (A2.0.1.1): produção, consumo e saldo por colônia e recurso.
 *
 * **Deriva do ledger, não instrumenta nada.** O ledger já é append-only e já registra todo fato
 * econômico; este comando só o lê e agrega. É por isso que produção e consumo não viram evento
 * discreto: o tick roda a cada minuto e a tabela de eventos ficaria ingovernável sem responder
 * nada que este retrato não responda melhor.
 *
 * **Idempotente por construção.** A chave única (colônia, dia, recurso) mais o `upsert` fazem com
 * que rodar o mesmo dia duas vezes chegue ao mesmo número. Sem isso, uma execução repetida por
 * engano dobraria a produção de um dia inteiro — e o erro só apareceria num gráfico, semanas
 * depois, quando ninguém mais lembrasse da execução repetida.
 *
 *     php84 artisan fertways:telemetria-diaria              # ontem
 *     php84 artisan fertways:telemetria-diaria --dia=2026-07-30
 *     php84 artisan fertways:telemetria-diaria --desde=2026-07-01   # recompõe um intervalo
 *
 * ⚠️ O padrão é **ontem**, e não hoje: um dia que ainda está acontecendo produz um retrato que
 * muda a cada execução, o que é exatamente o que uma série histórica não pode ter.
 */
class TelemetriaDiaria extends Command
{
    protected $signature = 'fertways:telemetria-diaria
                            {--dia= : o dia a agregar (AAAA-MM-DD). Padrão: ontem}
                            {--desde= : agrega de --desde até --dia, um dia por vez}';

    protected $description = 'Agrega o ledger no retrato diário de produção, consumo e saldo';

    public function handle(DirecaoDoLedger $direcao): int
    {
        $pendentes = $direcao->naoClassificados();

        if ($pendentes !== []) {
            /*
             * Recusa-se a rodar. Um tipo novo do ledger que ninguém classificou entraria mudo — e
             * o retrato ficaria errado sem nenhum sinal de que ficou.
             */
            $this->error('Há tipo de ledger sem direção declarada: '.implode(', ', $pendentes));
            $this->line('Classifique em App\Domain\Telemetria\DirecaoDoLedger antes de agregar.');

            return self::FAILURE;
        }

        $fim = $this->option('dia')
            ? Carbon::parse($this->option('dia'))->startOfDay()
            : now()->subDay()->startOfDay();

        $inicio = $this->option('desde')
            ? Carbon::parse($this->option('desde'))->startOfDay()
            : $fim->copy();

        if ($inicio->greaterThan($fim)) {
            $this->error('--desde é posterior a --dia.');

            return self::FAILURE;
        }

        $dias = 0;
        $linhas = 0;

        for ($dia = $inicio->copy(); $dia->lessThanOrEqualTo($fim); $dia->addDay()) {
            $linhas += $this->agregarDia($dia, $direcao);
            $dias++;
        }

        $this->info("{$dias} dia(s) agregados, {$linhas} linha(s) de retrato escritas.");

        return self::SUCCESS;
    }

    private function agregarDia(Carbon $dia, DirecaoDoLedger $direcao): int
    {
        $lancamentos = Ledger::query()
            ->whereBetween('created_at', [$dia->copy()->startOfDay(), $dia->copy()->endOfDay()])
            ->select('colony_id', 'type', 'resource_type', DB::raw('SUM(amount) AS total'))
            ->groupBy('colony_id', 'type', 'resource_type')
            ->get();

        /*
         * A chave é colônia + recurso. `resource_type` nulo é Fert$, e o `?? ''` existe só porque
         * um índice de array não aceita null — na escrita ele volta a ser null, que é a convenção
         * que o ledger usa e que esta tabela repete de propósito.
         */
        $acumulado = [];
        $ignorados = 0;

        foreach ($lancamentos as $l) {
            $direcaoDoTipo = $direcao->classificar($l->type);

            if ($direcaoDoTipo === 'indefinido') {
                $ignorados++;

                continue;
            }

            $chave = $l->colony_id.'|'.($l->resource_type ?? '');
            $acumulado[$chave] ??= [
                'colony_id' => $l->colony_id,
                'resource_type' => $l->resource_type,
                'produzido' => 0,
                'consumido' => 0,
            ];

            $campo = $direcaoDoTipo === 'entrada' ? 'produzido' : 'consumido';
            $acumulado[$chave][$campo] += (int) $l->total;
        }

        if ($ignorados > 0) {
            /*
             * Relatado, e não engolido: os tipos indefinidos são mudança de lugar, e ficam fora da
             * conta até haver arbitragem. Se este número for grande, a arbitragem virou urgente.
             */
            $this->line("  {$dia->toDateString()}: {$ignorados} agrupamento(s) de tipo indefinido ficaram de fora.");
        }

        if ($acumulado === []) {
            return 0;
        }

        $registros = array_map(fn (array $a) => [
            'colony_id' => $a['colony_id'],
            'dia' => $dia->toDateString(),
            'resource_type' => $a['resource_type'],
            'produzido' => $a['produzido'],
            'consumido' => $a['consumido'],
            /*
             * `saldo_fim` fica em zero aqui, e isso é honesto e não um esquecimento: o ledger diz o
             * FLUXO do dia, não o estoque ao fim dele. O saldo real vem das linhas de recurso da
             * colônia, que só valem para HOJE — reconstruir o saldo de um dia passado exigiria
             * varrer o ledger desde a fundação. Fica para quando o painel (A2.0.2) precisar dele,
             * e aí será outra decisão.
             */
            'saldo_fim' => 0,
        ], $acumulado);

        TelemetryDaily::upsert(
            $registros,
            ['colony_id', 'dia', 'resource_type'],
            ['produzido', 'consumido']
        );

        return count($registros);
    }
}
