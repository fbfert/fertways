<?php

namespace App\Http\Controllers\Api;

use App\Domain\Missoes\Atribuir;
use App\Domain\Missoes\Janela;
use App\Http\Controllers\Controller;
use App\Models\MissionAssignment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * As Missões do §06 (D-78). A mão do dia nasce AQUI, no primeiro pedido da janela (lazy) —
 * e a rejeição publicada ("3 por dia, 1 rejeição") troca uma diária por outra do pool.
 */
class MissoesController extends Controller
{
    public function index(Request $request, Atribuir $atribuir): JsonResponse
    {
        $colony = $request->user()->colony()->firstOrFail();
        $missoes = $atribuir->garantir($colony);

        return response()->json([
            'missoes' => $missoes->map($this->linha(...)),
            'rejeicoes_restantes' => $this->rejeicoesRestantes($colony->id),
            'dia_vira_em' => Janela::proximoDia()->toIso8601String(),
            'semana_vira_em' => Janela::fimDaSemana()->toIso8601String(),
        ]);
    }

    /** A 1 rejeição diária do §06: fora vai a escolhida, entra outra do pool. */
    public function rejeitar(Request $request, MissionAssignment $assignment, Atribuir $atribuir): JsonResponse
    {
        $colony = $request->user()->colony()->firstOrFail();

        if ($assignment->colony_id !== $colony->id) {
            return response()->json(['code' => 'missao_alheia', 'message' => 'Esta missão não é sua.'], 422);
        }

        if ($assignment->categoria !== 'diaria' || $assignment->status !== 'ativa') {
            return response()->json(['code' => 'nao_rejeitavel', 'message' => 'Só se rejeita uma diária ainda ativa (§06).'], 422);
        }

        if ($this->rejeicoesRestantes($colony->id) < 1) {
            return response()->json(['code' => 'rejeicao_esgotada', 'message' => 'A rejeição de hoje já foi usada — amanhã tem outra (§06).'], 422);
        }

        $assignment->forceFill(['status' => 'rejeitada'])->save();
        $atribuir->sortear($colony, 'diaria', 1, Janela::proximoDia());

        return response()->json(['rejeitada' => $assignment->id], 200);
    }

    private function rejeicoesRestantes(int $colonyId): int
    {
        $usadas = MissionAssignment::where('colony_id', $colonyId)
            ->where('categoria', 'diaria')
            ->where('status', 'rejeitada')
            ->where('created_at', '>=', Janela::diaAtual())
            ->count();

        return max(0, 1 - $usadas);
    }

    private function linha(MissionAssignment $m): array
    {
        return [
            'id' => $m->id,
            'categoria' => $m->categoria,
            'titulo' => $m->template->titulo,
            'descricao' => $m->template->descricao,
            'progresso' => $m->progresso,
            'meta' => $m->meta,
            'status' => $m->status,
            'expira_em' => $m->expires_at?->toIso8601String(),
            'recompensa' => [
                'fert' => $m->template->recompensa_fert_micro / 1_000_000,
                'xp' => $m->template->recompensa_xp,
                'recursos' => $m->template->recompensa_recursos,
            ],
        ];
    }
}
