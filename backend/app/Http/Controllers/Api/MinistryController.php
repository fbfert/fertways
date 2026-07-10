<?php

namespace App\Http\Controllers\Api;

use App\Domain\Ministry\AbrirDenuncia;
use App\Domain\Ministry\Apelacao;
use App\Domain\Ministry\DecidirCaso;
use App\Domain\Ministry\PunicaoSpecs;
use App\Exceptions\DomainRuleException;
use App\Http\Controllers\Controller;
use App\Models\Colony;
use App\Models\Punishment;
use App\Models\Report;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Ministério das Reputações — GDD §9.1–9.4 e §26.6–26.8. Ver D-44, D-48, D-49 e D-50.
 *
 * O que **não** está aqui: a equipe do jogo. Ela é o operador, fora do jogo, e julga casos graves e
 * apelações por artisan (`fertways:equipe`). Expor isso como rota daria a um colono o poder de
 * suspender conciliadores.
 */
class MinistryController extends Controller
{
    /**
     * A vista do colono sobre o Ministério: seus quatro índices, suas punições vigentes, seu cargo,
     * e o catálogo de violações que ele pode denunciar.
     */
    public function eu(Request $request): JsonResponse
    {
        $usuario = $request->user();

        return response()->json([
            'reputacao' => [
                'confianca_comercial' => $usuario->confianca_comercial,
                'conduta_social' => $usuario->conduta_social,
                'status_civico' => $usuario->status_civico,
                'honra_militar_diplomatica' => $usuario->honra_militar_diplomatica,
            ],
            'limiar_mercado' => PunicaoSpecs::PERSONA_NON_GRATA,
            // §9.4: Persona Non Grata é o mesmo limiar que fecha a doca (D-49). Leilões não existem.
            'persona_non_grata' => $usuario->confianca_comercial < PunicaoSpecs::PERSONA_NON_GRATA,
            'conciliador' => [
                'nomeado' => $usuario->conciliador_desde !== null,
                'suspenso' => $usuario->conciliador_suspenso_em !== null,
                'reversoes' => $usuario->reversoes,
                'limite_reversoes' => PunicaoSpecs::LIMITE_REVERSOES,
                'salario_diario_micro' => PunicaoSpecs::SALARIO_DIARIO_MICRO,
                'bonus_micro' => PunicaoSpecs::BONUS_MICRO,
            ],
            'punicoes' => Punishment::vigente()
                ->where('user_id', $usuario->id)
                ->orderByDesc('id')
                ->get()
                ->map(fn (Punishment $p) => [
                    'kind' => $p->kind,
                    'index_name' => $p->index_name,
                    'points' => $p->points,
                    'expires_at' => $p->expires_at,
                ])->values(),
            'catalogo' => collect(PunicaoSpecs::VIOLACOES)->map(fn (array $v, string $chave) => [
                'violation' => $chave,
                'indice' => $v['indice'],
                'pontos' => $v['pontos'],
                'punicoes' => $v['punicoes'],
                'grave' => $v['grave'],
                'inerte' => $v['inerte'],
                'fonte' => $v['fonte'],
            ])->values(),
        ]);
    }

    /** As denúncias que me dizem respeito: as que fiz, as que sofri, e as que devo julgar. */
    public function index(Request $request): JsonResponse
    {
        $usuario = $request->user();
        $colonia = $this->colonia($request);

        $minhas = Report::where('reporter_colony_id', $colonia->id)
            ->orWhere('accused_colony_id', $colonia->id)
            ->orderByDesc('id')
            ->get();

        $aJulgar = $usuario->conciliador_desde
            ? Report::where('conciliator_user_id', $usuario->id)->where('status', 'atribuido')->orderBy('deadline_at')->get()
            : collect();

        return response()->json([
            'minhas' => $minhas->map(fn (Report $r) => $this->exibir($r, $colonia->id))->values(),
            'a_julgar' => $aJulgar->map(fn (Report $r) => $this->exibir($r, $colonia->id))->values(),
        ]);
    }

    public function store(Request $request, AbrirDenuncia $abrir): JsonResponse
    {
        $dados = $request->validate([
            'accused_colony_id' => ['required', 'integer'],
            'violation' => ['required', 'string'],
            'texto' => ['required', 'string', 'min:10', 'max:2000'],
            'evidence_type' => ['required', 'string'],
            'trade_agreement_id' => ['nullable', 'integer'],
        ]);

        $colonia = $this->colonia($request);
        $denunciado = Colony::find($dados['accused_colony_id']);

        if (! $denunciado) {
            throw new DomainRuleException('denunciado_inexistente', 'Colônia denunciada inexistente.');
        }

        $denuncia = $abrir->handle(
            $colonia,
            $denunciado,
            $dados['violation'],
            $dados['texto'],
            $dados['evidence_type'],
            $dados['trade_agreement_id'] ?? null,
        );

        return response()->json($this->exibir($denuncia, $colonia->id), 201);
    }

    /** Passo 4 do §9.2. O conciliador julga o fato; a pena está na tabela fixa do §26.8. */
    public function decidir(Request $request, Report $report, DecidirCaso $decidir): JsonResponse
    {
        $dados = $request->validate(['procedente' => ['required', 'boolean']]);

        $denuncia = $decidir->porConciliador($request->user(), $report, $dados['procedente']);

        return response()->json($this->exibir($denuncia, $this->colonia($request)->id));
    }

    /** §9.3: "decisões podem ser apeladas para a equipe do jogo em casos contestados". */
    public function apelar(Request $request, Report $report, Apelacao $apelacao): JsonResponse
    {
        $colonia = $this->colonia($request);

        return response()->json($this->exibir($apelacao->apelar($colonia, $report), $colonia->id));
    }

    private function exibir(Report $r, int $euId): array
    {
        $spec = PunicaoSpecs::violacao($r->violation);

        return [
            'id' => $r->id,
            'violation' => $r->violation,
            'fonte' => $spec['fonte'],
            'texto' => $r->texto,
            'evidence_type' => $r->evidence_type,
            'trade_agreement_id' => $r->trade_agreement_id,
            'status' => $r->status,
            'decision' => $r->decision,
            'grave' => $r->grave,
            'eu_denunciei' => $r->reporter_colony_id === $euId,
            'reporter_colony_id' => $r->reporter_colony_id,
            'accused_colony_id' => $r->accused_colony_id,
            'deadline_at' => $r->deadline_at,
            'decided_at' => $r->decided_at,
            'appeal_until' => $r->appeal_until,
            // O que a tabela do §26.8 aplicaria (ou aplicou) se procedente. A pena não é segredo.
            'punicao_tabelada' => ['indice' => $spec['indice'], 'pontos' => $spec['pontos'], 'punicoes' => $spec['punicoes']],
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
