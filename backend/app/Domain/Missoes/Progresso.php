<?php

namespace App\Domain\Missoes;

use App\Domain\Marco\ConcederXp;
use App\Models\Colony;
use App\Models\Ledger;
use App\Models\MissionAssignment;

/**
 * O progresso das missões (§06; D-78) — o ouvido que escuta os atos do jogo.
 *
 * Os MESMOS ganchos do XP (D-75) chamam aqui: obra concluída, despacho, mercado, acordo, zona,
 * combate… A pergunta "há missão ativa desta ação nesta colônia?" quase sempre responde não, e o
 * índice (colony_id, status, acao) a torna barata o bastante para viver no caminho quente.
 *
 * **Concluir PAGA na hora** — sem botão de resgate: a recompensa de missão é emissão publicada
 * (§06 lista "recompensas de missão" entre as entradas de Fert$), o ledger registra cada perna, e
 * o XP entra pelo ledger do Marco com o valor DO TEMPLATE (as missões pagam o que o catálogo diz,
 * não o que a tabela de atos do D-75 diz).
 */
class Progresso
{
    public function __construct(private readonly ConcederXp $xp) {}

    public function registrar(int $colonyId, string $acao, int $vezes = 1): void
    {
        $ativas = MissionAssignment::ativa()
            ->where('colony_id', $colonyId)
            ->where('acao', $acao)
            ->get();

        foreach ($ativas as $missao) {
            $missao->progresso = min($missao->meta, $missao->progresso + max(1, $vezes));

            if ($missao->progresso >= $missao->meta) {
                $missao->status = 'concluida';
                $missao->concluded_at = now();
                $missao->save();

                $this->pagar($missao);

                continue;
            }

            $missao->save();
        }
    }

    private function pagar(MissionAssignment $missao): void
    {
        $t = $missao->template;
        $ref = "missao:{$missao->id}:{$t->chave}";

        if ((int) $t->recompensa_fert_micro > 0) {
            Colony::whereKey($missao->colony_id)->increment('fert_micro', (int) $t->recompensa_fert_micro);

            Ledger::create([
                'colony_id' => $missao->colony_id,
                'type' => 'recompensa_missao',
                'amount' => (int) $t->recompensa_fert_micro,
                'resource_type' => null,
                'ref' => $ref,
                'created_at' => now(),
            ]);
        }

        foreach ($t->recompensa_recursos ?? [] as $recurso => $qtd) {
            Colony::find($missao->colony_id)->resources()
                ->where('resource_type', $recurso)->increment('amount', (int) $qtd);

            Ledger::create([
                'colony_id' => $missao->colony_id,
                'type' => 'recompensa_missao',
                'amount' => (int) $qtd,
                'resource_type' => $recurso,
                'ref' => $ref,
                'created_at' => now(),
            ]);
        }

        if ((int) $t->recompensa_xp > 0) {
            $this->xp->direto($missao->colony_id, 'missao_concluida', (int) $t->recompensa_xp, $ref);
        }
    }
}
