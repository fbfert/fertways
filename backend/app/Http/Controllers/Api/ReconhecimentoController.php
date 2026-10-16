<?php

namespace App\Http\Controllers\Api;

use App\Domain\Missoes\Janela;
use App\Domain\Reconhecimento\Contribuir;
use App\Domain\Reconhecimento\Fases;
use App\Domain\Reconhecimento\Gagarin;
use App\Exceptions\DomainRuleException;
use App\Http\Controllers\Controller;
use App\Models\Colony;
use App\Models\MissaoDeReconhecimento;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * O reconhecimento do lado do colono (D-282): o Gagarin, as missões em curso, as luas e o Diário.
 *
 * Mostra só o que é público. O alvo de uma missão planetária não aparece pelo nome até a rota abrir;
 * a assinatura do resultado, sim, desde o primeiro minuto.
 */
class ReconhecimentoController extends Controller
{
    /** GET /central/reconhecimento */
    public function index(Request $request, Gagarin $gagarin): JsonResponse
    {
        $colonia = $this->colonia($request);
        $config = $gagarin->config();
        $dia = Janela::diaAtual()->toDateString();
        $minhasHoje = DB::table('contribuicoes_de_reconhecimento')
            ->where('colony_id', $colonia->id)->where('dia', $dia)->pluck('tarefas', 'missao_id');

        $missoes = MissaoDeReconhecimento::orderByDesc('id')->limit(10)->get()->map(function (MissaoDeReconhecimento $m) use ($minhasHoje) {
            $revelada = $m->concluida_em !== null
                || $m->tipo === MissaoDeReconhecimento::LUNAR
                || in_array($m->fase, ['concluida'], true);

            return [
                'id' => $m->id,
                'tipo' => $m->tipo,
                'setor' => $m->setor,
                'anomalia' => Fases::ANOMALIAS[$m->anomalia] ?? $m->anomalia,
                'faixa' => $m->faixa,
                'fase' => $m->fase,
                'fase_nome' => Fases::nome($m->fase),
                'fase_ate' => $m->fase_ate?->toIso8601String(),
                'tarefas' => $m->tarefas,
                'meta_de_tarefas' => $m->meta_de_tarefas,
                'pede_meta' => in_array($m->fase, Gagarin::COM_META, true),
                'aceita_tarefa' => $m->concluida_em === null && in_array($m->fase, Gagarin::COM_TAREFA, true),
                'minhas_tarefas_hoje' => (int) ($minhasHoje[$m->id] ?? 0),
                'assinatura' => $m->assinatura,
                'alvo_nome' => $revelada ? ($m->resultado['nome'] ?? null) : null,
                'concluida_em' => $m->concluida_em?->toIso8601String(),
            ];
        });

        return response()->json([
            'gagarin' => [
                'ativo' => $gagarin->ativo(),
                'ativado_em' => $config->ativado_em,
                'pausado' => $gagarin->pausado(),
                'proximo_boletim_em' => $gagarin->proximoBoletim()?->toIso8601String(),
            ] + $gagarin->contagem(now()),
            'missoes' => $missoes,
            'luas' => DB::table('luas')->orderBy('ordem')->get()->map(fn ($l) => [
                'chave' => $l->chave,
                'nome' => $l->nome,
                'ordem' => (int) $l->ordem,
                'revelada' => $l->revelada_em !== null,
                // Só a lua revelada mostra a ficha; as outras são só o nome do pioneiro.
                'recurso' => $l->revelada_em ? $l->recurso : null,
                'primeira_revelacao' => $l->revelada_em ? $l->primeira_revelacao : null,
                'gancho' => $l->revelada_em ? $l->gancho : null,
            ]),
            'tarefas_por_dia' => (int) $config->tarefas_por_dia,
            'tarefas_hoje' => (int) $minhasHoje->sum(),
            'xp_por_tarefa' => (int) $config->xp_por_tarefa,
            'tem_laboratorio' => (int) $colonia->buildings()->where('type', 'laboratorio')->max('level') >= 1,
            'diario' => DB::table('diario_do_colono')->where('colony_id', $colonia->id)
                ->orderByDesc('id')->limit(20)->get(['id', 'titulo', 'corpo', 'created_at']),
        ]);
    }

    /** POST /central/reconhecimento/{missao}/tarefa — uma tarefa de pesquisa. */
    public function tarefa(Request $request, MissaoDeReconhecimento $missao, Contribuir $contribuir): JsonResponse
    {
        $m = $contribuir->handle($this->colonia($request), $missao);

        return response()->json(['tarefas' => $m->tarefas, 'meta_de_tarefas' => $m->meta_de_tarefas], 201);
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
