<?php

namespace App\Http\Controllers\Api;

use App\Domain\Federacao\AlterarCargo;
use App\Domain\Federacao\CriarFederacao;
use App\Domain\Federacao\EnviarConviteOuPedido;
use App\Domain\Federacao\ExpulsarMembro;
use App\Domain\Federacao\ResponderConviteOuPedido;
use App\Domain\Federacao\SacarDoFundo;
use App\Domain\Federacao\SairDaFederacao;
use App\Domain\Federacao\TransferirLideranca;
use App\Exceptions\DomainRuleException;
use App\Http\Controllers\Controller;
use App\Models\Colony;
use App\Models\Federation;
use App\Models\FederationHolding;
use App\Models\FederationInvite;
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

    public function sair(Request $request): JsonResponse
    {
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
