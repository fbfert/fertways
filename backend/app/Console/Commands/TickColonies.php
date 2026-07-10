<?php

namespace App\Console\Commands;

use App\Domain\Logistics\ConcluirTrechos;
use App\Domain\Logistics\ExtrairZonasNeutras;
use App\Domain\Ministry\ExpirarPrazos;
use App\Domain\Ministry\PagarConciliadores;
use App\Domain\Production\ColonyTick;
use App\Domain\Trade\ExpirarAcordos;
use App\Models\Colony;
use App\Models\NeutralZone;
use Illuminate\Console\Command;
use Throwable;

/**
 * Motor de tick. Chamado pelo Laravel Scheduler, que o cron do sistema aciona a cada minuto:
 *
 *     * * * * * /usr/bin/php84 /home/fertways/deploy/fertways/backend/artisan schedule:run >/dev/null 2>&1
 *
 * É o `artisan` da cópia de deploy, não o da árvore de trabalho (D-39).
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

    public function handle(
        ColonyTick $tick,
        ConcluirTrechos $trechos,
        ExpirarAcordos $acordos,
        ExpirarPrazos $ministerio,
        PagarConciliadores $folha,
        ExtrairZonasNeutras $zonasNeutras,
    ): int {
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

        // Extração das zonas neutras ocupadas — fora do laço por colônia, como as entregas: a zona
        // rende por delta próprio, sem relação com o `last_tick_at` de ninguém (§07, D-52).
        $extraidas = $zonasNeutras->handle($agora);

        /*
         * Fora do laço por colônia: um veículo da colônia A entrega na B, e o relógio da viagem
         * não tem relação com o `last_tick_at` de nenhuma das duas. Rodar por colônia entregaria
         * a carga só quando a colônia de origem fosse processada.
         */
        $entregas = $trechos->handle();

        /*
         * Depois das entregas, nunca antes: a carga que chega no último segundo do prazo ainda
         * cumpre o acordo (§26.5, D-41). Expirar primeiro puniria quem entregou a tempo.
         */
        $vencidos = $acordos->handle();

        /*
         * Depois dos acordos, nunca antes: um acordo que vence **neste** tick já pode ser a
         * evidência de uma denúncia (§26.8), e um caso cujo prazo de análise venceu no mesmo minuto
         * deve ser reatribuído com o mundo já atualizado.
         */
        ['reatribuidos' => $reatribuidos, 'encerrados' => $encerrados] = $ministerio->handle();
        $salarios = $folha->handle();

        $this->info("tick: {$processadas} colônias, {$falhas} falhas, {$zonas} proteções expiradas, {$extraidas} zonas extraídas, {$entregas} trechos concluídos, {$vencidos} acordos vencidos, {$reatribuidos} casos reatribuídos, {$encerrados} casos encerrados, {$salarios} salários pagos");

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
