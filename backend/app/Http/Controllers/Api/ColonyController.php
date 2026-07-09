<?php

namespace App\Http\Controllers\Api;

use App\Domain\Colony\CreateColony;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ColonyController extends Controller
{
    public function store(Request $request, CreateColony $criar): JsonResponse
    {
        $dados = $request->validate([
            'name' => ['required', 'string', 'min:3', 'max:60'],
        ]);

        $user = $request->user();

        // Uma colônia por jogador no MVP. A UNIQUE em colonies.user_id é a garantia real;
        // isto só devolve 422 em vez de deixar estourar a violação de constraint.
        if ($user->colony()->exists()) {
            return response()->json([
                'message' => 'Este colono já fundou uma colônia.',
            ], 422);
        }

        $colony = $criar->handle($user, $dados['name']);

        return response()->json([
            'id' => $colony->id,
            'name' => $colony->name,
            'milestone' => $colony->milestone,
            'founded_at' => $colony->founded_at,
            'fert' => $colony->fert_micro / 1_000_000,
            'buildings' => $colony->buildings->map(fn ($b) => [
                'type' => $b->type,
                'level' => $b->level,
            ]),
            'resources' => $colony->resources->pluck('amount', 'resource_type'),
            'vehicles' => $colony->vehicles->map(fn ($v) => [
                'type' => $v->type,
                'level' => $v->level,
                'capacity' => $v->capacity,
                'status' => $v->status,
            ]),
        ], 201);
    }

    public function show(Request $request): JsonResponse
    {
        $colony = $request->user()->colony()->with(['buildings', 'resources', 'vehicles'])->first();

        if (! $colony) {
            return response()->json(['message' => 'Nenhuma colônia fundada.'], 404);
        }

        return response()->json([
            'id' => $colony->id,
            'name' => $colony->name,
            'fert' => $colony->fert_micro / 1_000_000,
            'last_tick_at' => $colony->last_tick_at,
            'buildings' => $colony->buildings->map(fn ($b) => ['type' => $b->type, 'level' => $b->level]),
            'resources' => $colony->resources->pluck('amount', 'resource_type'),
        ]);
    }
}
