<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Admin\CorrigirEstado;
use App\Domain\Admin\RealocarColonia;
use App\Domain\Admin\Suspender;
use App\Domain\Building\Funcoes;
use App\Domain\Ministry\PunicaoSpecs;
use App\Domain\Transport\Conservacao;
use App\Domain\Transport\Ministerio;
use App\Domain\Treasury\Tesouro;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\AuditEntry;
use App\Models\BuildQueue;
use App\Models\Colony;
use App\Models\Ledger;
use App\Models\NeutralZone;
use App\Models\News;
use App\Models\PriceIntervention;
use App\Models\Punishment;
use App\Models\Report;
use App\Models\ResourceType;
use App\Models\TradeAgreement;
use App\Models\TransportSetting;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleListing;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * As telas do painel de administração — **só leitura**. As ações vivem no `AcoesController`.
 *
 * **Quebrado em seções desde o D-61.** Era uma página só, e ela não aguentaria o que o usuário pediu:
 * o CRUD de jogadores e um log de auditoria que cresce sem parar dominariam tudo o mais. Continua
 * Blade por sessão, mesma estética, sem SPA — o que mudou foi a navegação, não a natureza.
 */
class PainelController extends Controller
{
    /** Visão geral: os números de topo e o que exige atenção agora. */
    public function dashboard(): View
    {
        return view('admin.dashboard', [
            'resumo' => $this->resumo(),
            'colonias' => Colony::with('user:id,nickname')->orderBy('id')->get(),
            'obras' => BuildQueue::query()->ativos()->with('colony:id,name')->orderBy('finishes_at')->get(),
            'zonas' => NeutralZone::with('owner:id,name')->whereNotNull('owner_colony_id')->orderBy('id')->get(),
            // O que aconteceu no painel ultimamente. É o primeiro lugar onde se olha quando algo
            // parece errado, e por isso vem na visão geral em vez de só na aba da auditoria.
            'ultimosAtos' => AuditEntry::orderByDesc('id')->limit(8)->get(),
        ]);
    }

    // ─────────────────────────────────────────────────────────── Jogadores

    /** A lista, com a busca global do topo. */
    public function jogadores(Request $request): View
    {
        $q = trim((string) $request->query('q'));

        $jogadores = User::query()
            ->with('colony')
            ->when($q !== '', function ($query) use ($q) {
                /*
                 * A busca aceita as quatro coisas que alguém tem à mão quando reclama de algo: o
                 * nome, o e-mail, o nome da colônia — e a **placa de um veículo**, que é o único
                 * identificador que aparece na tela de outro jogador.
                 */
                $query->where(fn ($w) => $w
                    ->where('name', 'like', "%{$q}%")
                    ->orWhere('nickname', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%")
                    ->orWhereHas('colony', fn ($c) => $c->where('name', 'like', "%{$q}%"))
                    ->orWhereHas('colony.vehicles', fn ($v) => $v->where('plate', 'like', "%{$q}%")),
                );
            })
            ->orderBy('id')
            ->paginate(30)
            ->withQueryString();

        return view('admin.jogadores', ['jogadores' => $jogadores, 'q' => $q]);
    }

    /** A ficha completa de um jogador. É o coração do CRUD (D-61). */
    public function jogador(User $user): View
    {
        $colony = $user->colony;

        return view('admin.jogador', [
            'jogador' => $user,
            'colonia' => $colony,
            'suspenso' => Suspender::estaSuspenso($user),
            'indices' => CorrigirEstado::INDICES,

            'recursos' => $colony
                ? $colony->resources()->join('resource_types', 'resources.resource_type', '=', 'resource_types.code')
                    ->orderBy('resource_types.tax_class')->orderBy('resource_types.nome')
                    ->get(['resources.resource_type as code', 'resource_types.nome', 'resources.amount'])
                : collect(),

            'construcoes' => $colony
                ? $colony->buildings()->orderBy('slot')->get()->map(fn ($b) => [
                    'slot' => $b->slot,
                    'type' => $b->type,
                    'nome' => Funcoes::de($b->type)['frase'],
                    'level' => $b->level,
                ])
                : collect(),

            'frota' => $colony ? $colony->vehicles()->orderBy('id')->get() : collect(),
            'conservacao' => app(Conservacao::class),

            'punicoes' => $colony ? Punishment::where('user_id', $user->id)->orderByDesc('id')->limit(10)->get() : collect(),
            'denuncias' => Report::where('reporter_id', $user->id)->orWhere('accused_id', $user->id)
                ->orderByDesc('id')->limit(10)->get(),
            'acordos' => $colony
                ? TradeAgreement::where('colony_a_id', $colony->id)->orWhere('colony_b_id', $colony->id)
                    ->orderByDesc('id')->limit(10)->get()
                : collect(),

            // O extrato: é ele que explica de onde veio cada unidade — inclusive os `ajuste_admin`
            // que este mesmo painel lança quando o operador corrige alguma coisa (D-61).
            'ledger' => $colony
                ? Ledger::where('colony_id', $colony->id)->orderByDesc('id')->limit(40)->get()
                : collect(),

            // O que este jogador já sofreu do painel. Fecha o círculo: a ficha mostra o rastro
            // administrativo dele sem que se precise garimpar a aba da auditoria.
            'auditoria' => AuditEntry::where('alvo', "user:{$user->id}")
                ->when($colony, fn ($q) => $q->orWhere('alvo', "colony:{$colony->id}"))
                ->orderByDesc('id')->limit(20)->get(),

            'avisosRealocacao' => $colony ? app(RealocarColonia::class)->avisos($colony, 0, 0) : [],
        ]);
    }

    // ─────────────────────────────────────────────────────────── Seções do jogo

    public function ministerio(): View
    {
        return view('admin.ministerio', [
            'conciliadores' => User::whereNotNull('conciliador_desde')->orderBy('id')->get(),
            'jogadores' => User::orderBy('id')->get(),
            'filaEquipe' => Report::with(['reporter:id,name', 'accused:id,name'])
                ->whereIn('status', ['na_equipe', 'apelado'])->orderBy('id')->get(),
            'atribuidos' => Report::with(['reporter:id,name', 'accused:id,name', 'conciliator:id,nickname'])
                ->where('status', 'atribuido')->orderBy('deadline_at')->get(),
            'emApelacao' => Report::where('status', 'decidido')->orderBy('appeal_until')->get(),
            'especs' => PunicaoSpecs::class,
        ]);
    }

    public function economia(): View
    {
        return view('admin.economia', [
            'colonias' => Colony::orderBy('id')->get(),
            'recursos' => ResourceType::orderBy('tax_class')->orderBy('nome')->get(['code', 'nome', 'tax_class']),
            'intervencoes' => PriceIntervention::query()->vigentes()->orderBy('resource_type')->get(),
            'tesouro' => DB::table('treasury_holdings')
                ->join('resource_types', 'treasury_holdings.resource_type', '=', 'resource_types.code')
                ->orderBy('resource_types.tax_class')->orderBy('resource_types.nome')
                ->get(['treasury_holdings.resource_type as code', 'resource_types.nome', 'treasury_holdings.amount']),
            'tesouroFert' => app(Tesouro::class)->saldoFertMicro(),
            'FERT' => Tesouro::FERT,
            'noticias' => News::orderByDesc('published_at')->orderByDesc('id')->limit(10)->get(),
        ]);
    }

    public function transportes(): View
    {
        return view('admin.transportes', [
            'transporte' => TransportSetting::singleton(),
            'frotaGoverno' => [
                'estoque' => Vehicle::whereNull('colony_id')->where('status', 'estoque')->count(),
                'fabricando' => Vehicle::whereNull('colony_id')->where('status', 'fabricando')->count(),
                'alvo' => Ministerio::ESTOQUE_ALVO,
            ],
            'volumeVeiculos' => [
                'registrados' => Vehicle::whereNotNull('plate')->count(),
                'em_rota' => Vehicle::where('status', 'em_rota')->count(),
                'anunciados' => VehicleListing::where('status', 'aberto')->count(),
                'vendidos' => VehicleListing::where('status', 'concluido')->count(),
                'sucateados' => Vehicle::onlyTrashed()->count(),
                'sucateados_7d' => Vehicle::onlyTrashed()->where('deleted_at', '>=', now()->subDays(7))->count(),
                'vendidos_7d' => VehicleListing::where('status', 'concluido')
                    ->where('updated_at', '>=', now()->subDays(7))->count(),
            ],
        ]);
    }

    public function operacao(): View
    {
        return view('admin.operacao', [
            'resumo' => $this->resumo(),
        ]);
    }

    // ─────────────────────────────────────────────────────────── Auditoria e admins

    /**
     * O log de auditoria (D-61), com filtros.
     *
     * Ele existe para responder à pergunta que o `ledger` não responde: **quem, do lado de dentro,
     * fez isto?** Append-only — nem o admin apaga.
     */
    public function auditoria(Request $request): View
    {
        $acao = (string) $request->query('acao', '');
        $adminId = (string) $request->query('admin', '');
        $alvo = trim((string) $request->query('alvo', ''));

        $entradas = AuditEntry::query()
            ->with('admin:id,name,email')
            ->when($acao !== '', fn ($q) => $q->where('acao', $acao))
            ->when($adminId !== '', fn ($q) => $q->where('admin_id', $adminId))
            ->when($alvo !== '', fn ($q) => $q->where('alvo', 'like', "%{$alvo}%"))
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        return view('admin.auditoria', [
            'entradas' => $entradas,
            'acoes' => AuditEntry::select('acao')->distinct()->orderBy('acao')->pluck('acao'),
            'admins' => Admin::orderBy('name')->get(['id', 'name', 'email']),
            'filtro' => ['acao' => $acao, 'admin' => $adminId, 'alvo' => $alvo],
        ]);
    }

    /** O CRUD de contas de admin. Só o dono chega aqui — a rota tem o middleware `dono`. */
    public function admins(): View
    {
        return view('admin.admins', [
            'admins' => Admin::orderBy('id')->get(),
            'papeis' => Admin::PAPEIS,
        ]);
    }

    /** Números de topo. */
    private function resumo(): array
    {
        return [
            'colonias' => Colony::count(),
            'jogadores' => User::count(),
            'suspensos' => User::whereNotNull('suspenso_em')
                ->where(fn ($q) => $q->whereNull('suspenso_ate')->orWhere('suspenso_ate', '>', now()))
                ->count(),
            'admins' => Admin::whereNull('desativado_em')->count(),
            'fert_em_circulacao_micro' => (int) Colony::sum('fert_micro'),
            'casos_na_equipe' => Report::whereIn('status', ['na_equipe', 'apelado'])->count(),
            'ordens_abertas' => DB::table('market_orders')->whereIn('status', ['aberta', 'parcial'])->count(),
            'veiculos_em_rota' => Vehicle::where('status', 'em_rota')->count(),
            'veiculos_ociosos' => Vehicle::where('status', 'ocioso')->count(),
            'zonas_ocupadas' => NeutralZone::whereNotNull('owner_colony_id')->count(),
        ];
    }
}
