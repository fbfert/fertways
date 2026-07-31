<?php

namespace App\Console\Commands;

use App\Models\Colony;
use App\Models\MissionAssignment;
use App\Models\MissionTemplate;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Grandfathering do onboarding (A2.1): as colônias que já existem ficam de fora.
 *
 * ## Por que este comando precisa existir
 *
 * A tutoria virou sequência encadeada e obrigatória. Sem esta varredura, **todo veterano viraria
 * elegível a um pacote de iniciante**: o motor entregaria a `tut_primeira_obra` a quem já ergueu
 * cinquenta níveis, ela concluiria no primeiro tick, e a recompensa cairia no ledger — corretamente
 * registrada, aliás, o que é o pior do problema. Seria emissão de Fert$ sem contrapartida
 * nenhuma, multiplicada pelo número de colônias, e o ledger é append-only: não se desfaz.
 *
 * ## E por que ele NÃO paga recompensa
 *
 * É a regra do GDD ALPHA 2 §4.3, e a razão é essa: a recompensa existe para ensinar um gesto novo.
 * Quem já sabe o gesto não está aprendendo nada — pagar seria só emitir dinheiro.
 *
 * As linhas entram direto como `concluida`, **sem passar pelo `Progresso`**, que é justamente quem
 * paga. É a diferença entre "marcar como visto" e "cumprir".
 *
 * ## O corte é por data de fundação
 *
 *     php84 artisan fertways:onboarding-grandfather --aplicar
 *
 * O padrão é **agora**: toda colônia que já existe no instante da varredura entra. Quem fundar
 * depois faz o onboarding de verdade e recebe as recompensas de verdade. `--antes-de` existe para
 * repetir a varredura com o mesmo corte, caso ela precise ser rodada de novo — o comando é
 * idempotente, mas o corte não pode andar sozinho entre execuções.
 */
class OnboardingGrandfather extends Command
{
    protected $signature = 'fertways:onboarding-grandfather
                            {--antes-de= : só colônias fundadas antes desta data/hora. Padrão: agora}
                            {--aplicar : sem isto, só relata o que faria}';

    protected $description = 'Marca o onboarding como concluído nas colônias que já existiam, sem pagar recompensa';

    public function handle(): int
    {
        $corte = $this->option('antes-de')
            ? Carbon::parse($this->option('antes-de'))
            : now();

        $templates = MissionTemplate::where('categoria', 'tutoria')->orderBy('id')->get();

        if ($templates->isEmpty()) {
            $this->error('Não há templates de tutoria. Rode o MissionTemplateSeeder antes.');

            return self::FAILURE;
        }

        /*
         * `created_at` e não `founded_at`: nem toda colônia tem `founded_at` preenchido, e o que
         * interessa aqui é "esta linha já existia quando a regra mudou" — que é exatamente o que
         * `created_at` diz, sempre.
         */
        /*
         * `<=` e não `<`. O `created_at` tem precisão de SEGUNDO: com o corte em `now()`, uma
         * colônia criada no mesmo segundo ficaria de fora por uma fração que o banco nem guarda.
         * Em produção isso quase nunca apareceria — as colônias são velhas —, e foi um teste que
         * pegou. E a semântica de `<=` é a certa de qualquer forma: o corte quer dizer "tudo o que
         * já existe neste instante", e o que nasceu neste instante já existe.
         */
        $colonias = Colony::where('created_at', '<=', $corte)->pluck('id');

        if ($colonias->isEmpty()) {
            $this->info('Nenhuma colônia anterior ao corte. Nada a fazer.');

            return self::SUCCESS;
        }

        // Quem já tem alguma linha da tutoria não deve ganhar uma segunda para o mesmo template.
        $jaTem = MissionAssignment::whereIn('colony_id', $colonias)
            ->where('categoria', 'tutoria')
            // `id` na projeção, e não é detalhe: sem ele `getKey()` devolve NULL e o
            // `whereIn('id', …)` da promoção não atinge linha nenhuma — falha silenciosa.
            ->get(['id', 'colony_id', 'template_id', 'status'])
            ->groupBy('colony_id');

        $agora = now();
        $novas = [];
        $promover = [];

        foreach ($colonias as $colonyId) {
            $existentes = ($jaTem[$colonyId] ?? collect())->keyBy('template_id');

            foreach ($templates as $t) {
                $atual = $existentes->get($t->id);

                if ($atual === null) {
                    $novas[] = [
                        'colony_id' => $colonyId,
                        'template_id' => $t->id,
                        'categoria' => 'tutoria',
                        'acao' => $t->acao,
                        'progresso' => $t->meta,
                        'meta' => $t->meta,
                        'status' => 'concluida',
                        'expires_at' => null,
                        'concluded_at' => $agora,
                        'created_at' => $agora,
                    ];

                    continue;
                }

                /*
                 * Linha antiga ainda em aberto — da tutoria plana, de antes da A2.1. Vira concluída
                 * também, e pelo mesmo motivo: quem está no jogo há semanas não vai "aprender" a
                 * despachar um veículo agora.
                 */
                if ($atual->status !== 'concluida') {
                    $promover[] = $atual->getKey();
                }
            }
        }

        $total = count($novas) + count($promover);

        if ($total === 0) {
            $this->info('Todas as colônias anteriores ao corte já estão em dia. Nada a fazer.');

            return self::SUCCESS;
        }

        if (! $this->option('aplicar')) {
            $this->warn("Faria: {$total} etapa(s) em {$colonias->count()} colônia(s) — ".
                count($novas).' nova(s) e '.count($promover).' promovida(s).');
            $this->line("Corte: colônias criadas antes de {$corte->toDateTimeString()}.");
            $this->line('⚠️ NENHUMA recompensa é paga — é o ponto do comando (GDD ALPHA 2 §4.3).');
            $this->line('Rode de novo com --aplicar.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($novas, $promover, $agora) {
            foreach (array_chunk($novas, 500) as $lote) {
                MissionAssignment::insert($lote);
            }

            foreach (array_chunk($promover, 500) as $lote) {
                MissionAssignment::whereIn('id', $lote)
                    ->update(['status' => 'concluida', 'progresso' => DB::raw('meta'),
                        'concluded_at' => $agora, 'expires_at' => null]);
            }
        });

        $this->info("{$total} etapa(s) marcadas como concluídas em {$colonias->count()} colônia(s).");
        $this->line('Nenhuma recompensa foi paga, e nenhum lançamento entrou no ledger.');

        return self::SUCCESS;
    }
}
