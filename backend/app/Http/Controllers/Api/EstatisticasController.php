<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Building;
use App\Models\Colony;
use App\Models\Ledger;
use App\Models\NeutralZone;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;

/**
 * Estatísticas públicas do planeta (pedido do usuário, 2026-07-17): números reais para a landing
 * page, lidos ao vivo do banco — a mesma regra de ouro do ledger e do GDD, aplicada aqui: nenhum
 * número é decorativo ou inventado, e a página que mostra "estatísticas reais" tem de ser real.
 *
 * Sem autenticação — é a mesma exceção do `/register` e do `/login`: quem visita antes de ter
 * conta também pode ver quanto o mundo já anda.
 */
class EstatisticasController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            // "Capital" é conta de sistema (D-91), não é gente.
            'colonos' => User::where('email', '!=', \App\Domain\Chat\ContaSistema::EMAIL_CAPITAL)->count(),
            'colonias' => Colony::count(),
            'fert_em_circulacao_micro' => (int) Colony::sum('fert_micro'),
            'construcoes_erguidas' => Building::count(),
            'veiculos_registrados' => Vehicle::whereNotNull('plate')->count(),
            'zonas_ocupadas' => NeutralZone::whereNotNull('owner_colony_id')->count(),
            // O ledger é append-only (a regra de ouro, §3.3 do GDD): cada linha é um fato
            // econômico de verdade. Contá-las é medir quanto o mundo já se moveu.
            'lancamentos_no_ledger' => Ledger::count(),
        ]);
    }
}
