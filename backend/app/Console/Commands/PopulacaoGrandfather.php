<?php

namespace App\Console\Commands;

use App\Domain\Populacao\Populacao;
use App\Models\Colony;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Grandfathering da população (A2.2.6 / GDD ALPHA 2 §6.7).
 *
 * ## A regra, que é uma promessa
 *
 * *"Nenhuma colônia existente pode parar de produzir por uma regra que não existia quando ela foi
 * construída."*
 *
 * Cada colônia recebe **população suficiente para operar tudo o que já construiu**, mais a folga do
 * §6.7 (20%). A exigência morde a partir dali: construção nova, upgrade novo e zona nova precisam de
 * população real.
 *
 * ## ⚠️ Por que ele precisa rodar ANTES de ligar a chave
 *
 * As colônias existentes têm `populacao = 0`. E `Ciclo::avancar()` devolve cedo quando o total é
 * zero — população zero **não cresce sozinha**, por construção: não há de quem nascer ninguém.
 *
 * Ligar `population_settings.ativo` sem rodar isto deixaria 29 colônias em déficit permanente, sem
 * caminho de saída. Não é hipótese: é o que aconteceria.
 *
 * ## O teto habitacional é respeitado
 *
 * A concessão é limitada pela capacidade da Estrutura de Sobrevivência. O `BALANCEAMENTO.md` §7.1
 * avisa que uma capacidade baixa demais faria veteranas nascerem acima do próprio teto — conferido
 * antes de rodar: em produção, **nenhuma das 29 fica acima**. O `min()` está aqui de qualquer forma,
 * porque o aviso continua valendo se alguém baixar o parâmetro depois.
 *
 *     php84 artisan fertways:populacao-grandfather --aplicar
 */
class PopulacaoGrandfather extends Command
{
    protected $signature = 'fertways:populacao-grandfather
                            {--aplicar : sem isto, só relata o que faria}';

    protected $description = 'Concede população às colônias existentes, para operarem o que já construíram';

    public function handle(Populacao $populacao): int
    {
        $colonias = Colony::with('buildings')->get();

        if ($colonias->isEmpty()) {
            $this->info('Nenhuma colônia. Nada a fazer.');

            return self::SUCCESS;
        }

        $plano = [];
        $acimaDoTeto = 0;

        foreach ($colonias as $c) {
            /*
             * Só concede a quem está em zero. Uma colônia que já tem população foi grandfatherizada
             * antes, ou cresceu de verdade — em nenhum dos dois casos se deve reescrever o número.
             * É o que torna o comando repetível sem estrago.
             */
            if ((int) $c->populacao > 0) {
                continue;
            }

            $precisa = $populacao->necessariaParaOQueJaTem($c);
            $cabe = $populacao->capacidade($c);

            if ($precisa > $cabe) {
                $acimaDoTeto++;
            }

            $conceder = min($precisa, $cabe);

            /*
             * Piso de 1. Uma colônia que não exige operador nenhum ainda assim precisa de gente:
             * população zero nunca cresce (o `Ciclo` devolve cedo), e ela ficaria congelada para
             * sempre — punida por não ter construído nada, que é o oposto do que a regra quer.
             */
            $plano[$c->id] = max(1, $conceder);
        }

        if ($plano === []) {
            $this->info('Todas as colônias já têm população. Nada a fazer.');

            return self::SUCCESS;
        }

        $total = array_sum($plano);

        if (! $this->option('aplicar')) {
            $this->warn(count($plano).' colônia(s) receberiam '.$total.' colono(s) no total.');
            $this->line('Menor: '.min($plano).' · maior: '.max($plano));

            if ($acimaDoTeto > 0) {
                $this->line("⚠️ {$acimaDoTeto} colônia(s) precisam de MAIS do que cabe no próprio teto —");
                $this->line('   receberão o teto, e ficarão em déficit até subirem a habitação.');
            }

            $this->line('Rode de novo com --aplicar.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($plano) {
            foreach ($plano as $colonyId => $quantos) {
                Colony::whereKey($colonyId)->update(['populacao' => $quantos]);
            }
        });

        $this->info(count($plano).' colônia(s) povoadas, '.$total.' colono(s) no total.');
        $this->line('Nenhum recurso foi gasto e nada entrou no ledger: população não é bem econômico.');

        return self::SUCCESS;
    }
}
