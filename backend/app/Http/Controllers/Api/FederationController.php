<?php

namespace App\Http\Controllers\Api;

use App\Domain\Eventos\Modificadores;
use App\Domain\Federacao\Aliancas;
use App\Domain\Federacao\AlterarCargo;
use App\Domain\Federacao\Concentracao;
use App\Domain\Federacao\ContribuirParaOFundo;
use App\Domain\Federacao\CriarFederacao;
use App\Domain\Federacao\Diplomacia;
use App\Domain\Federacao\EnviarConviteOuPedido;
use App\Domain\Federacao\ExpulsarMembro;
use App\Domain\Federacao\ResponderConviteOuPedido;
use App\Domain\Federacao\SacarDoFundo;
use App\Domain\Federacao\SairDaFederacao;
use App\Domain\Federacao\TransferirLideranca;
use App\Domain\GuerraFederativa\Capitulacao;
use App\Domain\GuerraFederativa\DeclararGuerra;
use App\Domain\GuerraFederativa\Neutralidade;
use App\Domain\GuerraFederativa\TratadoDePaz;
use App\Exceptions\DomainRuleException;
use App\Http\Controllers\Controller;
use App\Models\Colony;
use App\Models\Federation;
use App\Models\FederationHolding;
use App\Models\FederationInvite;
use App\Models\FederationSetting;
use App\Models\FederationWar;
use App\Models\FederationWarProposal;
use App\Models\NeutralZone;
use App\Models\WarSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Federação (GDD §04/§07; docs/decisoes.md D-114), Fatia 1 — o Quartel de Alianças, Capital slot 9.
 */
class FederationController extends Controller
{
    public function __construct(
        private readonly CriarFederacao $criar,
        private readonly EnviarConviteOuPedido $enviar,
        private readonly ResponderConviteOuPedido $responder,
        private readonly SairDaFederacao $sair,
        private readonly TransferirLideranca $transferir,
        private readonly ExpulsarMembro $expulsar,
        private readonly AlterarCargo $alterarCargo,
        private readonly SacarDoFundo $sacar,
        private readonly Diplomacia $diplomacia,
        private readonly Aliancas $aliancas,
    ) {}

    /** GET /federation — a federação da própria colônia (ou null), membros, fundo e pendências. */
    public function show(Request $request): JsonResponse
    {
        $colony = $this->colonia($request);

        if ($colony->federation_id === null) {
            // Sem federação: as próprias pendências (convites recebidos, pedidos que já mandou).
            $pendencias = FederationInvite::where('colony_id', $colony->id)
                ->where('status', FederationInvite::PENDENTE)
                ->with('federation:id,name')
                ->get();

            return response()->json([
                'federation' => null,
                'my_role' => null,
                'members' => [],
                'fund' => [],
                'pending_invites' => $pendencias->map(fn (FederationInvite $i) => $this->invitePayload($i)),
            ]);
        }

        $federation = Federation::findOrFail($colony->federation_id);

        // Ordenado em PHP, não em SQL: `FIELD()` é MySQL/MariaDB, e a suíte de testes roda em
        // SQLite, que não tem essa função.
        $membros = Colony::where('federation_id', $federation->id)
            ->get(['id', 'name', 'federation_role'])
            ->sortBy(fn (Colony $c) => array_search($c->federation_role, Federation::CARGOS, true))
            ->values();

        $fundo = FederationHolding::where('federation_id', $federation->id)
            ->where('amount', '>', 0)
            ->orderBy('resource_type')
            ->get(['resource_type', 'amount']);

        // Pendências visíveis só a quem age sobre elas: Líder/Diplomata veem tudo da federação
        // (convites que ela mandou, pedidos que recebeu); membro comum não vê nada aqui.
        $pendencias = $colony->podeConvidarParaFederacao()
            ? FederationInvite::where('federation_id', $federation->id)
                ->where('status', FederationInvite::PENDENTE)
                ->with('colony:id,name')
                ->get()
            : collect();

        return response()->json([
            'federation' => ['id' => $federation->id, 'name' => $federation->name],
            'my_role' => $colony->federation_role,
            'members' => $membros->map(fn (Colony $c) => [
                'colony_id' => $c->id, 'name' => $c->name, 'role' => $c->federation_role,
            ]),
            'fund' => $fundo,
            'pending_invites' => $pendencias->map(fn (FederationInvite $i) => $this->invitePayload($i)),
        ]);
    }

    /** GET /federations — diretório público, para "pedir para entrar". */
    /**
     * A concentração da federação, e **quanto falta para o teto antimonopólio bater** (A2.5).
     *
     * O limite existe desde o D-119 e funciona — mas até aqui ele **bloqueava sem avisar**: o colono
     * descobria o teto no instante em que batia nele, depois de já ter levado tropa e material até a
     * zona. O roadmap chama isso de "proteções antimonopólio **observáveis**". A proteção já era; o
     * que faltava era vê-la chegando.
     */
    public function concentracao(Request $request, Concentracao $concentracao): JsonResponse
    {
        $federacao = $request->user()->colony?->federation;

        if (! $federacao) {
            return response()->json(['tem_federacao' => false]);
        }

        return response()->json(['tem_federacao' => true] + $concentracao->de($federacao));
    }

    /**
     * GET /federation/diplomacia — a mesa diplomática (A2.5, item 7).
     *
     * Traz as relações que existem **e** as federações com quem ainda dá para tratar. Sem a segunda
     * lista a tela seria um mural de nada até alguém propor primeiro, e ninguém propõe o que não
     * consegue ver.
     */
    public function diplomacia(Request $request): JsonResponse
    {
        $federacao = $request->user()->colony?->federation;

        if (! $federacao) {
            return response()->json(['tem_federacao' => false]);
        }

        $config = FederationSetting::singleton();
        $relacoes = $this->aliancas->relacoesDe($federacao->id);
        $comRelacao = array_map(fn ($r) => $r['federacao']->id, $relacoes);

        return response()->json([
            'tem_federacao' => true,
            'pode_tratar' => (bool) $request->user()->colony?->podeConvidarParaFederacao(),
            'max_aliadas' => (int) $config->max_aliadas,
            'aliadas' => count($this->aliancas->aliadasDe($federacao->id)),
            // Os dois descontos lado a lado: é o que torna visível POR QUE filiar-se vale mais.
            'desconto_interno' => (int) $config->desconto_tributo_aliados_bps / 100,
            'desconto_alianca' => (int) $config->desconto_tributo_aliancas_bps / 100,
            // A2.10: o caixa comum, e o que declarar guerra custaria agora.
            'fundo_fert' => $federacao->fert_micro / Colony::MICRO_POR_FERT,
            'guerra' => $this->mesaDeGuerra($federacao),

            'relacoes' => array_map(fn ($r) => [
                'id' => $r['federacao']->id,
                'nome' => $r['federacao']->name,
                'status' => $r['status'],
                'propus' => $r['propus'],
            ], $relacoes),
            'disponiveis' => Federation::whereNull('disbanded_at')
                ->whereKeyNot($federacao->id)
                ->whereNotIn('id', $comRelacao)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn ($f) => ['id' => $f->id, 'nome' => $f->name]),
        ]);
    }

    /**
     * POST /federations/{federation}/guerra — declara guerra (A2.10).
     *
     * A regra inteira mora no domínio. Aqui só se traduz colônia e alvo.
     */
    public function declararGuerra(Request $request, Federation $federation): JsonResponse
    {
        $guerra = app(DeclararGuerra::class)
            ->handle($this->colonia($request), $federation);

        return response()->json([
            'guerra' => [
                'id' => $guerra->id,
                'termina_em' => $guerra->termina_em->toIso8601String(),
            ],
        ]);
    }

    /** POST /federation/neutralidade — declara-se neutra (A2.10, decisão 12). Imediato. */
    public function declararNeutralidade(Request $request): JsonResponse
    {
        $f = app(Neutralidade::class)->declarar($this->colonia($request));

        return response()->json(['neutra_desde' => $f->neutra_desde?->toIso8601String()]);
    }

    /** DELETE /federation/neutralidade — pede para sair. ⚠️ Só vale depois da carência. */
    public function encerrarNeutralidade(Request $request): JsonResponse
    {
        $f = app(Neutralidade::class)->encerrar($this->colonia($request));

        return response()->json(['termina_em' => $f->neutralidade_termina_em?->toIso8601String()]);
    }

    /** POST /federation/fundo — põe Fert$ no caixa comum. Qualquer membro pode. */
    public function contribuirParaOFundo(Request $request): JsonResponse
    {
        $dados = $request->validate(['fert' => ['required', 'numeric', 'min:0.01']]);

        $f = app(ContribuirParaOFundo::class)->handle(
            $this->colonia($request),
            (int) round($dados['fert'] * Colony::MICRO_POR_FERT),
        );

        return response()->json(['fundo_fert' => $f->fert_micro / Colony::MICRO_POR_FERT]);
    }

    /**
     * O estado de guerra da federação (A2.10).
     *
     * ⚠️ Traz o **custo agora**, e não o custo de tabela: um evento de mobilização pode tê-lo mudado,
     * e o jogador precisa ver o preço que vai pagar, não o que estava no manual.
     *
     * @return array<string,mixed>
     */
    private function mesaDeGuerra(Federation $federacao): array
    {
        /*
         * ⚠️ Relê a federação, e não confia na relação que veio do usuário.
         *
         * `$request->user()->colony->federation` é carregado uma vez e fica em memória; qualquer
         * coisa que mude a federação **depois** desse carregamento — declarar neutralidade, receber
         * uma declaração de guerra — não apareceria aqui. Em produção cada requisição reconstrói o
         * usuário e o problema não dá as caras; num teste com `actingAs`, e em qualquer caminho que
         * reaproveite o modelo, dá.
         *
         * Uma consulta a mais no caminho de leitura vale menos que uma tela que mostra o estado
         * anterior sem avisar.
         */
        $federacao = Federation::whereKey($federacao->id)->firstOrFail();

        $declarar = app(DeclararGuerra::class);
        $custo = $declarar->custo();
        $agora = now();

        $emGuerra = FederationWar::with(['declarante:id,name', 'alvo:id,name'])
            ->where('status', 'ativa')
            ->where(fn ($q) => $q->where('declarante_id', $federacao->id)->orWhere('alvo_id', $federacao->id))
            ->get();

        $neutralidade = app(Neutralidade::class);

        return [
            'tregua' => app(Modificadores::class)->guerraBloqueada(null, $agora),

            /*
             * A neutralidade da PRÓPRIA federação. `saindo_em` preenchido significa carência em
             * curso: ainda protegida, e já com data para deixar de estar.
             */
            'neutra' => $neutralidade->vigente($federacao, $agora),
            'saindo_em' => $federacao->neutralidade_termina_em?->toIso8601String(),
            'custo_fert' => $custo['fert'] / Colony::MICRO_POR_FERT,
            'custo_niobio' => $custo['niobio'],
            'carencia_horas' => (int) WarSetting::singleton()->neutralidade_carencia_horas,
            'capitulacao_fert' => (int) WarSetting::singleton()->capitulacao_fert_micro / Colony::MICRO_POR_FERT,
            'em_guerra_com' => $emGuerra->map(function (FederationWar $g) use ($federacao) {
                $outraId = (int) ($g->declarante_id === $federacao->id ? $g->alvo_id : $g->declarante_id);

                return [
                    'id' => $outraId,
                    'nome' => (int) $g->declarante_id === $federacao->id ? $g->alvo?->name : $g->declarante?->name,
                    'eu_declarei' => (int) $g->declarante_id === $federacao->id,
                    'termina_em' => $g->termina_em->toIso8601String(),

                    /*
                     * A2.10, decisões 8 e 9: as duas saídas antecipadas. `war_id` sai daqui porque
                     * é ele que as rotas pedem — sem o id, a tela teria de descobrir qual guerra é
                     * a "minha com aquela federação", que é uma pergunta que o servidor já
                     * respondeu ao montar esta lista.
                     */
                    'war_id' => $g->id,
                    'capitulacao' => $this->proposta($g->id, FederationWarProposal::CAPITULACAO, $federacao->id),
                    'tratado' => $this->proposta($g->id, FederationWarProposal::TRATADO, $federacao->id),

                    /*
                     * As zonas do inimigo — o cardápio de quem vai escolher o espólio. Só as que a
                     * capitulação pode de facto levar: zona sob combate não se entrega (ver
                     * `Capitulacao::tomarZona`), e oferecê-la na tela seria oferecer um botão que a
                     * regra recusaria.
                     */
                    'zonas_do_inimigo' => NeutralZone::whereIn(
                        'owner_colony_id',
                        Colony::where('federation_id', $outraId)->pluck('id'),
                    )
                        ->whereNull('sieged_at')
                        ->orderBy('id')
                        ->get(['id', 'name', 'x', 'y', 'mineral', 'level'])
                        ->map(fn (NeutralZone $z) => [
                            'id' => $z->id,
                            'nome' => $z->name ?? "({$z->x}, {$z->y})",
                            'mineral' => $z->mineral,
                            'nivel' => $z->level,
                        ])->values(),
                ];
            })->values(),
        ];
    }

    /**
     * O estado de uma proposta de fim de guerra, do ponto de vista de QUEM PERGUNTA.
     *
     * `de_mim` é o que decide o que a tela oferece: quem propôs pode retirar, quem recebeu pode
     * responder. Sem isso o painel mostraria os dois botões aos dois lados, e metade dos cliques
     * levaria a um 422.
     *
     * @return array{pendente: bool, de_mim: bool}
     */
    private function proposta(int $warId, string $tipo, int $minhaFederacaoId): array
    {
        $p = FederationWarProposal::where('war_id', $warId)
            ->where('tipo', $tipo)
            ->where('status', 'pendente')
            ->first();

        return [
            'pendente' => $p !== null,
            'de_mim' => $p !== null && (int) $p->proponente_federation_id === $minhaFederacaoId,
        ];
    }

    /**
     * POST /federation/wars/{war}/capitulacao — quem quer sair da guerra oferece a rendição.
     *
     * ⚠️ Sem preço no corpo, e é a decisão 9: **o vencedor escolhe**. Aceitar um preço vindo de
     * quem se rende seria deixar o derrotado arbitrar a própria derrota.
     */
    public function proporCapitulacao(Request $request, FederationWar $war): JsonResponse
    {
        app(Capitulacao::class)->propor($this->colonia($request), $war->id);

        return response()->json(['proposta' => true]);
    }

    /** POST /federation/wars/{war}/capitulacao/accept — o vencedor escolhe o espólio e a guerra acaba. */
    public function aceitarCapitulacao(Request $request, FederationWar $war): JsonResponse
    {
        $dados = $request->validate([
            'preco' => ['required', Rule::in(['zona', 'fert'])],
            'zone_id' => ['required_if:preco,zona', 'nullable', 'integer'],
        ]);

        app(Capitulacao::class)->aceitar(
            $this->colonia($request),
            $war->id,
            $dados['preco'],
            $dados['zone_id'] ?? null,
        );

        return response()->json(['encerrada' => true]);
    }

    /** DELETE /federation/wars/{war}/capitulacao — quem se rendeu volta atrás, se ainda dá. */
    public function retirarCapitulacao(Request $request, FederationWar $war): JsonResponse
    {
        app(Capitulacao::class)->retirar($this->colonia($request), $war->id);

        return response()->json(['retirada' => true]);
    }

    /** POST /federation/wars/{war}/tratado — propõe paz. Qualquer um dos dois lados. */
    public function proporTratado(Request $request, FederationWar $war): JsonResponse
    {
        app(TratadoDePaz::class)->propor($this->colonia($request), $war->id);

        return response()->json(['proposta' => true]);
    }

    /** POST /federation/wars/{war}/tratado/accept — o outro lado aceita: acaba sem espólio. */
    public function aceitarTratado(Request $request, FederationWar $war): JsonResponse
    {
        app(TratadoDePaz::class)->aceitar($this->colonia($request), $war->id);

        return response()->json(['encerrada' => true]);
    }

    /** POST /federation/wars/{war}/tratado/reject — recusa. A guerra continua. */
    public function recusarTratado(Request $request, FederationWar $war): JsonResponse
    {
        app(TratadoDePaz::class)->recusar($this->colonia($request), $war->id);

        return response()->json(['recusada' => true]);
    }

    /** DELETE /federation/wars/{war}/tratado — tira a própria proposta da mesa. */
    public function retirarTratado(Request $request, FederationWar $war): JsonResponse
    {
        app(TratadoDePaz::class)->retirar($this->colonia($request), $war->id);

        return response()->json(['retirada' => true]);
    }

    /** POST /federations/{federation}/alianca — propõe. */
    public function proporAlianca(Request $request, Federation $federation): JsonResponse
    {
        $this->diplomacia->propor($this->colonia($request), $federation);

        return response()->json(['proposta' => true]);
    }

    /** POST /federations/{federation}/alianca/accept — aceita a proposta da outra. */
    public function aceitarAlianca(Request $request, Federation $federation): JsonResponse
    {
        $this->diplomacia->aceitar($this->colonia($request), $federation);

        return response()->json(['aliada' => true]);
    }

    /** DELETE /federations/{federation}/alianca — rompe a aliança, ou recusa a proposta. */
    public function romperAlianca(Request $request, Federation $federation): JsonResponse
    {
        $this->diplomacia->romper($this->colonia($request), $federation);

        return response()->json(['rompida' => true]);
    }

    public function index(): JsonResponse
    {
        $federacoes = Federation::whereNull('disbanded_at')
            ->withCount('membros')
            ->orderBy('name')
            ->get()
            ->map(fn (Federation $f) => [
                'id' => $f->id,
                'name' => $f->name,
                'membros' => $f->membros_count,
                'cheia' => $f->membros_count >= Federation::MAX_COLONIAS,
            ]);

        return response()->json($federacoes);
    }

    public function store(Request $request): JsonResponse
    {
        $dados = $request->validate(['name' => ['required', 'string', 'max:60']]);

        $federation = $this->criar->handle($this->colonia($request), $dados['name']);

        return response()->json(['id' => $federation->id, 'name' => $federation->name], 201);
    }

    public function convidar(Request $request, Federation $federation): JsonResponse
    {
        $dados = $request->validate(['colony_id' => ['required', 'integer', 'exists:colonies,id']]);

        $alvo = Colony::findOrFail($dados['colony_id']);
        $invite = $this->enviar->convidar($this->colonia($request), $federation, $alvo);

        return response()->json(['id' => $invite->id], 201);
    }

    public function pedir(Request $request, Federation $federation): JsonResponse
    {
        $invite = $this->enviar->pedir($this->colonia($request), $federation);

        return response()->json(['id' => $invite->id], 201);
    }

    public function aceitar(Request $request, FederationInvite $invite): JsonResponse
    {
        $this->responder->aceitar($invite, $this->colonia($request));

        return response()->json(['ok' => true]);
    }

    public function recusar(Request $request, FederationInvite $invite): JsonResponse
    {
        $this->responder->recusar($invite, $this->colonia($request));

        return response()->json(['ok' => true]);
    }

    public function cancelarConvite(Request $request, FederationInvite $invite): JsonResponse
    {
        $this->responder->cancelar($invite, $this->colonia($request));

        return response()->json(['ok' => true]);
    }

    /**
     * A exigência da palavra vive AQUI, e não só na tela (D-121, mesmo padrão do `Demolir`, D-59):
     * uma confirmação só em React protege contra o dedo escorregando, e nada mais. Quem chamar a
     * API direto sai sem digitar nada, se a porta de verdade não perguntar.
     */
    public function sair(Request $request): JsonResponse
    {
        $confirmacao = (string) $request->input('confirmacao');

        if ($confirmacao !== SairDaFederacao::PALAVRA) {
            throw new DomainRuleException(
                'confirmacao_invalida',
                'Para sair, escreva '.SairDaFederacao::PALAVRA.'.',
            );
        }

        $this->sair->handle($this->colonia($request));

        return response()->json(['ok' => true]);
    }

    public function transferirLideranca(Request $request): JsonResponse
    {
        $dados = $request->validate(['colony_id' => ['required', 'integer', 'exists:colonies,id']]);

        $this->transferir->handle($this->colonia($request), Colony::findOrFail($dados['colony_id']));

        return response()->json(['ok' => true]);
    }

    public function expulsar(Request $request, Colony $colony): JsonResponse
    {
        $this->expulsar->handle($this->colonia($request), $colony);

        return response()->json(['ok' => true]);
    }

    public function alterarCargo(Request $request, Colony $colony): JsonResponse
    {
        $dados = $request->validate(['role' => ['required', 'string', Rule::in([
            Federation::DIPLOMATA, Federation::INTENDENTE, Federation::MEMBRO,
        ])]]);

        $this->alterarCargo->handle($this->colonia($request), $colony, $dados['role']);

        return response()->json(['ok' => true]);
    }

    public function sacar(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'resource_type' => ['required', 'string'],
            'amount' => ['required', 'integer', 'min:1'],
        ]);

        $this->sacar->handle($this->colonia($request), $dados['resource_type'], $dados['amount']);

        return response()->json(['ok' => true]);
    }

    private function invitePayload(FederationInvite $i): array
    {
        return [
            'id' => $i->id,
            'kind' => $i->kind,
            'federation' => $i->relationLoaded('federation') && $i->federation
                ? ['id' => $i->federation->id, 'name' => $i->federation->name] : null,
            'colony' => $i->relationLoaded('colony') && $i->colony
                ? ['id' => $i->colony->id, 'name' => $i->colony->name] : null,
            'created_by_colony_id' => $i->created_by_colony_id,
            'created_at' => $i->created_at,
        ];
    }

    private function colonia(Request $request): Colony
    {
        $colony = $request->user()->colony;

        if (! $colony) {
            throw new DomainRuleException('sem_colonia', 'Funde uma colônia primeiro.');
        }

        return $colony;
    }
}
