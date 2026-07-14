<?php

namespace App\Http\Controllers\Api;

use App\Domain\Logistics\MapaFertways;
use App\Http\Controllers\Controller;
use App\Models\NeutralZone;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * O card de "quem é esse colono" — aberto do Chat privado e do diretório de colônias (D-81).
 *
 * **Mesma régua de privacidade do diretório** (`ColonyController::index`, D-37): nome da colônia,
 * posição, distância, porte e as zonas que ele ocupa — tudo já público hoje em algum lugar do jogo
 * (o diretório mostra a colônia; `GET /zones` mostra o dono de cada zona). Nada de recursos, saldo,
 * frota ou reputação — isso nunca é exposto a terceiros em lugar nenhum do jogo, nem aqui.
 *
 * As zonas trazem só os campos já públicos em `NeutralZoneController::index` (posição, distrito,
 * mineral, nível). Guarnição e depósito ficam de fora: a névoa do Drone (D-74) protege isso para
 * QUALQUER olhar de fora, e este card não é exceção.
 */
class PlayerController extends Controller
{
    public function info(Request $request, User $user): JsonResponse
    {
        $minha = $request->user()->colony()->first();
        $colony = $user->colony()->first();

        return response()->json([
            'id' => $user->id,
            'nickname' => $user->nickname,
            'colony' => $colony ? [
                'id' => $colony->id,
                'name' => $colony->name,
                'x' => $colony->x,
                'y' => $colony->y,
                'distance' => $minha ? MapaFertways::distancia($minha->x, $minha->y, $colony->x, $colony->y) : null,
                'building_levels_sum' => (int) $colony->buildings()->sum('level'),
            ] : null,
            'zones' => $colony
                ? NeutralZone::where('owner_colony_id', $colony->id)
                    ->orderBy('id')
                    ->get(['id', 'name', 'x', 'y', 'district', 'mineral', 'level', 'status'])
                : [],
        ]);
    }
}
