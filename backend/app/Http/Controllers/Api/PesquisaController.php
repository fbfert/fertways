<?php

namespace App\Http\Controllers\Api;

use App\Domain\Pesquisa\EfeitosDaPesquisa;
use App\Domain\Pesquisa\Pesquisar;
use App\Domain\Pesquisa\Vagas;
use App\Http\Controllers\Controller;
use App\Models\Technology;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * A árvore de pesquisa, na mão do colono (A2.3).
 *
 * ## ⚠️ Por que esta rota não existia
 *
 * A A2.3 entregou o **modelo** — catálogo, trilhas, custos, vagas, vocabulário de efeitos — e parou
 * aí. Não havia rota, não havia tela, e `EfeitosDaPesquisa` não era consumido por ninguém. A
 * chave-mestra `research_settings.ativo` era lida num único lugar: um serviço que ninguém conseguia
 * chamar.
 *
 * Ligá-la naquele estado não teria feito **nada** — o mesmo defeito que a população teve no D-178,
 * onde a chave também não estava ligada em coisa alguma.
 */
class PesquisaController extends Controller
{
    public function index(Request $request, Vagas $vagas, EfeitosDaPesquisa $efeitos): JsonResponse
    {
        $colony = $request->user()->colony()->firstOrFail();
        $colony->load('buildings');

        $ativo = (bool) DB::table('research_settings')->find(1)?->ativo;

        $minhas = DB::table('colony_technologies')
            ->where('colony_id', $colony->id)
            ->get()
            ->keyBy('technology_id');

        $nivelLab = (int) ($colony->buildings->firstWhere('type', 'laboratorio')?->level ?? 0);

        return response()->json([
            'ativo' => $ativo,
            'laboratorio' => $nivelLab,
            'vagas' => [
                'total' => $vagas->total($colony),
                'ocupadas' => $vagas->ocupadas($colony),
                'livres' => $vagas->livres($colony),
                'fontes' => $vagas->fontes($colony),
            ],
            /*
             * Os efeitos ATIVOS, e não só a lista do que dá para pesquisar. Sem isto o jogador
             * concluiria uma tecnologia e não teria como saber se ela está valendo — que é a mesma
             * armadilha da penalidade invisível da A2.6.
             */
            'meus_efeitos' => [
                'desconto_tributo_pct' => $efeitos->descontoDeTributo($colony) / 100,
                'desconto_duracao_pct' => $efeitos->descontoDeDuracao($colony) / 100,
                'producao_por_alvo' => $efeitos->bonusDeProducaoPorAlvo($colony),
            ],
            'tecnologias' => Technology::where('ativa', true)
                ->orderBy('trilha')->orderBy('id')
                ->get()
                ->map(function (Technology $t) use ($minhas, $nivelLab) {
                    $meu = $minhas->get($t->id);
                    $nivel = (int) ($meu->nivel ?? 0);

                    return [
                        'id' => $t->id,
                        'chave' => $t->chave,
                        'nome' => $t->nome,
                        'descricao' => $t->descricao,
                        'trilha' => $t->trilha,
                        'nivel' => $nivel,
                        'nivel_maximo' => (int) $t->nivel_maximo,
                        'laboratorio_minimo' => (int) $t->laboratorio_minimo,
                        // O modelo já faz o cast para array — decodificar de novo estouraria.
                        'custo' => $t->custo_json ?? [],
                        'duracao_segundos' => (int) $t->duracao_segundos,
                        'efeitos' => $t->efeitos_json ?? [],
                        'status' => $meu->status ?? 'nao_iniciada',
                        'termina_em' => isset($meu->finishes_at) && $meu->status === 'pesquisando'
                            ? Carbon::parse($meu->finishes_at)->toIso8601String()
                            : null,
                        // O porquê de não poder, para a tela não oferecer o que a regra recusaria.
                        'bloqueio' => match (true) {
                            ! $t->ativa => 'inativa',
                            $nivelLab < (int) $t->laboratorio_minimo => 'laboratorio',
                            $nivel >= (int) $t->nivel_maximo => 'no_maximo',
                            ($meu->status ?? null) === 'pesquisando' => 'em_andamento',
                            default => null,
                        },
                    ];
                }),
        ]);
    }

    /** POST /pesquisa/{technology} — inicia. A regra inteira mora no domínio, não aqui. */
    public function pesquisar(Request $request, Technology $technology, Pesquisar $pesquisar): JsonResponse
    {
        $colony = $request->user()->colony()->firstOrFail();

        $pesquisar->handle($colony, $technology);

        return response()->json(['iniciada' => true]);
    }
}
