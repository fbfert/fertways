<?php

namespace App\Console\Commands;

use App\Domain\Logistics\ConcluirTrechos;
use App\Domain\Production\ColonyTick;
use App\Models\Colony;
use App\Models\NeutralZone;
use Illuminate\Console\Command;
use Throwable;

/**
 * Motor de tick. Chamado pelo Laravel Scheduler, que o cron do sistema aciona a cada minuto:
 *
 *     * * * * * /bin/php84 /home/fertways/apps/fertways/backend/artisan schedule:run >/dev/null 2>&1
 *
 * Caminho absoluto do php84 de propósito: o alias `php` do Virtualmin só existe em shell
 * interativo, e o cron rodaria o PHP 8.2 do AppStream.
 *
 * Nada aqui depende de "tempo online": cada colônia avança pelo delta entre `last_tick_at`
 * e agora. Uma colônia parada por dois dias recupera exatamente o que produziria.
 */
class TickColonies extends Command
{
    protected $signature = 'fertways:tick {--colony= : processa só esta colônia}';

    protected $description = 'Avança produção, conclui upgrades e expira proteções, por delta de tempo';

    public function handle(ColonyTick $tick, ConcluirTrechos $trechos): int
    {
        $agora = now();
        $processadas = 0;
        $falhas = 0;

        Colony::when($this->option('colony'), fn ($q, $id) => $q->whereKey($id))
            ->where('last_tick_at', '<', $agora)
            ->orderBy('id')
            ->chunkById(200, function ($colonias) use ($tick, $agora, &$processadas, &$falhas) {
                foreach ($colonias as $colony) {
                    try {
                        $tick->handle($colony, $agora);
                        $processadas++;
                    } catch (Throwable $e) {
                        // Uma colônia com estado ruim não pode travar o servidor inteiro.
                        // A transação dela já sofreu rollback; as outras seguem.
                        $falhas++;
                        report($e);
                        $this->error("colônia {$colony->id}: {$e->getMessage()}");
                    }
                }
            });

        $zonas = $this->expirarProtecoes($agora);

        /*
         * Fora do laço por colônia: um veículo da colônia A entrega na B, e o relógio da viagem
         * não tem relação com o `last_tick_at` de nenhuma das duas. Rodar por colônia entregaria
         * a carga só quando a colônia de origem fosse processada.
         */
        $entregas = $trechos->handle();

        $this->info("tick: {$processadas} colônias, {$falhas} falhas, {$zonas} proteções expiradas, {$entregas} trechos concluídos");

        return $falhas > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * "Zona neutra elegível protegida por 8 dias completos. Ao término, tornam-se
     * vulneráveis na janela declarada pelo dono." (GDD, precedência da seção 0)
     *
     * O slot principal é inviolável sempre e não passa por aqui.
     */
    private function expirarProtecoes($agora): int
    {
        return NeutralZone::where('status', 'protegida')
            ->whereNotNull('protected_until')
            ->where('protected_until', '<=', $agora)
            ->update(['status' => 'vulneravel']);
    }
}
