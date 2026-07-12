<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Ministry\PunicaoSpecs;
use App\Domain\Treasury\Tesouro;
use App\Http\Controllers\Controller;
use App\Models\BuildQueue;
use App\Models\Colony;
use App\Models\NeutralZone;
use App\Models\News;
use App\Models\PriceIntervention;
use App\Models\Report;
use App\Models\ResourceType;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * O dashboard do painel de administração: o estado do jogo numa tela, e os dados que as ações
 * precisam (colonos para nomear, recursos para intervir). Só leitura — as ações vivem no AcoesController.
 */
class PainelController extends Controller
{
    public function dashboard(): View
    {
        return view('admin.dashboard', [
            'resumo' => $this->resumo(),
            'colonias' => Colony::with('user:id,nickname')->orderBy('id')->get(),
            'jogadores' => User::orderBy('id')->get(),
            'conciliadores' => User::whereNotNull('conciliador_desde')->orderBy('id')->get(),
            'filaEquipe' => Report::with(['reporter:id,name', 'accused:id,name'])
                ->whereIn('status', ['na_equipe', 'apelado'])->orderBy('id')->get(),
            'atribuidos' => Report::with(['reporter:id,name', 'accused:id,name', 'conciliator:id,nickname'])
                ->where('status', 'atribuido')->orderBy('deadline_at')->get(),
            'emApelacao' => Report::where('status', 'decidido')->orderBy('appeal_until')->get(),
            'intervencoes' => PriceIntervention::query()->vigentes()->orderBy('resource_type')->get(),
            'recursos' => ResourceType::orderBy('tax_class')->orderBy('nome')->get(['code', 'nome', 'tax_class']),
            'noticias' => News::orderByDesc('published_at')->orderByDesc('id')->limit(10)->get(),
            'zonas' => NeutralZone::with('owner:id,name')->whereNotNull('owner_colony_id')->orderBy('id')->get(),
            'obras' => BuildQueue::query()->ativos()->with('colony:id,name')->orderBy('finishes_at')->get(),
            'especs' => PunicaoSpecs::class,
            // Ministério do Tesouro (D-57): saldo do caixa (recursos com nome, e o Fert$).
            'tesouro' => DB::table('treasury_holdings')
                ->join('resource_types', 'treasury_holdings.resource_type', '=', 'resource_types.code')
                ->orderBy('resource_types.tax_class')->orderBy('resource_types.nome')
                ->get(['treasury_holdings.resource_type as code', 'resource_types.nome', 'treasury_holdings.amount']),
            'tesouroFert' => app(Tesouro::class)->saldoFertMicro(),
            'FERT' => Tesouro::FERT,

            /*
             * Ministério dos Transportes (D-60). O §16 dá ao painel do Ministério seis atribuições,
             * e **quatro delas são configurar números que o GDD nunca publica** — a curva de
             * depreciação, o limite crítico, a perda de vida útil e o teto de revenda. Foi isso que
             * permitiu tirar a depreciação da geladeira sem inventar constante no código: o próprio
             * documento diz de quem os números são.
             */
            'transporte' => \App\Models\TransportSetting::singleton(),
            'frotaGoverno' => [
                'estoque' => Vehicle::whereNull('colony_id')->where('status', 'estoque')->count(),
                'fabricando' => Vehicle::whereNull('colony_id')->where('status', 'fabricando')->count(),
                'alvo' => \App\Domain\Transport\Ministerio::ESTOQUE_ALVO,
            ],
            // A 6ª atribuição: "volume de veículos registrados, vendidos e sucateados por período".
            'volumeVeiculos' => [
                'registrados' => Vehicle::whereNotNull('plate')->count(),
                'em_rota' => Vehicle::where('status', 'em_rota')->count(),
                'anunciados' => \App\Models\VehicleListing::where('status', 'aberto')->count(),
                'vendidos' => \App\Models\VehicleListing::where('status', 'concluido')->count(),
                'sucateados' => Vehicle::onlyTrashed()->count(),
                'sucateados_7d' => Vehicle::onlyTrashed()->where('deleted_at', '>=', now()->subDays(7))->count(),
                'vendidos_7d' => \App\Models\VehicleListing::where('status', 'concluido')
                    ->where('updated_at', '>=', now()->subDays(7))->count(),
            ],
        ]);
    }

    /** Números de topo. */
    private function resumo(): array
    {
        return [
            'colonias' => Colony::count(),
            'jogadores' => User::count(),
            'admins' => DB::table('admins')->count(),
            'fert_em_circulacao_micro' => (int) Colony::sum('fert_micro'),
            'tesouro_fert_micro' => (int) DB::table('tax_events')->where('kind', 'mercado_venda')->sum('tax_amount'),
            'casos_na_equipe' => Report::whereIn('status', ['na_equipe', 'apelado'])->count(),
            'ordens_abertas' => DB::table('market_orders')->whereIn('status', ['aberta', 'parcial'])->count(),
            'veiculos_em_rota' => Vehicle::where('status', 'em_rota')->count(),
            'veiculos_ociosos' => Vehicle::where('status', 'ocioso')->count(),
            'zonas_ocupadas' => NeutralZone::whereNotNull('owner_colony_id')->count(),
        ];
    }
}
