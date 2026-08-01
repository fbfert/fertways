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
 * ## ⚠️ O teto habitacional NÃO limita a concessão, e isso é decisão de desenho
 *
 * O `BALANCEAMENTO.md` §7.1 avisa que uma capacidade baixa faria veteranas nascerem acima do próprio
 * teto. **Em produção, 21 das 29 nascem** — não porque a capacidade esteja errada, mas porque 20
 * delas têm Estrutura de Sobrevivência **nível 1**: até hoje não havia razão nenhuma para subi-la,
 * já que população não existia. O nível 1 não foi escolha delas.
 *
 * Limitar a concessão ao teto poria 21 colônias em déficit por construções erguidas antes de a regra
 * existir — **o que o §6.7 proíbe em uma frase**. Então a concessão cobre o que a colônia precisa,
 * mesmo acima do teto, e o teto passa a travar só o que ele deve travar: **o crescimento**.
 *
 * `Ciclo::avancar()` já sustenta isso sem remendo (`$total < $capacidade` governa apenas o
 * crescimento): acima do teto ninguém morre, ninguém é expulso, e ninguém cresce. A colônia opera
 * tudo o que construiu e ganha um motivo concreto para subir a habitação — que é o comportamento
 * que se queria dela desde o começo.
 *
 * A folga do §6.7 continua limitada pelo teto: quem já está acima não recebe folga nenhuma.
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

            /*
             * Dois números, e a diferença entre eles importa: `$bruto` é o que a colônia precisa
             * para OPERAR, e `$precisa` é isso mais a folga do §6.7, que já vem embutida.
             */
            $bruto = $populacao->alocadaEmConstrucoes($c) + $populacao->alocadaEmZonas($c);
            $precisa = $populacao->necessariaParaOQueJaTem($c);
            $cabe = $populacao->capacidade($c);

            if ($precisa > $cabe) {
                $acimaDoTeto++;
            }

            /*
             * Nunca menos do que a colônia precisa — nem que isso passe do teto. Ver o docblock: o
             * `min()` que estava aqui violava o §6.7 para 21 das 29 colônias de produção.
             *
             * A folga, essa sim, respeita o teto: ela é conforto, e conforto não justifica empurrar
             * ninguém mais para cima do limite.
             */
            $conceder = max($bruto, min($precisa, $cabe));

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
                $this->line("⚠️ {$acimaDoTeto} colônia(s) precisam de MAIS do que cabe no próprio teto.");
                $this->line('   Recebem o que precisam mesmo assim (§6.7): operam tudo o que ergueram,');
                $this->line('   e ficam sem CRESCER até subirem a Estrutura de Sobrevivência.');
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
