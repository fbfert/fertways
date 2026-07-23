<?php

namespace App\Http\Controllers\Admin;

use App\Console\Commands\TickColonies;
use App\Domain\Admin\AlternarCelulaDeFundacao;
use App\Domain\Admin\AlternarZonaNeutra;
use App\Domain\Admin\Auditoria;
use App\Domain\Admin\Contas;
use App\Domain\Admin\CorrigirEstado;
use App\Domain\Logistics\ZonasNeutras;
use App\Domain\Admin\RealocarColonia;
use App\Domain\Admin\Suspender;
use App\Domain\Federacao\DissolverFederacao;
use App\Domain\Finance\DeclararIntervencao;
use App\Domain\Ministry\Apelacao;
use App\Domain\Ministry\DecidirCaso;
use App\Domain\Ministry\GerirConciliador;
use App\Domain\News\PublicarNoticia;
use App\Domain\Treasury\Tesouro;
use App\Exceptions\DomainRuleException;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Domain\Media\Biblioteca;
use App\Domain\Media\Vinculaveis;
use App\Models\Colony;
use App\Models\ImageBinding;
use App\Models\MediaAsset;
use App\Models\Federation;
use App\Models\News;
use App\Models\Report;
use App\Models\TransportSetting;
use App\Models\WarSetting;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * As ações de operador do painel. Cada uma chama a MESMA classe de domínio que o comando artisan
 * correspondente usa (não "shell out"), traduz erros de regra numa mensagem e volta ao dashboard.
 *
 * O que não vive no domínio (tick e realocação de founders — orquestração com guardas) é invocado
 * em processo por `Artisan::call`, que é o mesmo container, não um processo de shell.
 */
class AcoesController extends Controller
{
    // ── Ministério ───────────────────────────────────────────────────────────

    public function julgar(Request $request, Report $report, DecidirCaso $decidir): RedirectResponse
    {
        $procedente = $request->validate(['procedente' => ['required', 'boolean']])['procedente'];

        return $this->tentar('ministerio.julgar', function () use ($decidir, $report, $procedente) {
            $decidir->pelaEquipe($report, (bool) $procedente);

            return 'Caso #'.$report->id.' julgado '.($procedente ? 'procedente' : 'improcedente').'.';
        }, "report:{$report->id}");
    }

    public function apelacao(Request $request, Report $report, Apelacao $apelacao): RedirectResponse
    {
        $decisao = $request->validate(['decisao' => ['required', 'in:manter,reverter']])['decisao'];

        return $this->tentar('ministerio.apelacao', function () use ($apelacao, $report, $decisao) {
            $decisao === 'reverter' ? $apelacao->reverter($report) : $apelacao->manter($report);

            return 'Apelação do caso #'.$report->id.': '.($decisao === 'reverter' ? 'revertida' : 'mantida').'.';
        }, "report:{$report->id}");
    }

    // ── Conciliadores ────────────────────────────────────────────────────────

    public function conciliadorNomear(Request $request, GerirConciliador $gerir): RedirectResponse
    {
        $nick = $request->validate(['nickname' => ['required', 'string']])['nickname'];
        $colono = User::where('nickname', $nick)->first();

        if (! $colono) {
            return $this->erro("Colono não encontrado: {$nick}");
        }

        return $this->tentar('conciliador.nomear', fn () => $gerir->nomear($colono)
            ? "{$colono->nickname} é conciliador."
            : "{$colono->nickname} já era conciliador.", "user:{$colono->id}");
    }

    public function conciliadorGerir(Request $request, User $user, GerirConciliador $gerir): RedirectResponse
    {
        $acao = $request->validate(['acao' => ['required', 'in:demitir,reintegrar,suspender']])['acao'];

        return $this->tentar("conciliador.{$acao}", function () use ($gerir, $user, $acao) {
            match ($acao) {
                'demitir' => $gerir->demitir($user),
                'reintegrar' => $gerir->reintegrar($user),
                'suspender' => $gerir->suspender($user),
            };

            return "{$user->nickname}: {$acao}.";
        }, "user:{$user->id}");
    }

    // ── Finanças ─────────────────────────────────────────────────────────────

    public function intervencao(Request $request, DeclararIntervencao $declarar): RedirectResponse
    {
        $dados = $request->validate([
            'resource_type' => ['required', 'string'],
            'teto' => ['nullable', 'numeric', 'min:0'],
            'piso' => ['nullable', 'numeric', 'min:0'],
            'motivo' => ['required', 'string', 'max:255'],
            'dias' => ['required', 'integer', 'min:1'],
        ]);

        return $this->tentar('financas.intervencao', function () use ($declarar, $dados) {
            $i = $declarar->declarar(
                $dados['resource_type'],
                $this->emMicro($dados['teto'] ?? null),
                $this->emMicro($dados['piso'] ?? null),
                $dados['motivo'],
                (int) $dados['dias'],
            );

            return "Intervenção #{$i->id} em {$i->resource_type} declarada.";
        }, "resource:{$dados['resource_type']}");
    }

    public function intervencaoRevogar(Request $request, DeclararIntervencao $declarar): RedirectResponse
    {
        $recurso = $request->validate(['resource_type' => ['required', 'string']])['resource_type'];
        $n = $declarar->revogar($recurso);

        return $this->ok(
            'financas.intervencao_revogar',
            $n > 0 ? "Revogadas {$n} intervenção(ões) de {$recurso}." : "Nenhuma vigente em {$recurso}.",
            "resource:{$recurso}",
        );
    }

    // ── Mercado do Governo (D-87) ────────────────────────────────────────────

    /**
     * Salva a lista inteira de uma vez — o formulário manda os 26 recursos, um por linha, e o
     * salvar reconcilia cada um com o que já está na vitrine (`OfertarComoGoverno::definir`).
     */
    public function mercadoGoverno(Request $request, \App\Domain\Market\OfertarComoGoverno $ofertar): RedirectResponse
    {
        $dados = $request->validate([
            'qtd' => ['required', 'array'],
            'qtd.*' => ['nullable', 'integer', 'min:0'],
            'preco' => ['required', 'array'],
            'preco.*' => ['nullable', 'numeric', 'min:0'],
        ]);

        return $this->tentar('mercado.governo', function () use ($ofertar, $dados) {
            $alterados = 0;
            $erros = [];

            foreach ($dados['qtd'] as $recurso => $qtd) {
                $qtd = (int) ($qtd ?? 0);
                $precoMicro = (int) round(((float) ($dados['preco'][$recurso] ?? 0)) * Colony::MICRO_POR_FERT);

                try {
                    $ofertar->definir($recurso, $qtd, $precoMicro);
                    $alterados++;
                } catch (DomainRuleException $e) {
                    $erros[] = $e->getMessage();
                }
            }

            if ($erros !== []) {
                // O que não deu erro já foi salvo — cada recurso é a sua própria transação. Só se
                // avisa do que falhou, sem fingir que nada mudou.
                throw new DomainRuleException(
                    'mercado_governo_parcial',
                    "{$alterados} recurso(s) salvo(s). Falharam: ".implode(' ', $erros),
                );
            }

            return "Mercado do Governo atualizado: {$alterados} recurso(s).";
        });
    }

    // ── Notícias ─────────────────────────────────────────────────────────────

    public function noticiaPublicar(Request $request, PublicarNoticia $publicar): RedirectResponse
    {
        $dados = $request->validate([
            'titulo' => ['required', 'string', 'max:140'],
            'corpo' => ['required', 'string'],
            'autor' => ['nullable', 'string', 'max:60'],
        ]);

        return $this->tentar('noticia.publicar', function () use ($publicar, $dados) {
            $n = $publicar->publicar($dados['titulo'], $dados['corpo'], $dados['autor'] ?? null);

            return "Comunicado #{$n->id} publicado.";
        });
    }

    public function noticiaRemover(News $news, PublicarNoticia $publicar): RedirectResponse
    {
        $id = $news->id;
        $titulo = $news->title ?? '';
        $publicar->remover($id);

        return $this->ok('noticia.remover', "Notícia #{$id} removida. {$titulo}", "news:{$id}");
    }

    /**
     * Reescreve uma notícia já publicada (2026-07-13).
     *
     * A auditoria guarda o **antes e o depois** — e aqui isso não é zelo burocrático: um comunicado
     * público que muda de texto depois de lido é exatamente o tipo de coisa que alguém vai querer
     * conferir, e a única defesa do operador contra a acusação de ter reescrito a história é o
     * registro de que reescreveu, e do quê para o quê.
     */
    public function noticiaEditar(Request $request, News $news): RedirectResponse
    {
        $dados = $request->validate([
            'titulo' => ['required', 'string', 'max:140'],
            'corpo' => ['required', 'string'],
            'autor' => ['nullable', 'string', 'max:60'],
        ]);

        $antes = "«{$news->title}» / {$news->author}";

        $news->forceFill([
            'title' => $dados['titulo'],
            'body' => $dados['corpo'],
            'author' => $dados['autor'] ?? $news->author,
            'updated_at' => now(),
        ])->save();

        return $this->ok(
            'noticia.editar',
            "Notícia #{$news->id} reescrita. Antes: {$antes}. Agora: «{$news->title}» / {$news->author}",
            "news:{$news->id}",
        );
    }

    /**
     * OCULTAR — administrativo e **reversível**. Sai do mural agora e volta a qualquer momento.
     *
     * É o botão para o erro de redação e para a notícia publicada cedo demais. O colono deixa de
     * vê-la (o `noMural()` do endpoint dele barra); o painel continua a mostrá-la.
     */
    public function noticiaOcultar(News $news): RedirectResponse
    {
        $voltando = $news->oculta();

        $news->forceFill(['hidden_at' => $voltando ? null : now()])->save();

        return $this->ok(
            $voltando ? 'noticia.reexibir' : 'noticia.ocultar',
            $voltando
                ? "Notícia #{$news->id} de volta ao mural. «{$news->title}»"
                : "Notícia #{$news->id} oculta do mural. «{$news->title}»",
            "news:{$news->id}",
        );
    }

    /**
     * INATIVAR — **fim de vida**. A notícia deixou de ser verdadeira.
     *
     * Sai do mural e fica arquivada, marcada. É o que preserva o histórico em vez de destruí-lo: uma
     * notícia inativa continua provando que a coisa foi dita, e quando. Reversível também — mas o
     * nome diz outra coisa, e é essa a diferença para o ocultar.
     */
    public function noticiaInativar(News $news): RedirectResponse
    {
        $reativando = $news->inativa();

        $news->forceFill(['inactive_at' => $reativando ? null : now()])->save();

        return $this->ok(
            $reativando ? 'noticia.reativar' : 'noticia.inativar',
            $reativando
                ? "Notícia #{$news->id} reativada. «{$news->title}»"
                : "Notícia #{$news->id} inativada (envelheceu). «{$news->title}»",
            "news:{$news->id}",
        );
    }

    // ── Bugs/Melhorias (D-95) ────────────────────────────────────────────────

    /** Marca lida/não lida — o mesmo alternar de `noticiaOcultar`, sem julgar o conteúdo. */
    public function feedbackLida(\App\Models\Feedback $feedback): RedirectResponse
    {
        $voltando = $feedback->lida();

        $feedback->forceFill(['lida_at' => $voltando ? null : now()])->save();

        return $this->ok(
            $voltando ? 'feedback.marcar_nao_lida' : 'feedback.marcar_lida',
            $voltando
                ? "Feedback #{$feedback->id} marcado como não lido. «{$feedback->assunto}»"
                : "Feedback #{$feedback->id} marcado como lido. «{$feedback->assunto}»",
            "feedback:{$feedback->id}",
        );
    }

    /**
     * Responder AVISA o jogador pelo rádio (D-91) — mesma conta "Capital" que já avisa sobre o
     * Pátio. Sem isto, o admin escreveria a resposta e o jogador só a veria se voltasse a esta
     * mensagem por conta própria, o que ele não tem motivo nenhum para fazer.
     *
     * Responder também marca como lida — seria estranho responder algo que o painel ainda
     * mostra como "não lido".
     */
    public function feedbackResponder(
        Request $request,
        \App\Models\Feedback $feedback,
        \App\Domain\Chat\EnviarMensagem $chat,
    ): RedirectResponse {
        $dados = $request->validate([
            'resposta' => ['required', 'string', 'min:1', 'max:2000'],
        ]);

        return $this->tentar('feedback.responder', function () use ($feedback, $dados, $chat) {
            $agora = now();

            $feedback->forceFill([
                'resposta' => $dados['resposta'],
                'respondida_at' => $agora,
                'lida_at' => $feedback->lida_at ?? $agora,
            ])->save();

            $chat->sistema(
                \App\Domain\Chat\ContaSistema::capital(),
                $feedback->user,
                "Resposta ao seu \"{$feedback->assunto}\": {$dados['resposta']}",
            );

            return "Resposta enviada para #{$feedback->id} «{$feedback->assunto}», e avisada pelo rádio.";
        }, "feedback:{$feedback->id}");
    }

    /** FEITO — o registro de que aquele bug foi corrigido ou aquela sugestão foi implementada. */
    public function feedbackFeito(\App\Models\Feedback $feedback): RedirectResponse
    {
        $desfazendo = $feedback->feita();

        $feedback->forceFill(['feito_at' => $desfazendo ? null : now()])->save();

        return $this->ok(
            $desfazendo ? 'feedback.desmarcar_feito' : 'feedback.marcar_feito',
            $desfazendo
                ? "Feedback #{$feedback->id} voltou a pendente. «{$feedback->assunto}»"
                : "Feedback #{$feedback->id} marcado como feito. «{$feedback->assunto}»",
            "feedback:{$feedback->id}",
        );
    }

    // ── Ministério do Tesouro — Subsídios (D-57; D-113) ───────────────────────

    /**
     * Recebe o `quantidade[código] = valor` de um formulário de subsídio (mesmo padrão
     * `qtd[{{ $r->code }}]` da aba Mercado) e devolve só as entradas válidas: código do catálogo
     * (ou `Tesouro::FERT`) com valor positivo, já convertido para a unidade interna — micro-Fert$
     * para FERT, unidades inteiras para o resto.
     *
     * @return array<string,int>
     */
    private function parseSubsidio(array $quantidades): array
    {
        $codigos = \App\Models\ResourceType::pluck('code')->push(Tesouro::FERT);

        $entregas = [];
        foreach ($quantidades as $recurso => $valor) {
            if (! $codigos->contains($recurso) || (float) $valor <= 0) {
                continue;
            }

            $qtd = $recurso === Tesouro::FERT
                ? (int) round(((float) $valor) * Colony::MICRO_POR_FERT)
                : (int) $valor;

            if ($qtd > 0) {
                $entregas[$recurso] = $qtd;
            }
        }

        return $entregas;
    }

    private function resumoSubsidio(array $entregas): string
    {
        return collect($entregas)
            ->map(fn ($q, $r) => $r === Tesouro::FERT ? number_format($q).' µF$' : "{$q} {$r}")
            ->implode('; ');
    }

    /**
     * Subsídios — "Mandar pra um colono" (D-113): a lista inteira do catálogo (+ Fert$) num
     * formulário só, ao contrário do antigo "Enviar Recursos" (um recurso por vez). Todo-ou-nada:
     * os vários `Tesouro::distribuir()` (cada um já transacional) vivem dentro de UMA
     * transação externa — se o terceiro recurso não couber no saldo, os dois primeiros voltam.
     */
    public function subsidioColono(Request $request, Tesouro $tesouro): RedirectResponse
    {
        $dados = $request->validate([
            'colony_id' => ['required', 'integer', 'exists:colonies,id'],
            'quantidade' => ['required', 'array'],
            'quantidade.*' => ['nullable', 'numeric', 'min:0'],
        ]);

        $destino = Colony::findOrFail($dados['colony_id']);
        $entregas = $this->parseSubsidio($dados['quantidade']);

        if ($entregas === []) {
            throw ValidationException::withMessages([
                'quantidade' => 'Informe ao menos um recurso com quantidade positiva.',
            ]);
        }

        return $this->tentar('tesouro.subsidio_colono', function () use ($tesouro, $destino, $entregas) {
            \Illuminate\Support\Facades\DB::transaction(function () use ($tesouro, $destino, $entregas) {
                foreach ($entregas as $recurso => $qtd) {
                    $tesouro->distribuir($destino, $recurso, $qtd);
                }
            });

            return "Tesouro enviou a {$destino->name}: {$this->resumoSubsidio($entregas)}.";
        }, "colony:{$destino->id}");
    }

    /**
     * Subsídios — "Mandar para todos colonos" (D-113): a MESMA quantidade de cada recurso
     * escolhido, para cada colônia fundada. Todo-ou-nada de verdade: primeiro confere se o Tesouro
     * comporta o custo AGREGADO (quantidade × nº de colônias) — sem isso, a entrega pararia no
     * meio da lista de colônias, e algumas receberiam o subsídio e outras não, o que ninguém pediu.
     */
    public function subsidioTodos(Request $request, Tesouro $tesouro): RedirectResponse
    {
        $dados = $request->validate([
            'quantidade' => ['required', 'array'],
            'quantidade.*' => ['nullable', 'numeric', 'min:0'],
        ]);

        $porColonia = $this->parseSubsidio($dados['quantidade']);

        if ($porColonia === []) {
            throw ValidationException::withMessages([
                'quantidade' => 'Informe ao menos um recurso com quantidade positiva.',
            ]);
        }

        $colonias = Colony::orderBy('id')->get(['id', 'name']);

        if ($colonias->isEmpty()) {
            throw ValidationException::withMessages(['quantidade' => 'Não há colônias fundadas.']);
        }

        $custoTotal = collect($porColonia)->map(fn ($qtd) => $qtd * $colonias->count())->all();

        if (! $tesouro->comporta($custoTotal)) {
            throw ValidationException::withMessages([
                'quantidade' => "O Tesouro não tem saldo para entregar isto às {$colonias->count()} colônias — reduza a quantidade ou escolha menos recursos.",
            ]);
        }

        return $this->tentar('tesouro.subsidio_todos', function () use ($tesouro, $colonias, $porColonia) {
            \Illuminate\Support\Facades\DB::transaction(function () use ($tesouro, $colonias, $porColonia) {
                foreach ($colonias as $colonia) {
                    foreach ($porColonia as $recurso => $qtd) {
                        $tesouro->distribuir($colonia, $recurso, $qtd);
                    }
                }
            });

            return "Tesouro enviou a cada uma das {$colonias->count()} colônias: {$this->resumoSubsidio($porColonia)}.";
        });
    }

    /**
     * O Painel do Ministério dos Transportes (§16), na parte que é do **operador** (D-60).
     *
     * O GDD dá a este painel quatro atribuições de configuração — "configurar a curva de depreciação
     * por hora de uso", "configurar o limite crítico de desempenho", "configurar a perda de vida
     * útil e o teto de revenda a cada manutenção" — e **não publica nenhum dos números**. Eles são,
     * portanto, do operador. Foi isso que permitiu tirar a depreciação da geladeira sem inventar
     * constante no código, e é este formulário.
     *
     * O jogo obedece ao que estiver na linha — não ao que o seeder escreveu.
     */
    /**
     * Os dez números da guerra (§27.3, §28.10; D-70). **Até aqui só se mudavam por SQL.**
     *
     * O §27.3 escreve os bônus defensivos como "(valores configuráveis)" e o §28.10 manda comparar
     * níveis sem publicar a conta — quer dizer que o GDD delega, e o painel é onde ele delega para.
     * Mexer no preço do Nióbio direto na produção com um `UPDATE` é o tipo de coisa que ninguém
     * audita e ninguém desfaz; aqui fica registrado quem mudou o quê, de quanto para quanto.
     *
     * Os limites são guardas contra o dedo escorregando, não regras de jogo: o único que morde de
     * verdade é o `predador_min_bps ≤ predador_max_bps`, porque invertê-los prenderia a chance de
     * apreensão num intervalo vazio — e aí o `max(min, min(max, x))` do motor devolveria sempre o
     * mínimo, calado.
     */
    public function guerra(Request $request): RedirectResponse
    {
        $dados = $request->validate([
            // Bônus por NÍVEL da construção. 0 desliga a construção como defesa; 10000 = +100%/nível.
            'muralha_bonus_bps' => ['required', 'integer', 'min:0', 'max:10000'],
            'torre_bonus_bps' => ['required', 'integer', 'min:0', 'max:10000'],
            'bastiao_bonus_bps' => ['required', 'integer', 'min:0', 'max:10000'],

            // A Torre olha (sabotagem) e avisa (o defensor vê a marcha). Duas coisas distintas.
            'torre_deteccao_bps_por_nivel' => ['required', 'integer', 'min:0', 'max:10000'],
            'torre_aviso_minutos_por_nivel' => ['required', 'integer', 'min:0', 'max:120'],

            // A apreensão do Predador (§28.10): base no empate, ±por nível, presa entre min e max.
            'predador_base_bps' => ['required', 'integer', 'min:0', 'max:10000'],
            'predador_por_nivel_bps' => ['required', 'integer', 'min:0', 'max:10000'],
            'predador_min_bps' => ['required', 'integer', 'min:0', 'max:10000'],
            'predador_max_bps' => ['required', 'integer', 'min:0', 'max:10000', 'gte:predador_min_bps'],

            // O preço do Nióbio, em micro-Fert$. É o freio de todo o exército do planeta: nada o
            // produz, e a Sentinela custa 3. Zerá-lo torna a guerra gratuita.
            'niobio_preco_micro' => ['required', 'integer', 'min:0'],

            // RepararModulo (D-118): fração do custo de construção da estrutura no nível atual.
            'reparo_bps_do_custo' => ['required', 'integer', 'min:0', 'max:10000'],
        ]);

        $config = WarSetting::singleton();
        $antes = $config->only(array_keys($dados));

        return $this->tentar('guerra.parametros', function () use ($dados, $config, $antes) {
            $config->update($dados);

            $mudou = collect($dados)
                ->reject(fn ($v, $k) => (int) $v === (int) $antes[$k])
                ->map(fn ($v, $k) => "{$k}: {$antes[$k]} → {$v}")
                ->implode('; ');

            /*
             * "Valem já no próximo combate", e não "nos combates em curso": a força e o dano são
             * **congelados** quando o exército chega (D-66). Uma Muralha que fica mais forte agora não
             * salva a zona que já está sob ataque — e é bom que o operador saiba disso antes de tentar
             * salvar alguém no meio de uma batalha.
             */
            return 'Parâmetros da guerra atualizados. '
                .($mudou !== '' ? $mudou : 'Nada mudou.')
                .' Valem no PRÓXIMO combate: os em curso já congelaram a força e o dano.';
        });
    }

    /**
     * Federação — alavanca de emergência (D-114). Sem "criar" nem "mover membro" pelo painel:
     * nenhum sistema comparável (Acordo de Troca, Guerra) tem isso — o operador intervém no
     * extremo (nome ofensivo, disputa entre jogadores, Líder inativo com colônia zumbi), não no
     * meio do fluxo do jogador. O saldo do fundo vai para o Tesouro, mesma regra da dissolução
     * normal (`DissolverFederacao`) — ver docs/decisoes.md D-114.
     */
    public function federacaoDissolver(Request $request, Federation $federation, DissolverFederacao $dissolver): RedirectResponse
    {
        $dados = $request->validate(['confirmacao' => ['required', 'string']]);

        if ($dados['confirmacao'] !== 'DISSOLVER') {
            return $this->erro('Digite DISSOLVER, exatamente assim, para confirmar.');
        }

        return $this->tentar('federacao.dissolver', function () use ($federation, $dissolver) {
            $nome = $federation->name;
            $dissolver->handle($federation);

            return "Federação «{$nome}» dissolvida pelo operador. O saldo do fundo foi para o Tesouro.";
        }, "federation:{$federation->id}");
    }

    /**
     * Os dois números do §04 (D-119, D-120): o limite antimonopólio territorial ("20% → 10%") e o
     * desconto de tributo entre aliados ("50%", v3.0) — nenhum dos dois vale de código, os dois são
     * do operador, mesmo padrão do resto da casa.
     */
    public function federacaoParametros(Request $request): RedirectResponse
    {
        $dados = $request->validate([
            'teto_ocupacao_zonas_bps' => ['required', 'integer', 'min:0', 'max:10000'],
            'desconto_tributo_aliados_bps' => ['required', 'integer', 'min:0', 'max:10000'],
        ]);

        $config = \App\Models\FederationSetting::singleton();
        $antes = $config->only(array_keys($dados));

        return $this->tentar('federacao.parametros', function () use ($dados, $config, $antes) {
            $config->update($dados);

            $mudou = collect($dados)
                ->reject(fn ($v, $k) => (int) $v === (int) $antes[$k])
                ->map(fn ($v, $k) => "{$k}: {$antes[$k]} → {$v}")
                ->implode('; ');

            return 'Parâmetros da federação atualizados. '.($mudou !== '' ? $mudou : 'Nada mudou.');
        });
    }

    public function transporte(Request $request): RedirectResponse
    {
        $dados = $request->validate([
            // 0 = sem desgaste nenhum (desliga a depreciação). Teto de 1000 bps/h = 10%/h, que já é
            // absurdo — mas é uma guarda contra o dedo escorregando, não uma regra de jogo.
            'desgaste_bps_por_hora' => ['required', 'integer', 'min:0', 'max:1000'],
            // O piso não pode passar de 100%: um veículo não anda melhor do que novo.
            'piso_desempenho_bps' => ['required', 'integer', 'min:0', 'max:10000'],
            'manutencao_bps_do_custo' => ['required', 'integer', 'min:0', 'max:10000'],
            'perda_de_teto_bps' => ['required', 'integer', 'min:0', 'max:10000'],
            /*
             * A âncora do teto do Furgão (D-73). O `min:1` é a regra que importa: um zero digitado
             * no painel faria teto 0 e **recusaria todo anúncio de Furgão** — pareceria "voltar ao
             * sem-teto do aditivo 14" e seria o contrário. Tirar o Furgão do mercado de usados é
             * decisão para se tomar de frente, não por um dedo escorregado.
             */
            'furgao_preco_referencia_micro' => ['required', 'integer', 'min:1'],
            // O frete público (§07, D-76). Zero é permitido: frete de graça é subsídio, decisão
            // legítima do operador — a Garagem finita continua sendo o freio.
            'frete_base_micro' => ['required', 'integer', 'min:0'],
            'frete_por_slot_micro' => ['required', 'integer', 'min:0'],
        ]);

        // O antes e o depois destes quatro números importam: eles mudam o envelhecimento da frota
        // inteira, e sem o log ninguém saberia por que a depreciação começou a morder.
        $config = TransportSetting::singleton();
        $antes = $config->only(array_keys($dados));

        return $this->tentar('transporte.parametros', function () use ($dados, $config, $antes) {
            $config->update($dados);

            $mudou = collect($dados)
                ->reject(fn ($v, $k) => (int) $v === (int) $antes[$k])
                ->map(fn ($v, $k) => "{$k}: {$antes[$k]} → {$v}")
                ->implode('; ');

            return 'Parâmetros do Ministério dos Transportes atualizados. '
                .($mudou !== '' ? $mudou : 'Nada mudou.').' Valem já no próximo tick.';
        });
    }

    /**
     * A Fábrica (D-109): preço, estoque-alvo, tempo de fabricação e custo em recursos de UM tipo
     * de veículo. `updateOrInsert` em `fabrica_veiculos` — a mesma tabela que a migration semeou
     * uma vez só; nenhum Seeder a toca depois, então o ajuste do admin nunca é apagado.
     */
    public function fabricaConfig(Request $request): RedirectResponse
    {
        $dados = $request->validate([
            'tipo' => ['required', 'string', Rule::in(\App\Domain\Transport\Ministerio::TIPOS)],
            'preco_fert' => ['required', 'numeric', 'min:0.000001'],
            'estoque_alvo' => ['required', 'integer', 'min:0', 'max:255'],
            'minutos_fabricacao' => ['required', 'integer', 'min:1', 'max:100000'],
            // Mesmo formato recurso:quantidade das outras telas (missões, Gestão de Construções).
            'custo' => ['required', 'string', 'max:2000'],
        ]);

        $custo = [];
        foreach (explode("\n", $dados['custo']) as $linha) {
            $linha = trim($linha);
            if ($linha === '') {
                continue;
            }

            [$recurso, $qtd] = array_pad(explode(':', $linha, 2), 2, null);
            $recurso = trim((string) $recurso);
            $qtd = (int) trim((string) $qtd);

            if ($recurso === '' || $qtd <= 0) {
                throw ValidationException::withMessages([
                    'custo' => "Linha inválida: «{$linha}». Use recurso:quantidade, um por linha.",
                ]);
            }

            if (! \App\Models\ResourceType::whereKey($recurso)->exists()) {
                throw ValidationException::withMessages([
                    'custo' => "«{$recurso}» não é um recurso do catálogo.",
                ]);
            }

            $custo[$recurso] = $qtd;
        }

        return $this->tentar('fabrica.config', function () use ($dados, $custo) {
            \Illuminate\Support\Facades\DB::table('fabrica_veiculos')->where('tipo', $dados['tipo'])->update([
                'preco_micro' => (int) round(((float) $dados['preco_fert']) * 1_000_000),
                'estoque_alvo' => $dados['estoque_alvo'],
                'minutos_fabricacao' => $dados['minutos_fabricacao'],
                'custo_json' => json_encode($custo, JSON_UNESCAPED_UNICODE),
                'admin_id' => auth('admin')->id(),
                'updated_at' => now(),
            ]);

            return "Fábrica de {$dados['tipo']} atualizada: {$dados['preco_fert']} Fert$, estoque-alvo {$dados['estoque_alvo']}.";
        }, "fabrica:{$dados['tipo']}");
    }

    /**
     * Encomenda avulsa (D-109): um empurrão pontual na prateleira de um tipo, fora do ciclo do
     * tick — reaproveita a MESMA regra de custo (debita o Tesouro, cria o veículo em
     * `fabricando`), só que disparada na hora pelo operador, N vezes. Não muda o estoque-alvo: o
     * tick volta a repor só até ele depois que este excedente for vendido.
     */
    public function fabricaEncomendar(Request $request): RedirectResponse
    {
        $dados = $request->validate([
            'tipo' => ['required', 'string', Rule::in(\App\Domain\Transport\Ministerio::TIPOS)],
            'quantidade' => ['required', 'integer', 'min:1', 'max:50'],
        ]);

        return $this->tentar('fabrica.encomendar', function () use ($dados) {
            $config = \App\Domain\Transport\Ministerio::config($dados['tipo']);
            $tesouro = app(\App\Domain\Treasury\Tesouro::class);
            $placas = app(\App\Domain\Transport\Placas::class);
            $feitos = 0;

            for ($i = 0; $i < $dados['quantidade']; $i++) {
                $ok = \Illuminate\Support\Facades\DB::transaction(function () use ($config, $dados, $tesouro, $placas) {
                    if (! $tesouro->gastar($config['custo'], "fabricacao_avulsa:{$dados['tipo']}")) {
                        return false;
                    }

                    $veiculo = \App\Models\Vehicle::create([
                        'colony_id' => null,
                        'type' => $dados['tipo'],
                        'level' => 1,
                        'status' => 'fabricando',
                        'capacity' => \App\Domain\Logistics\VeiculoSpecs::CAPACIDADE[$dados['tipo']],
                        'ready_at' => now()->addMinutes($config['minutos_fabricacao']),
                    ]);
                    $placas->registrar($veiculo);

                    return true;
                });

                if (! $ok) {
                    break;
                }

                $feitos++;
            }

            if ($feitos < $dados['quantidade']) {
                return "Encomendados {$feitos} de {$dados['quantidade']} — o Tesouro não teve para o resto.";
            }

            return "Encomendados {$feitos} {$dados['tipo']}(s) avulsos. Ficam prontos em {$config['minutos_fabricacao']} min.";
        }, "fabrica:{$dados['tipo']}");
    }

    /** Os cinco valores de XP do Marco (D-75). A CURVA (50×N²) não está aqui: é arbitragem, não balanceamento. */
    public function marco(Request $request): RedirectResponse
    {
        $dados = $request->validate([
            // Zero DESLIGA a fonte (nenhuma linha entra no ledger) — é permitido de propósito:
            // o operador pode decidir que mercado não sobe marco, por exemplo.
            'xp_obra_por_nivel' => ['required', 'integer', 'min:0', 'max:100000'],
            'xp_zona_ocupada' => ['required', 'integer', 'min:0', 'max:100000'],
            'xp_combate_vencido' => ['required', 'integer', 'min:0', 'max:100000'],
            'xp_acordo_executado' => ['required', 'integer', 'min:0', 'max:100000'],
            'xp_mercado_executado' => ['required', 'integer', 'min:0', 'max:100000'],
        ]);

        $config = \App\Models\MilestoneSetting::singleton();
        $antes = $config->only(array_keys($dados));

        return $this->tentar('marco.parametros', function () use ($dados, $config, $antes) {
            $config->update($dados);

            $mudou = collect($dados)
                ->reject(fn ($v, $k) => (int) $v === (int) $antes[$k])
                ->map(fn ($v, $k) => "{$k}: {$antes[$k]} → {$v}")
                ->implode('; ');

            return 'Valores de XP do Marco atualizados. '
                .($mudou !== '' ? $mudou : 'Nada mudou.')
                .' Valem para os atos NOVOS: o ledger de XP não é reescrito.';
        });
    }

    /**
     * O kit inicial de toda colônia nova (D-85), editável pelo admin sem mexer em código (D-92).
     *
     * Só vale para quem funda DEPOIS de salvar — mesma regra que o D-85 já tinha fixado; não há
     * backfill aqui, de propósito (confirmado com o usuário).
     *
     * Nióbio Alienígena e Quartzo Piezoelétrico **não travam** acima do limiar que reabre o muro
     * de progressão do D-17 — só avisam, ao lado do campo, na própria tela. O admin decide de
     * olhos abertos.
     */
    public function kitInicial(Request $request): RedirectResponse
    {
        $codigos = \App\Models\ResourceType::pluck('code');

        $dados = $request->validate([
            'fert' => ['required', 'numeric', 'min:0'],
            'recursos' => ['required', 'array'],
            'recursos.*' => ['required', 'integer', 'min:0'],
            'furgoes' => ['required', 'integer', 'min:0', 'max:255'],
            'caminhoes' => ['required', 'integer', 'min:0', 'max:255'],
        ]);

        // Só os códigos que o catálogo de fato tem — um `<input name="recursos[x]">` forjado não
        // cria uma linha nova em `kit_inicial_recursos` para um recurso que não existe.
        $recursos = collect($dados['recursos'])->only($codigos->all());

        return $this->tentar('kit_inicial.parametros', function () use ($dados, $recursos) {
            $agora = now();

            foreach ($recursos as $codigo => $qtd) {
                \Illuminate\Support\Facades\DB::table('kit_inicial_recursos')->updateOrInsert(
                    ['resource_type' => $codigo],
                    ['amount' => $qtd, 'updated_at' => $agora],
                );
            }

            $config = \App\Models\KitInicialSetting::singleton();
            $config->update([
                'fert_micro' => (int) round(((float) $dados['fert']) * 1_000_000),
                'furgoes' => $dados['furgoes'],
                'caminhoes' => $dados['caminhoes'],
            ]);

            return 'Kit inicial atualizado. Vale só para quem funda a partir de agora — '
                .'colônias já fundadas não são tocadas.';
        });
    }

    /**
     * Gestão de Construções — Tempo (D-107). Grava em `building_specs_overrides`, nunca em
     * `building_specs` (que é semeada de novo a cada `db:seed`) — é por isso que o ajuste
     * sobrevive a um reseed futuro.
     */
    public function construcoesTempo(Request $request): RedirectResponse
    {
        $dados = $this->validarAjusteDeConstrucao($request, ['niveis' => ['required', 'array'], 'niveis.*' => ['required', 'integer', 'min:1', 'max:100000']]);

        return $this->tentar('construcoes.tempo', function () use ($dados) {
            $agora = now();
            $tocados = 0;

            foreach ($dados['niveis'] as $nivel => $minutos) {
                \Illuminate\Support\Facades\DB::table('building_specs_overrides')->updateOrInsert(
                    ['building_type' => $dados['tipo'], 'level' => $nivel],
                    ['build_time_seconds' => $minutos * 60, 'admin_id' => auth('admin')->id(), 'updated_at' => $agora],
                );
                $tocados++;
            }

            return "Tempo de {$dados['nome']} atualizado em {$tocados} nível(is).";
        }, "construcoes:{$dados['tipo']}");
    }

    /**
     * Gestão de Construções — Custo (D-107). Cada nível chega como uma linha
     * `recurso:quantidade` por linha — mesmo formato que `recompensa_recursos` já usa em
     * `validarMissao()` — e vira o `cost_json` do override.
     */
    public function construcoesCusto(Request $request): RedirectResponse
    {
        $dados = $this->validarAjusteDeConstrucao($request, ['niveis' => ['required', 'array'], 'niveis.*' => ['nullable', 'string', 'max:2000']]);

        return $this->tentar('construcoes.custo', function () use ($dados, $request) {
            $agora = now();
            $tocados = 0;

            foreach ($dados['niveis'] as $nivel => $texto) {
                $custo = $this->parseRecursosPorLinha($texto, "niveis.{$nivel}");

                if ($custo === []) {
                    continue;
                }

                \Illuminate\Support\Facades\DB::table('building_specs_overrides')->updateOrInsert(
                    ['building_type' => $dados['tipo'], 'level' => $nivel],
                    [
                        'cost_json' => json_encode($custo, JSON_UNESCAPED_UNICODE),
                        'admin_id' => auth('admin')->id(),
                        'updated_at' => $agora,
                    ],
                );
                $tocados++;
            }

            return "Custo de {$dados['nome']} atualizado em {$tocados} nível(is).";
        }, "construcoes:{$dados['tipo']}");
    }

    /**
     * Gestão de Construções — Manutenção (D-112): consumo extra de recursos por hora, por TIPO de
     * construção — mesma linha `recurso:quantidade` de Custo, mas sem nível (o usuário não pediu
     * granularidade por nível) e restrita a primário/industrial: raro fica de fora, decisão do
     * usuário. Grava em `manutencao_estruturas`, ADITIVA sobre `energia_consumo_hora` do GDD —
     * nunca o substitui (ver docs/decisoes.md D-112). A textarea representa o conjunto inteiro
     * daquele tipo: salvar de novo substitui tudo, então tirar uma linha zera aquele recurso.
     */
    public function construcoesManutencao(Request $request): RedirectResponse
    {
        $tipos = \Illuminate\Support\Facades\DB::table('building_specs')->distinct()->pluck('building_type');

        $dados = $request->validate([
            'building_type' => ['required', 'string', Rule::in($tipos->all())],
            'recursos' => ['nullable', 'string', 'max:2000'],
        ]);

        $nome = \App\Domain\Media\NomesDeExibicao::de($dados['building_type']);
        $consumo = $this->parseRecursosPorLinha($dados['recursos'] ?? '', 'recursos');

        $permitidos = \App\Models\ResourceType::where('tax_class', '!=', 'raro')->pluck('code');
        foreach (array_keys($consumo) as $recurso) {
            if (! $permitidos->contains($recurso)) {
                throw ValidationException::withMessages([
                    'recursos' => "«{$recurso}» é um recurso raro — Manutenção só aceita primário ou industrial.",
                ]);
            }
        }

        return $this->tentar('construcoes.manutencao', function () use ($dados, $consumo, $nome) {
            $agora = now();

            \Illuminate\Support\Facades\DB::transaction(function () use ($dados, $consumo, $agora) {
                \Illuminate\Support\Facades\DB::table('manutencao_estruturas')
                    ->where('building_type', $dados['building_type'])
                    ->delete();

                foreach ($consumo as $recurso => $qtd) {
                    \Illuminate\Support\Facades\DB::table('manutencao_estruturas')->insert([
                        'building_type' => $dados['building_type'],
                        'resource_type' => $recurso,
                        'qtd_hora' => $qtd,
                        'admin_id' => auth('admin')->id(),
                        'created_at' => $agora,
                        'updated_at' => $agora,
                    ]);
                }
            });

            return $consumo === []
                ? "Manutenção de {$nome} zerada — volta a não consumir nada além da energia do GDD."
                : "Manutenção de {$nome} atualizada: ".count($consumo).' recurso(s).';
        }, "construcoes:{$dados['building_type']}");
    }

    /**
     * Valida o que Tempo e Custo têm em comum: a construção existe, e nenhum nível 1 das que já
     * nascem prontas (`Building::NASCE_NO_NIVEL_UM`) — validação no servidor, não só esconder o
     * campo na view, porque um POST forjado não lê HTML.
     *
     * @param  array<string,array<int,string>>  $regrasNiveis
     * @return array{tipo: string, nome: string, niveis: array<int,mixed>}
     */
    private function validarAjusteDeConstrucao(Request $request, array $regrasNiveis): array
    {
        $tipos = \Illuminate\Support\Facades\DB::table('building_specs')->distinct()->pluck('building_type');

        $dados = $request->validate([
            'building_type' => ['required', 'string', Rule::in($tipos->all())],
        ] + $regrasNiveis);

        $niveisValidos = \Illuminate\Support\Facades\DB::table('building_specs')
            ->where('building_type', $dados['building_type'])->pluck('level')->all();

        $niveis = [];
        foreach ($dados['niveis'] as $nivel => $valor) {
            $nivel = (int) $nivel;

            if (! in_array($nivel, $niveisValidos, true)) {
                throw ValidationException::withMessages([
                    'niveis' => "{$dados['building_type']} não tem nível {$nivel} em building_specs.",
                ]);
            }

            if ($nivel === 1 && in_array($dados['building_type'], \App\Models\Building::NASCE_NO_NIVEL_UM, true)) {
                throw ValidationException::withMessages([
                    'niveis' => "{$dados['building_type']} já nasce pronta no nível 1 — não há o que ajustar ali.",
                ]);
            }

            $niveis[$nivel] = $valor;
        }

        return [
            'tipo' => $dados['building_type'],
            'nome' => \App\Domain\Media\NomesDeExibicao::de($dados['building_type']),
            'niveis' => $niveis,
        ];
    }

    /** Mesma regra de `recompensa_recursos`: uma linha `recurso:quantidade`, recurso do catálogo. */
    private function parseRecursosPorLinha(?string $texto, string $campo): array
    {
        $recursos = [];

        foreach (explode("\n", (string) $texto) as $linha) {
            $linha = trim($linha);

            if ($linha === '') {
                continue;
            }

            [$recurso, $qtd] = array_pad(explode(':', $linha, 2), 2, null);
            $recurso = trim((string) $recurso);
            $qtd = (int) trim((string) $qtd);

            if ($recurso === '' || $qtd <= 0) {
                throw ValidationException::withMessages([
                    $campo => "Linha inválida: «{$linha}». Use recurso:quantidade, um por linha.",
                ]);
            }

            if (! \App\Models\ResourceType::whereKey($recurso)->exists()) {
                throw ValidationException::withMessages([
                    $campo => "«{$recurso}» não é um recurso do catálogo. Confira a grafia (ex.: ligas_metalicas).",
                ]);
            }

            $recursos[$recurso] = $qtd;
        }

        return $recursos;
    }

    /**
     * Gestão de Construções — Silo (D-107). Uma grade recurso × nível; só grava as células que
     * vieram no POST e batem com um recurso real do catálogo — a mesma cautela do `kitInicial()`
     * contra um `<input>` forjado criando linha para um recurso que não existe.
     */
    public function construcoesSilo(Request $request): RedirectResponse
    {
        $codigos = \App\Models\ResourceType::pluck('code');

        $dados = $request->validate([
            'capacidades' => ['required', 'array'],
            'capacidades.*' => ['required', 'array'],
            'capacidades.*.*' => ['required', 'integer', 'min:0'],
        ]);

        return $this->tentar('construcoes.silo', function () use ($dados, $codigos) {
            $agora = now();
            $tocadas = 0;

            foreach ($dados['capacidades'] as $recurso => $porNivel) {
                if (! $codigos->contains($recurso)) {
                    continue;
                }

                foreach ($porNivel as $nivel => $capacidade) {
                    $nivel = (int) $nivel;
                    if ($nivel < 1 || $nivel > 10) {
                        continue;
                    }

                    \Illuminate\Support\Facades\DB::table('silo_capacidades')->updateOrInsert(
                        ['resource_type' => $recurso, 'level' => $nivel],
                        ['capacidade' => $capacidade, 'admin_id' => auth('admin')->id(), 'updated_at' => $agora],
                    );
                    $tocadas++;
                }
            }

            return "Capacidade do Silo atualizada em {$tocadas} célula(s).";
        });
    }

    /**
     * Cria um item da Loja de Peças da Endurance (D-135): catálogo dinâmico, substitui as 32 linhas
     * fixas do D-132/D-133. `tipo=unico` força `quantidade_total=1` — não é o admin que garante isto
     * digitando certo, é o código (mesmo espírito do `ativa=true` do `missaoCriar`).
     */
    public function enduranceItemCriar(Request $request): RedirectResponse
    {
        // A validação de domínio (formato dos efeitos, tipo/alvo conhecidos) mora DENTRO de
        // `tentar()` — ela lança `DomainRuleException`, e só o `catch` do `tentar()` a converte em
        // mensagem de erro. Chamá-la antes deixaria a exceção escapar sem resposta amigável.
        return $this->tentar('endurance.item.criar', function () use ($request) {
            [$dados, $efeitos] = $this->validarEnduranceItem($request);

            $item = \App\Models\EnduranceItem::create($dados + ['admin_id' => auth('admin')->id()]);
            $item->efeitos()->createMany($efeitos);

            return "Item «{$item->nome}» ({$item->item_key}) criado em ".
                \App\Models\EnduranceItem::SECOES[$item->secao].', com '.count($efeitos).' efeito(s).';
        });
    }

    /**
     * Edita um item — os efeitos são SUBSTITUÍDOS por completo (apaga e recria), mais simples que
     * diff linha a linha e o volume é baixo (poucos efeitos por item).
     */
    public function enduranceItemEditar(Request $request, \App\Models\EnduranceItem $item): RedirectResponse
    {
        return $this->tentar('endurance.item.editar', function () use ($request, $item) {
            [$dados, $efeitos] = $this->validarEnduranceItem($request, $item->id);

            if ($dados['quantidade_total'] < $item->quantidade_vendida) {
                throw new DomainRuleException(
                    'endurance.item.estoque_abaixo_do_vendido',
                    "«{$item->nome}» já vendeu {$item->quantidade_vendida} unidade(s) — não dá para ".
                    "baixar o total abaixo disso.",
                );
            }

            $item->update($dados + ['admin_id' => auth('admin')->id()]);
            $item->efeitos()->delete();
            $item->efeitos()->createMany($efeitos);

            return "Item «{$item->nome}» ({$item->item_key}) atualizado, com ".count($efeitos).' efeito(s).';
        }, "endurance_item:{$item->id}");
    }

    /**
     * Apaga um item — só se NENHUMA colônia o possui. Uma colônia que já comprou uma peça com
     * bônus de produção teria o efeito arrancado silenciosamente do meio do jogo se isto não travasse
     * (mesma cautela do `missaoApagar`, que trava se a missão já foi sorteada).
     */
    public function enduranceItemApagar(\App\Models\EnduranceItem $item): RedirectResponse
    {
        if (\App\Models\ColonyEnduranceItem::where('endurance_item_id', $item->id)->exists()) {
            return $this->erro(
                "«{$item->nome}» já foi comprado por alguma colônia — apagar arrancaria o efeito dela ".
                'sem aviso. Baixe o estoque a zero em vez disso, se quiser parar de vender.',
            );
        }

        return $this->tentar('endurance.item.apagar', function () use ($item) {
            $nome = $item->nome;
            $chave = $item->item_key;
            $item->delete();

            return "Item «{$nome}» ({$chave}) apagado — nenhuma colônia o possuía.";
        }, "endurance_item:{$item->id}");
    }

    /**
     * A validação comum de criar/editar item da Endurance. Os efeitos chegam como texto, uma linha
     * `tipo_efeito:alvo:valor_bps` (ou `tipo_efeito:valor_bps` quando o tipo não usa alvo — tributo
     * e drone) — mesmo padrão `chave:valor` por linha que `recompensa_recursos` já usa em
     * `missaoCriar`, para não inventar um segundo jeito de digitar lista no mesmo painel.
     *
     * ⚠️ **`alvo` não é validado contra um catálogo** (não há um catálogo fechado de
     * `building_type`/tipo de veículo aqui) — um `alvo` errado (ex. `mina_locl`) cria o efeito, mas
     * ele nunca bate em nenhuma query de bônus, então some silenciosamente. Documentado, não
     * corrigido: seria preciso importar `Building::MVP`/tipos de veículo aqui só para validar texto
     * livre, e o painel já avisa o admin do formato esperado.
     *
     * @return array{0: array, 1: array<int, array{tipo_efeito: string, alvo: ?string, valor_bps: int}>}
     */
    private function validarEnduranceItem(Request $request, ?int $ignorarId = null): array
    {
        $dados = $request->validate([
            'item_key' => [
                'required', 'string', 'max:60', 'alpha_dash',
                Rule::unique('endurance_items', 'item_key')->ignore($ignorarId),
            ],
            'secao' => ['required', Rule::in(array_keys(\App\Models\EnduranceItem::SECOES))],
            'nome' => ['required', 'string', 'max:120'],
            'tipo' => ['required', Rule::in(\App\Models\EnduranceItem::TIPOS)],
            'quantidade_total' => ['required', 'integer', 'min:1', 'max:1000000'],
            'preco' => ['required', 'numeric', 'min:0.000001'],
            'marco' => ['nullable', 'integer', 'min:1', 'max:100'],
            'vendavel_em_leilao' => ['nullable', 'boolean'],
            'descricao' => ['nullable', 'string', 'max:2000'],
            'efeitos' => ['nullable', 'string'],
        ]);

        if ($dados['tipo'] === \App\Models\EnduranceItem::UNICO) {
            $dados['quantidade_total'] = 1;
        }

        $efeitos = [];
        foreach (preg_split('/\R/', trim($dados['efeitos'] ?? '')) as $linha) {
            $linha = trim($linha);
            if ($linha === '') {
                continue;
            }

            $partes = explode(':', $linha);
            if (count($partes) === 2) {
                [$tipoEfeito, $valor] = $partes;
                $alvo = null;
            } elseif (count($partes) === 3) {
                [$tipoEfeito, $alvo, $valor] = $partes;
            } else {
                throw new DomainRuleException(
                    'endurance.efeito.formato',
                    "Linha de efeito inválida: «{$linha}». Use tipo_efeito:valor_bps ou tipo_efeito:alvo:valor_bps.",
                );
            }

            if (! in_array($tipoEfeito, \App\Domain\Endurance\EfeitosDaEndurance::TIPOS, true)) {
                throw new DomainRuleException(
                    'endurance.efeito.tipo',
                    "Tipo de efeito desconhecido: «{$tipoEfeito}».",
                );
            }

            $alvoExigido = in_array($tipoEfeito, \App\Domain\Endurance\EfeitosDaEndurance::EXIGE_ALVO, true);
            if ($alvoExigido && ($alvo === null || $alvo === '')) {
                throw new DomainRuleException(
                    'endurance.efeito.alvo',
                    "O efeito «{$tipoEfeito}» exige um alvo (ex.: mina_local, furgao_de_comercio, global, todos).",
                );
            }

            if (! is_numeric($valor) || (int) $valor < 1) {
                throw new DomainRuleException(
                    'endurance.efeito.valor',
                    "Valor de bps inválido na linha «{$linha}» — precisa ser um inteiro positivo.",
                );
            }

            $efeitos[] = [
                'tipo_efeito' => $tipoEfeito,
                'alvo' => $alvoExigido ? $alvo : null,
                'valor_bps' => (int) $valor,
            ];
        }

        $dados['preco_micro'] = (int) round($dados['preco'] * 1_000_000);
        // Campo vazio ('') não é "integer" (a validação `nullable` o deixa passar como string vazia,
        // não como null) — sem esta conversão, «marco» viraria 0 no banco, não "sem exigência".
        $dados['marco_minimo'] = isset($dados['marco']) && $dados['marco'] !== '' ? (int) $dados['marco'] : null;
        $dados['vendavel_em_leilao'] = (bool) ($dados['vendavel_em_leilao'] ?? false);
        unset($dados['preco'], $dados['marco'], $dados['efeitos']);

        return [$dados, $efeitos];
    }

    /**
     * Gestão de Construções — Fila (D-111): quantos itens cabem na fila da colônia (novato e
     * padrão, preservando a regra dos 5 dias de onboarding) e quantas obras a zona neutra
     * comporta em curso ao mesmo tempo.
     */
    public function construcoesFila(Request $request): RedirectResponse
    {
        $dados = $request->validate([
            'colonia_vagas_novato' => ['required', 'integer', 'min:1', 'max:20'],
            'colonia_vagas_padrao' => ['required', 'integer', 'min:1', 'max:20'],
            'zona_vagas' => ['required', 'integer', 'min:1', 'max:20'],
        ]);

        $config = \App\Models\FilaSetting::singleton();
        $antes = $config->only(array_keys($dados));

        return $this->tentar('construcoes.fila', function () use ($dados, $config, $antes) {
            $config->update($dados);

            $mudou = collect($dados)
                ->reject(fn ($v, $k) => (int) $v === (int) $antes[$k])
                ->map(fn ($v, $k) => "{$k}: {$antes[$k]} → {$v}")
                ->implode('; ');

            return 'Fila de construção atualizada. '.($mudou !== '' ? $mudou : 'Nada mudou.');
        });
    }

    /**
     * Encomenda um caminhão para a GARAGEM do frete público (D-76).
     *
     * Instantâneo e por fiat, ao contrário da prateleira de venda (que tem linha de montagem no
     * tick): a Garagem é infraestrutura do serviço público, não economia — e o operador que a
     * expande está respondendo a demanda, não jogando. Fica tudo na auditoria.
     */
    public function garagem(\App\Domain\Transport\Placas $placas): RedirectResponse
    {
        return $this->tentar('garagem.encomendar', function () use ($placas) {
            $caminhao = \App\Models\Vehicle::create([
                'colony_id' => null,
                'type' => 'caminhao_de_carga',
                'level' => 1,
                'status' => 'ocioso',
                'local' => \App\Models\Vehicle::NO_PATIO,
                'capacity' => \App\Models\Vehicle::CAPACIDADE['caminhao_de_carga'],
            ]);
            $placas->registrar($caminhao);

            $frota = \App\Domain\Frete\Garagem::frota()->count();

            return "Caminhão {$caminhao->plate} entregue à Garagem do Governo. Frota: {$frota}.";
        });
    }

    /**
     * Silencia um colono (§10.2; D-77): a MESMA pena `silencio` do Ministério (§9.4/D-44), aplicada
     * por decisão humana do painel — o filtro conta reincidência, mas não cala ninguém sozinho.
     */
    public function silenciar(Request $request, User $user): RedirectResponse
    {
        $dados = $request->validate([
            'motivo' => ['required', 'string', 'max:255'],
            'horas' => ['required', 'integer', 'min:1', 'max:720'],
        ]);

        return $this->tentar('chat.silenciar', function () use ($user, $dados) {
            \App\Models\Punishment::create([
                'report_id' => null,   // pena do PAINEL, não de um caso — e a coluna é nulável por isso
                'user_id' => $user->id,
                'kind' => \App\Domain\Ministry\PunicaoSpecs::SILENCIO,
                'index_name' => 'conduta_social',
                'points' => 0,
                'applied_at' => now(),
                'expires_at' => now()->addHours((int) $dados['horas']),
            ]);

            return "{$user->nickname} em silêncio por {$dados['horas']} h. Motivo: {$dados['motivo']}";
        }, "user:{$user->id}");
    }

    /** A lista de termos vedados e o raio da vizinhança (§10.1/§10.2; D-77) — do operador. */
    public function chat(Request $request): RedirectResponse
    {
        $dados = $request->validate([
            'vizinhanca_raio_slots' => ['required', 'integer', 'min:1', 'max:100'],
            'termos' => ['nullable', 'string', 'max:5000'],
        ]);

        $config = \App\Models\ChatSetting::singleton();
        $antes = count($config->termos());

        return $this->tentar('chat.parametros', function () use ($config, $dados, $antes) {
            $termos = array_values(array_filter(array_map('trim', explode("\n", (string) ($dados['termos'] ?? '')))));

            $config->update([
                'vizinhanca_raio_slots' => (int) $dados['vizinhanca_raio_slots'],
                'termos_vedados' => $termos,
            ]);

            return 'Chat atualizado: raio de vizinhança '.$dados['vizinhanca_raio_slots']
                .' slots; termos vedados: '.$antes.' → '.count($termos).'.';
        });
    }

    /**
     * Espiar uma conversa privada (§10.3; D-77). O GDD permite o acesso interno E EXIGE o rastro:
     * "todo acesso interno a mensagens reportadas é registrado". O rastro é a linha de auditoria
     * que este método grava ANTES de mostrar qualquer coisa — espiar sem registro é impossível
     * por construção. (A notificação ao denunciado, também do §10.3, espera um sistema de
     * notificações que não existe — registrado no D-77.)
     */
    public function chatEspiar(Request $request): RedirectResponse
    {
        $dados = $request->validate([
            'nickname_a' => ['required', 'string'],
            'nickname_b' => ['required', 'string'],
            'motivo' => ['required', 'string', 'max:255'],
        ]);

        $a = User::where('nickname', $dados['nickname_a'])->first();
        $b = User::where('nickname', $dados['nickname_b'])->first();

        if (! $a || ! $b) {
            return $this->erro('Colono não encontrado. Confira os dois nicknames.');
        }

        app(Auditoria::class)->registrar(
            'chat.acesso_privado',
            "Acessou a conversa privada {$a->nickname} ↔ {$b->nickname}. Motivo: {$dados['motivo']}",
            "users:{$a->id},{$b->id}",
        );

        return redirect()->route('admin.chat', ['privada_a' => $a->id, 'privada_b' => $b->id]);
    }

    /** Liga/desliga um molde de missão do §06 (D-78): o template com defeito sai do baralho sem deploy. */
    public function missaoAlternar(\App\Models\MissionTemplate $template): RedirectResponse
    {
        return $this->tentar('missao.alternar', function () use ($template) {
            $template->update(['ativa' => ! $template->ativa]);

            return "Missão «{$template->titulo}» ({$template->chave}) "
                .($template->ativa ? 'voltou ao baralho.' : 'saiu do baralho — as já entregues seguem valendo.');
        }, "missao:{$template->id}");
    }

    /** Cria um molde de missão (§06; D-78). Nasce ativa — o operador desativa se mudar de ideia. */
    public function missaoCriar(Request $request): RedirectResponse
    {
        $dados = $this->validarMissao($request);

        return $this->tentar('missao.criar', function () use ($dados) {
            $t = \App\Models\MissionTemplate::create($dados + ['ativa' => true]);

            return "Missão «{$t->titulo}» ({$t->chave}) criada, no baralho de {$t->categoria}.";
        });
    }

    /**
     * Edita um molde (§06; D-78).
     *
     * ⚠️ **`acao` e `meta` só valem para missões sorteadas DAQUI EM DIANTE** — o Atribuir copia os
     * dois para a linha de `mission_assignments` no instante do sorteio, e mudar o molde depois não
     * reescreve o que uma colônia já tem na mão (senão editar uma missão ativa faria o progresso
     * dela pular de meta no meio do dia). **A recompensa é diferente, e de propósito**: ela NÃO é
     * copiada — é o painel de admin que serve de torniquete contra a inflação do §06, e um torniquete
     * que só freia amanhã não freia hoje. Editar o prêmio agora vale também para quem já está com a
     * missão na mão e ainda não completou.
     */
    public function missaoEditar(Request $request, \App\Models\MissionTemplate $template): RedirectResponse
    {
        $dados = $this->validarMissao($request, $template->id);
        $antes = $template->only(['titulo', 'meta', 'acao', 'recompensa_fert_micro', 'recompensa_xp']);

        return $this->tentar('missao.editar', function () use ($template, $dados, $antes) {
            $template->update($dados);

            $mudou = collect($dados)
                ->only(array_keys($antes))
                ->reject(fn ($v, $k) => $v === $antes[$k])
                ->map(fn ($v, $k) => "{$k}: {$antes[$k]} → {$v}")
                ->implode('; ');

            return "Missão «{$template->titulo}» ({$template->chave}) atualizada."
                .($mudou !== '' ? " {$mudou}" : '');
        }, "missao:{$template->id}");
    }

    /**
     * Apaga um molde — só se ele NUNCA foi sorteado (D-78). Uma missão já entregue a uma colônia
     * seria destruída junto (a FK é `cascadeOnDelete`), e isso apagaria o rastro de uma recompensa
     * que já saiu do Tesouro. Para um molde com histórico, o botão certo é desativar.
     */
    public function missaoApagar(\App\Models\MissionTemplate $template): RedirectResponse
    {
        if (\App\Models\MissionAssignment::where('template_id', $template->id)->exists()) {
            return $this->erro(
                "«{$template->titulo}» já foi sorteada para alguém — apagar destruiria o histórico. Desative em vez disso.",
            );
        }

        return $this->tentar('missao.apagar', function () use ($template) {
            $chave = $template->chave;
            $titulo = $template->titulo;
            $template->delete();

            return "Missão «{$titulo}» ({$chave}) apagada — nunca tinha sido sorteada.";
        }, "missao:{$template->id}");
    }

    /**
     * A validação comum de criar/editar. `recompensa_recursos` chega como texto, uma linha
     * `recurso:quantidade` por vez — o mesmo padrão dos termos vedados do chat (D-77).
     *
     * ⚠️ **Cada recurso é conferido contra o catálogo real** (`resource_types`). Sem isto, um erro
     * de digitação ("liga_metalicas" por "ligas_metalicas") criaria uma missão que paga um recurso
     * que não existe — silenciosamente: `Progresso::pagar()` faria o `increment` num recurso
     * inexistente, e a colônia não receberia nada, sem erro nenhum. É a mesma classe de silêncio
     * do vínculo de imagem com chave errada (D-72).
     */
    private function validarMissao(Request $request, ?int $ignorarId = null): array
    {
        $dados = $request->validate([
            'chave' => ['required', 'string', 'max:40', 'alpha_dash', Rule::unique('mission_templates', 'chave')->ignore($ignorarId)],
            'categoria' => ['required', Rule::in(array_keys(\App\Models\MissionTemplate::CATEGORIAS))],
            'titulo' => ['required', 'string', 'max:80'],
            'descricao' => ['required', 'string', 'max:200'],
            'acao' => ['required', Rule::in(array_keys(\App\Domain\Missoes\Acoes::TODAS))],
            'meta' => ['required', 'integer', 'min:1', 'max:999'],
            'recompensa_fert' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'recompensa_xp' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'recompensa_recursos' => ['nullable', 'string', 'max:2000'],
            // Narrativa (D-140): o capítulo anterior, se este só libera depois de outro concluído.
            // Nulo = sem pré-requisito (o primeiro capítulo, ou qualquer missão fora de cadeia).
            'requer_template_id' => ['nullable', 'integer', Rule::exists('mission_templates', 'id')->whereNot('id', $ignorarId ?? 0)],
        ]);

        $recursos = [];

        foreach (explode("\n", (string) ($dados['recompensa_recursos'] ?? '')) as $linha) {
            $linha = trim($linha);

            if ($linha === '') {
                continue;
            }

            [$recurso, $qtd] = array_pad(explode(':', $linha, 2), 2, null);
            $recurso = trim((string) $recurso);
            $qtd = (int) trim((string) $qtd);

            if ($recurso === '' || $qtd <= 0) {
                throw ValidationException::withMessages([
                    'recompensa_recursos' => "Linha inválida: «{$linha}». Use recurso:quantidade, um por linha.",
                ]);
            }

            if (! \App\Models\ResourceType::whereKey($recurso)->exists()) {
                throw ValidationException::withMessages([
                    'recompensa_recursos' => "«{$recurso}» não é um recurso do catálogo. Confira a grafia (ex.: ligas_metalicas).",
                ]);
            }

            $recursos[$recurso] = $qtd;
        }

        return [
            'chave' => $dados['chave'],
            'categoria' => $dados['categoria'],
            'titulo' => $dados['titulo'],
            'descricao' => $dados['descricao'],
            'acao' => $dados['acao'],
            'meta' => (int) $dados['meta'],
            'recompensa_fert_micro' => (int) round((float) ($dados['recompensa_fert'] ?? 0) * 1_000_000),
            'recompensa_xp' => (int) ($dados['recompensa_xp'] ?? 0),
            'recompensa_recursos' => $recursos ?: null,
            // D-135 já achou esta armadilha para `marco_minimo`: `nullable` não converte '' em
            // null, só dispensa os demais checks — sem isto, '' viraria 0 no FK e quebraria a
            // constraint (ou apontaria pro template errado).
            'requer_template_id' => isset($dados['requer_template_id']) && $dados['requer_template_id'] !== ''
                ? (int) $dados['requer_template_id'] : null,
        ];
    }

    // ── Jogadores (D-61) ─────────────────────────────────────────────────────

    /**
     * Suspender: barra o acesso e congela **só o comércio** (reusa a restrição do §9.4).
     *
     * Motivo e prazo são obrigatórios — um banimento que não diz por quê e até quando não é
     * moderação, é castigo mudo. `dias` vazio = definitiva.
     */
    public function suspender(Request $request, User $user, Suspender $suspender): RedirectResponse
    {
        $dados = $request->validate([
            'motivo' => ['required', 'string', 'max:500'],
            'dias' => ['nullable', 'integer', 'min:1', 'max:3650'],
        ]);

        return $this->tentarJaAuditado(function () use ($suspender, $user, $dados) {
            $ate = isset($dados['dias']) ? now()->addDays((int) $dados['dias']) : null;
            $suspender->suspender($user, $dados['motivo'], $ate);

            return "{$user->nickname} suspenso.";
        }, "admin.jogador", $user->id);
    }

    public function reintegrar(Request $request, User $user, Suspender $suspender): RedirectResponse
    {
        $motivo = $request->validate(['motivo' => ['required', 'string', 'max:500']])['motivo'];

        return $this->tentarJaAuditado(
            fn () => tap("{$user->nickname} reintegrado.", fn () => $suspender->reintegrar($user, $motivo)),
            'admin.jogador',
            $user->id,
        );
    }

    /**
     * Corrigir o estado de jogo. Lança `ajuste_admin` no ledger, sempre (D-61).
     *
     * Os campos vêm como **saldo absoluto**, não como delta: é o que o operador vê na tela, e pedir
     * "+300" a quem está olhando "1.200" é convite a erro de conta.
     */
    public function corrigir(Request $request, User $user, CorrigirEstado $corrigir): RedirectResponse
    {
        $dados = $request->validate([
            'motivo' => ['required', 'string', 'max:255'],
            'fert' => ['nullable', 'numeric', 'min:0'],
            'recursos' => ['array'],
            'recursos.*' => ['nullable', 'integer', 'min:0'],
            'indices' => ['array'],
            'indices.*' => ['nullable', 'integer', 'min:0', 'max:1000'],
        ]);

        $colony = $user->colony;

        if (! $colony) {
            return $this->erro('Este jogador ainda não fundou colônia.');
        }

        return $this->tentarJaAuditado(function () use ($corrigir, $colony, $dados) {
            $corrigir->corrigir(
                $colony,
                isset($dados['fert']) ? (int) round(((float) $dados['fert']) * Colony::MICRO_POR_FERT) : null,
                array_filter($dados['recursos'] ?? [], fn ($v) => $v !== null),
                array_filter($dados['indices'] ?? [], fn ($v) => $v !== null),
                $dados['motivo'],
            );

            return "Estado de {$colony->name} corrigido.";
        }, 'admin.jogador', $user->id);
    }

    public function editarJogador(Request $request, User $user, Auditoria $auditoria): RedirectResponse
    {
        $dados = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'nickname' => ['required', 'string', 'max:60', Rule::unique('users', 'nickname')->ignore($user->id)],
            'email' => ['required', 'email', Rule::unique('users', 'email')->ignore($user->id)],
        ]);

        $antes = $user->only(['name', 'nickname', 'email']);
        $user->update($dados);

        $auditoria->registrar(
            'jogador.editar',
            "Editou os dados de {$user->nickname}.",
            "user:{$user->id}",
            $antes,
            $user->fresh()->only(['name', 'nickname', 'email']),
        );

        return $this->voltarAoJogador($user, 'Dados atualizados.');
    }

    /**
     * Redefinir a senha de um colono.
     *
     * **Os tokens dele morrem junto.** Uma senha nova que não revogue as sessões antigas não
     * recupera conta nenhuma: quem tiver roubado o token continua entrando com ele, porque token do
     * Sanctum não expira. É a mesma lição do logout (D-53) e da suspensão.
     */
    public function redefinirSenha(Request $request, User $user, Auditoria $auditoria): RedirectResponse
    {
        $senha = $request->validate([
            'password' => ['required', 'string', Password::min(8)],
        ])['password'];

        $user->forceFill(['password' => Hash::make($senha)])->save();
        $tokens = $user->tokens()->count();
        $user->tokens()->delete();

        $auditoria->registrar(
            'jogador.senha',
            "Redefiniu a senha de {$user->nickname} e revogou {$tokens} token(s).",
            "user:{$user->id}",
        );

        return $this->voltarAoJogador($user, 'Senha redefinida e sessões encerradas.');
    }

    /** Realocar a colônia. **Só o dono** (a rota tem o middleware). Exige a palavra REALOCAR. */
    public function realocarColonia(Request $request, User $user, RealocarColonia $realocar): RedirectResponse
    {
        $dados = $request->validate([
            'x' => ['required', 'integer'],
            'y' => ['required', 'integer'],
            'motivo' => ['required', 'string', 'max:255'],
            'confirmacao' => ['required', 'string'],
        ]);

        if ($dados['confirmacao'] !== 'REALOCAR') {
            return $this->erro('Para realocar, escreva REALOCAR. A viagem de todo veículo em rota será refeita.');
        }

        $colony = $user->colony;

        if (! $colony) {
            return $this->erro('Este jogador ainda não fundou colônia.');
        }

        return $this->tentarJaAuditado(function () use ($realocar, $colony, $dados) {
            $realocar->handle($colony, (int) $dados['x'], (int) $dados['y'], $dados['motivo']);

            return "{$colony->name} realocada para ({$dados['x']}, {$dados['y']}).";
        }, 'admin.jogador', $user->id);
    }

    // ── Admins (D-61). Só o dono — a rota tem o middleware `dono`. ────────────

    public function adminCriar(Request $request, Contas $contas): RedirectResponse
    {
        $dados = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'unique:admins,email'],
            'password' => ['required', 'string', Password::min(10)],
            'role' => ['required', Rule::in(Admin::PAPEIS)],
        ]);

        return $this->tentarJaAuditado(
            fn () => tap('Admin criado.', fn () => $contas->criar($dados)),
            'admin.admins',
        );
    }

    public function adminEditar(Request $request, Admin $admin, Contas $contas): RedirectResponse
    {
        $dados = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', Rule::unique('admins', 'email')->ignore($admin->id)],
            'password' => ['nullable', 'string', Password::min(10)],
            'role' => ['required', Rule::in(Admin::PAPEIS)],
        ]);

        return $this->tentarJaAuditado(
            fn () => tap('Admin atualizado.', fn () => $contas->editar($admin, $this->eu(), $dados)),
            'admin.admins',
        );
    }

    public function adminDesativar(Admin $admin, Contas $contas): RedirectResponse
    {
        return $this->tentarJaAuditado(
            fn () => tap("Admin {$admin->email} desativado.", fn () => $contas->desativar($admin, $this->eu())),
            'admin.admins',
        );
    }

    public function adminReativar(Admin $admin, Contas $contas): RedirectResponse
    {
        return $this->tentarJaAuditado(
            fn () => tap("Admin {$admin->email} reativado.", fn () => $contas->reativar($admin)),
            'admin.admins',
        );
    }

    // ── Operação (orquestração, via Artisan em processo) ─────────────────────

    public function tick(): RedirectResponse
    {
        Artisan::call(TickColonies::class);

        return $this->ok('operacao.tick', 'Tick disparado. O mundo avançou.');
    }

    /**
     * Realocação **pontual**: esta colônia, para este `x,y` (2026-07-13).
     *
     * ⚠️ **Não existe realocação em massa pelo painel, e é decisão do usuário.** Existiu um botão
     * "Realocar founders" que movia **todas as colônias do jogo de uma vez** — a ferramenta de uma
     * migração histórica (D-51) que ficara pendurada na tela de Operação, ao lado do "Disparar tick",
     * como se fosse uma coisa que se faz. Não é. Realocar é ato sobre **um jogador escolhido**.
     *
     * O comando `artisan fertways:realocar-founders` continua existindo, fora do painel: ele simula
     * por omissão e só aplica com `--force`.
     *
     * Reusa o mesmo `RealocarColonia` da ficha do jogador (D-61), com os mesmos avisos: a energia já
     * gasta não é acertada, e os Acordos abertos ficam com o prazo da distância antiga.
     */
    public function realocarManual(Request $request, RealocarColonia $realocar): RedirectResponse
    {
        $dados = $request->validate([
            'colony_id' => ['required', 'integer', 'exists:colonies,id'],
            'x' => ['required', 'integer'],
            'y' => ['required', 'integer'],
            'motivo' => ['required', 'string', 'max:255'],
            'confirmacao' => ['required', 'string'],
        ]);

        if ($dados['confirmacao'] !== 'REALOCAR') {
            return $this->erro('Para realocar, escreva REALOCAR. A viagem de todo veículo em rota será refeita.');
        }

        $colonia = Colony::findOrFail($dados['colony_id']);

        // `tentarJaAuditado`: o `RealocarColonia` audita por dentro, com o antes e o depois. Auditar
        // aqui de novo duplicaria a linha.
        return $this->tentarJaAuditado(function () use ($realocar, $colonia, $dados) {
            $realocar->handle($colonia, (int) $dados['x'], (int) $dados['y'], $dados['motivo']);

            return "{$colonia->name} realocada para ({$dados['x']}, {$dados['y']}).";
        }, 'admin.operacao');
    }

    /**
     * A mesma realocação, pela terceira porta: origem e destino escolhidos por clique no mapa
     * (D-146). Idêntico a `realocarManual()` — só o redirecionamento de sucesso muda, de volta
     * pro mapa em vez de Operação. O erro já cai em `redirect()->back()`, que devolve pro mapa
     * naturalmente (é o referer): é assim que "destino inválido" aparece e deixa escolher outro.
     */
    public function realocarPeloMapa(Request $request, RealocarColonia $realocar): RedirectResponse
    {
        $dados = $request->validate([
            'colony_id' => ['required', 'integer', 'exists:colonies,id'],
            'x' => ['required', 'integer'],
            'y' => ['required', 'integer'],
            'motivo' => ['required', 'string', 'max:255'],
            'confirmacao' => ['required', 'string'],
        ]);

        if ($dados['confirmacao'] !== 'REALOCAR') {
            return $this->erro('Para realocar, escreva REALOCAR. A viagem de todo veículo em rota será refeita.');
        }

        $colonia = Colony::findOrFail($dados['colony_id']);

        return $this->tentarJaAuditado(function () use ($realocar, $colonia, $dados) {
            $realocar->handle($colonia, (int) $dados['x'], (int) $dados['y'], $dados['motivo']);

            return "{$colonia->name} realocada para ({$dados['x']}, {$dados['y']}).";
        }, 'admin.mapa');
    }

    /**
     * Liga/desliga uma célula de periferia na lista de fundação (D-147), a partir do mapa.
     *
     * JSON, não formulário/redirect: o admin pode marcar dezenas de células numa sessão, e recarregar
     * a página inteira a cada clique perderia zoom, posição e o resto das marcações já vistas. O
     * próprio domínio audita — `tentarJaAuditado` não serve aqui porque ele redireciona. Um
     * `DomainRuleException` não é capturado aqui de propósito: o `render()` dele já devolve
     * `{"code", "message"}` em 422 — o mesmo formato que `POST /colony` usa pra `celula_invalida`,
     * e é esse `code` que o JS do mapa lê pra mostrar o erro certo.
     */
    public function alternarCelulaDeFundacao(Request $request, AlternarCelulaDeFundacao $alternar): JsonResponse
    {
        $dados = $request->validate([
            'x' => ['required', 'integer', 'between:-50,50'],
            'y' => ['required', 'integer', 'between:-50,50'],
        ]);

        $liberada = $alternar->handle((int) $dados['x'], (int) $dados['y']);

        return response()->json(['liberada' => $liberada]);
    }

    /**
     * Cria ou remove uma zona neutra fora dos 4 distritos originais (D-148), a partir do mapa.
     *
     * JSON, mesmo motivo de `alternarCelulaDeFundacao`: o Dôno pode marcar várias zonas numa
     * sessão. `mineral` só é exigido pelo domínio quando a célula ainda não é zona (criando); ao
     * remover, o campo é ignorado — não precisa nem chegar no corpo do POST.
     */
    public function alternarZonaNeutra(Request $request, AlternarZonaNeutra $alternar): JsonResponse
    {
        $dados = $request->validate([
            'x' => ['required', 'integer', 'between:-50,50'],
            'y' => ['required', 'integer', 'between:-50,50'],
            'mineral' => ['nullable', 'string', Rule::in(ZonasNeutras::MINERAIS)],
        ]);

        $criada = $alternar->handle((int) $dados['x'], (int) $dados['y'], $dados['mineral'] ?? null);

        return response()->json(['criada' => $criada]);
    }

    // ── Gestão de imagens (D-68) ─────────────────────────────────────────────

    /** Envia um PNG para a biblioteca. O arquivo vai para fora da árvore de deploy. */
    public function imagemEnviar(Request $request, Biblioteca $biblioteca): RedirectResponse
    {
        $dados = $request->validate([
            'categoria' => ['required', Rule::in(array_keys(Biblioteca::CATEGORIAS))],
            'arquivo' => ['required', 'file'],
        ]);

        return $this->tentar('imagem.enviar', function () use ($biblioteca, $dados, $request) {
            $a = $biblioteca->enviar(
                $dados['categoria'],
                $request->file('arquivo'),
                auth('admin')->id(),
            );

            return "Imagem {$a->filename} enviada para {$a->category}.";
        });
    }

    /**
     * Vincula (ou desvincula) uma imagem a uma coisa do jogo.
     *
     * `media_asset_id` vazio = **desvincular**: a construção volta ao hexágono colorido. Não é um
     * caso de erro, é uma escolha — e é ela que torna a arte reversível sem apagar arquivo nenhum.
     */
    public function imagemVincular(Request $request): RedirectResponse
    {
        $dados = $request->validate([
            'entity_key' => ['required', Rule::in(array_keys(Vinculaveis::todas()))],
            'media_asset_id' => ['nullable', 'integer', 'exists:media_assets,id'],
        ]);

        $nome = Vinculaveis::todas()[$dados['entity_key']];

        if (empty($dados['media_asset_id'])) {
            ImageBinding::where('entity_key', $dados['entity_key'])->delete();

            return $this->ok(
                'imagem.desvincular',
                "{$nome} voltou ao hexágono: sem imagem.",
                "entity:{$dados['entity_key']}",
            );
        }

        $a = MediaAsset::findOrFail($dados['media_asset_id']);

        ImageBinding::updateOrCreate(
            ['entity_key' => $dados['entity_key']],
            ['media_asset_id' => $a->id],
        );

        return $this->ok(
            'imagem.vincular',
            "{$nome} passou a usar {$a->category}/{$a->filename}.",
            "entity:{$dados['entity_key']}",
        );
    }

    /**
     * Apaga da biblioteca — o arquivo e o registro.
     *
     * As construções que a usavam **voltam ao hexágono**, e a auditoria registra **quais**: sem isso,
     * alguém apagaria uma imagem, três prédios perderiam a arte, e ninguém saberia relacionar as duas
     * coisas semanas depois.
     */
    public function imagemApagar(MediaAsset $media, Biblioteca $biblioteca): RedirectResponse
    {
        $ref = "{$media->category}/{$media->filename}";
        $orfas = $biblioteca->apagar($media);

        $quem = $orfas === []
            ? 'Não estava em uso.'
            : 'Voltaram ao hexágono: '.implode(', ', array_map(
                fn ($k) => Vinculaveis::todas()[$k] ?? $k,
                $orfas,
            )).'.';

        return $this->ok('imagem.apagar', "Imagem {$ref} apagada. {$quem}", "media:{$media->id}");
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Roda a ação, **audita**, traduz erro de regra e volta.
     *
     * **A auditoria não é opcional.** Este é o gargalo por onde toda ação bem-sucedida passa, e por
     * isso `ok()` **exige o nome da ação**: não dá para acrescentar um botão ao painel e esquecer de
     * registrá-lo — o código não compila sem nomear o que faz. É a mesma ideia do `ledger`, que
     * obriga todo recurso a ter origem.
     */
    private function tentar(string $acao, callable $fn, ?string $alvo = null): RedirectResponse
    {
        try {
            return $this->ok($acao, $fn(), $alvo);
        } catch (DomainRuleException $e) {
            return $this->erro($e->getMessage());
        }
    }

    /**
     * Para as ações cujo **serviço de domínio já auditou** — com o antes e o depois, que só ele
     * conhece: `Suspender`, `CorrigirEstado`, `Contas` e `RealocarColonia`.
     *
     * Auditar de novo aqui duplicaria a linha. O nome é feio de propósito: quem o usar sem que o
     * domínio audite está criando uma ação invisível, e o nome do método é o aviso.
     */
    private function tentarJaAuditado(callable $fn, string $rota = 'admin.dashboard', mixed $param = null): RedirectResponse
    {
        try {
            $msg = $fn();

            return redirect()->route($rota, $param ? [$param] : [])->with('ok', $msg);
        } catch (DomainRuleException $e) {
            return $this->erro($e->getMessage());
        }
    }

    private function ok(string $acao, string $msg, ?string $alvo = null): RedirectResponse
    {
        app(Auditoria::class)->registrar($acao, $msg, $alvo);

        return redirect()->back()->with('ok', $msg);
    }

    private function erro(string $msg): RedirectResponse
    {
        return redirect()->back()->with('erro', $msg);
    }

    private function voltarAoJogador(User $user, string $msg): RedirectResponse
    {
        return redirect()->route('admin.jogador', $user)->with('ok', $msg);
    }

    private function eu(): Admin
    {
        return Auth::guard('admin')->user();
    }

    private function emMicro(mixed $fert): ?int
    {
        return ($fert === null || $fert === '') ? null : (int) round(((float) $fert) * Colony::MICRO_POR_FERT);
    }
}
