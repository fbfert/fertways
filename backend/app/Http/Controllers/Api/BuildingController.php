<?php

namespace App\Http\Controllers\Api;

use App\Domain\Building\BuildingSpecs;
use App\Domain\Building\EnqueueUpgrade;
use App\Domain\Production\ColonyTick;
use App\Exceptions\DomainRuleException;
use App\Http\Controllers\Controller;
use App\Models\Building;
use App\Models\BuildQueue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BuildingController extends Controller
{
    public function upgrade(Request $request, Building $building, EnqueueUpgrade $enfileirar): JsonResponse
    {
        $colony = $request->user()->colony;

        if (! $colony) {
            throw new DomainRuleException('sem_colonia', 'Funde uma colônia primeiro.');
        }

        $item = $enfileirar->handle($colony, $building);

        return response()->json([
            'building' => $building->type,
            'target_level' => $item->target_level,
            'status' => $item->status,
            'position' => $item->position,
            // §24.7: "o custo aparece normalmente na interface, mas junto com a mensagem
            // 'Esta construção será custeada pelo Governo Central até o nível 3'".
            'cost' => $item->quoted_cost_json,
            'subsidized' => $item->subsidized,
            'subsidy_message' => $item->subsidized
                ? 'Esta construção será custeada pelo Governo Central até o nível 3'
                : null,
            'finishes_at' => $item->finishes_at,
        ], 201);
    }

    /**
     * Escolhe a receita de Componentes Eletrônicos da Oficina (§24.5). As três produzem o
     * mesmo recurso `componentes_eletronicos`, com insumos distintos. Ver D-23.
     */
    /**
     * As três receitas do §24.5, para a UI oferecer a escolha.
     *
     * Sem esta rota o `PATCH /buildings/{id}/recipe` era inalcançável: o frontend não tinha de
     * onde tirar os códigos válidos, e digitá-los à mão no React seria copiar o GDD para fora do
     * banco. Leitura pura; não depende de colônia.
     */
    public function recipes(): JsonResponse
    {
        return response()->json(
            DB::table('component_recipes')->orderBy('id')->get()->map(fn ($r) => [
                'code' => $r->code,
                'nome' => $r->nome,
                'contexto' => $r->contexto,
                'insumos_por_unidade' => json_decode($r->insumos_json, true),
                'padrao' => $r->code === ColonyTick::RECEITA_PADRAO,
            ])->values(),
        );
    }

    public function recipe(Request $request, Building $building): JsonResponse
    {
        $colony = $request->user()->colony;

        if (! $colony || $building->colony_id !== $colony->id) {
            throw new DomainRuleException('construcao_de_outra_colonia', 'Esta construção não é sua.');
        }

        if ($building->type !== 'oficina') {
            throw new DomainRuleException('sem_receita', 'Só a Oficina escolhe receita.');
        }

        $dados = $request->validate([
            'recipe' => ['required', 'string', 'exists:component_recipes,code'],
        ]);

        $building->update(['recipe' => $dados['recipe']]);

        $receita = DB::table('component_recipes')->where('code', $dados['recipe'])->first();

        return response()->json([
            'recipe' => $receita->code,
            'nome' => $receita->nome,
            'contexto' => $receita->contexto,
            'insumos_por_unidade' => json_decode($receita->insumos_json, true),
        ]);
    }

    public function queue(Request $request): JsonResponse
    {
        $user = $request->user();
        $colony = $user->colony;

        if (! $colony) {
            throw new DomainRuleException('sem_colonia', 'Funde uma colônia primeiro.');
        }

        $itens = BuildQueue::where('colony_id', $colony->id)->ativos()
            ->with('building')->orderBy('position')->get();

        return response()->json([
            'slots' => BuildQueue::vagasDe($user),
            'used' => $itens->count(),
            'items' => $itens->map(fn (BuildQueue $i) => [
                'building' => $i->building->type,
                'target_level' => $i->target_level,
                'position' => $i->position,
                'status' => $i->status,
                'subsidized' => $i->subsidized,
                'cost' => $i->quoted_cost_json,
                'finishes_at' => $i->finishes_at,
            ]),
        ]);
    }

    /** Catálogo do GDD para a UI: o custo do próximo nível, subsidiado ou não. */
    public function specs(Request $request, BuildingSpecs $specs): JsonResponse
    {
        $user = $request->user();
        $colony = $user->colony;

        if (! $colony) {
            throw new DomainRuleException('sem_colonia', 'Funde uma colônia primeiro.');
        }

        return response()->json(
            $colony->buildings->map(function (Building $b) use ($specs, $user) {
                $alvo = $b->level + 1;
                $max = $specs->nivelMaximo($b->type);

                if ($alvo > $max) {
                    return ['id' => $b->id, 'type' => $b->type, 'level' => $b->level, 'max_level' => $max];
                }

                try {
                    $spec = $specs->para($b->type, $alvo);
                } catch (DomainRuleException $e) {
                    // tempo_indefinido: o GDD não cronometra esta construção (D-10).
                    return ['id' => $b->id, 'type' => $b->type, 'level' => $b->level,
                        'max_level' => $max, 'blocked' => $e->codigo];
                }

                return [
                    'id' => $b->id,
                    'type' => $b->type,
                    'level' => $b->level,
                    'max_level' => $max,
                    'next_level' => $alvo,
                    'cost' => $spec['custo'],
                    'build_time_seconds' => $spec['tempo_segundos'],
                    'subsidized' => $b->ehEssencial() && $alvo <= 3 && $user->tutoriaConcluida(),
                    // Só a Oficina escolhe receita (§24.5). Sem este campo a UI não teria como
                    // mostrar qual das três está ativa, e o `PATCH .../recipe` ficava órfão.
                    'recipe' => $b->type === 'oficina' ? ($b->recipe ?? ColonyTick::RECEITA_PADRAO) : null,
                ];
            })->values(),
        );
    }
}
