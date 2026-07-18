<?php

namespace App\Http\Controllers\Api;

use App\Domain\Building\BuildingSpecs;
use App\Domain\Building\ConstruirEmSlot;
use App\Domain\Building\Demolir;
use App\Domain\Building\EnqueueUpgrade;
use App\Domain\Building\Funcoes;
use App\Domain\Colony\Slots;
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
     * Ergue uma construção nova num slot escolhido pelo colono (D-59).
     *
     * `POST /buildings` com `{type, slot}`. Antes do D-59 esta rota não existia: as 16 construções
     * já vinham da fundação no nível 0 e "construir" era só o primeiro upgrade. Agora a linha de
     * `buildings` nasce aqui.
     */
    public function construir(Request $request, ConstruirEmSlot $construir): JsonResponse
    {
        $colony = $request->user()->colony;

        if (! $colony) {
            throw new DomainRuleException('sem_colonia', 'Funde uma colônia primeiro.');
        }

        $dados = $request->validate([
            'type' => ['required', 'string'],
            'slot' => ['required', 'integer', 'min:0', 'max:' . (Slots::TOTAL - 1)],
        ]);

        $item = $construir->handle($colony, $dados['type'], $dados['slot']);

        return response()->json([
            'building' => $item->building->type,
            'slot' => $item->building->slot,
            'target_level' => $item->target_level,
            'status' => $item->status,
            'cost' => $item->quoted_cost_json,
            'finishes_at' => $item->finishes_at,
        ], 201);
    }

    /**
     * Demole e libera o slot (D-59). O investido não volta; essencial não cai; em obra não se
     * demole. As três regras são arbitragem do usuário — o GDD não fala em demolição.
     */
    public function demolir(Request $request, Building $building, Demolir $demolir): JsonResponse
    {
        $colony = $request->user()->colony;

        if (! $colony) {
            throw new DomainRuleException('sem_colonia', 'Funde uma colônia primeiro.');
        }

        /*
         * D-61: o colono tem de **escrever a palavra**.
         *
         * A exigência vive **aqui**, e não só na tela, de propósito. Uma confirmação que só existe no
         * React protege contra o dedo escorregando e contra mais nada: quem chamar a API direto — ou
         * um duplo-clique que dispare a chamada duas vezes — demole sem digitar nada. **A API é a
         * porta de verdade.**
         *
         * Demolir é irreversível e não devolve nada (D-59): o custo já foi lançado, e a construção
         * vira pó. É a ação mais destrutiva que o colono tem à mão.
         */
        $confirmacao = (string) $request->input('confirmacao');

        if ($confirmacao !== Demolir::PALAVRA) {
            throw new DomainRuleException(
                'confirmacao_invalida',
                'Para demolir, escreva '.Demolir::PALAVRA.'. Nada é devolvido, e não há volta.',
            );
        }

        $demolir->handle($colony, $building);

        return response()->json(['demolida' => true]);
    }

    /**
     * O que o colono PODE erguer, e onde (D-59).
     *
     * Serve o painel do slot vazio. Devolve as 12 de progressão — as 5 essenciais nascem no miolo
     * e não se erguem de novo —, cada uma com o que faz, o custo do nível 1 e se já existe na
     * colônia (as não-repetíveis somem da lista depois de erguidas).
     */
    public function catalogo(Request $request, BuildingSpecs $specs): JsonResponse
    {
        $colony = $request->user()->colony;

        if (! $colony) {
            throw new DomainRuleException('sem_colonia', 'Funde uma colônia primeiro.');
        }

        $erguidas = $colony->buildings->groupBy('type');
        $ocupados = $colony->buildings->pluck('slot')->filter(fn ($s) => $s !== null)->values();

        $itens = collect(Building::PROGRESSAO)->map(function (string $tipo) use ($specs, $erguidas) {
            $spec = $specs->para($tipo, 1);
            $quantas = $erguidas->get($tipo)?->count() ?? 0;
            $repetivel = in_array($tipo, Building::REPETIVEIS, true);

            return [
                'type' => $tipo,
                'funcao' => Funcoes::de($tipo),
                'cost' => $spec['custo'],
                'build_time_seconds' => $spec['tempo_segundos'],
                'max_level' => $specs->nivelMaximo($tipo),
                'repetivel' => $repetivel,
                'quantas' => $quantas,
                // Uma construção única já erguida não pode ser erguida de novo; uma repetível,
                // sempre pode — o limite dela é o slot vago e a energia (§19.8).
                'disponivel' => $repetivel || $quantas === 0,
            ];
        })->values();

        return response()->json([
            'slots' => ['linhas' => Slots::LINHAS, 'total' => Slots::TOTAL],
            'ocupados' => $ocupados,
            'buildings' => $itens,
        ]);
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
            DB::table('component_recipes')->orderBy('code')->get()->map(fn ($r) => [
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

    /**
     * O detalhe de cada construção erguida: o que ela FAZ, e só depois o que custa evoluir.
     *
     * A ordem importa (D-59, item 5): a tela abre no efeito — a frase do GDD, o que a construção
     * produz agora e o que passaria a produzir no nível seguinte — e o custo/tempo só aparece
     * atrás do botão "Evoluir". O jogador precisa saber para que serve o prédio antes de saber
     * o preço dele.
     */
    public function specs(Request $request, BuildingSpecs $specs): JsonResponse
    {
        $user = $request->user();
        $colony = $user->colony;

        if (! $colony) {
            throw new DomainRuleException('sem_colonia', 'Funde uma colônia primeiro.');
        }

        // Uma consulta só para todas as construções da colônia: com repetição (D-59) uma colônia
        // pode ter quatro Minas, e uma consulta por linha viraria N+1 de verdade.
        $catalogo = DB::table('building_specs')
            ->whereIn('building_type', $colony->buildings->pluck('type')->unique())
            ->get(['building_type', 'level', 'producao_hora_json', 'energia_consumo_hora'])
            ->keyBy(fn ($s) => "{$s->building_type}:{$s->level}");

        // ⚠️ A Indústria Siderúrgica (D-82) reaproveita a chave `metal_bruto` da própria Mina
        // Local dentro de `producao_hora_json` — mas ali é o que ela PROCESSA por hora (um
        // insumo), não o que produz. Devolver isso como `producao_hora` sem distinção fazia o
        // painel dizer "Produz por hora: Metal Bruto: 15", o oposto do que a construção faz.
        $efeito = fn (string $tipo, int $nivel) => ($s = $catalogo->get("{$tipo}:{$nivel}")) ? [
            'producao_hora' => $tipo === 'industria_siderurgica'
                ? null
                : json_decode($s->producao_hora_json ?? 'null', true),
            'insumo_hora' => $tipo === 'industria_siderurgica'
                ? json_decode($s->producao_hora_json ?? 'null', true)
                : null,
            'energia_hora' => (int) $s->energia_consumo_hora,
        ] : null;

        return response()->json(
            $colony->buildings->map(function (Building $b) use ($specs, $user, $efeito) {
                $alvo = $b->level + 1;
                $max = $specs->nivelMaximo($b->type);

                $base = [
                    'id' => $b->id,
                    'type' => $b->type,
                    'level' => $b->level,
                    'slot' => $b->slot,
                    // Só quando ELA é a que está em obra de verdade (não apenas na fila, atrás de
                    // outra) — a contagem no mobile (D-110) precisa saber onde apontar o relógio.
                    'finishes_at' => $b->upgrade_finish_at?->toIso8601String(),
                    'max_level' => $max,
                    'essencial' => $b->ehEssencial(),
                    // Indemolível = essencial, ou o Depósito Local (D-105) — ver
                    // `Building::ehIndemolivel()`. A tela esconde o botão em vez de oferecer um
                    // clique que o backend vai recusar.
                    'demolivel' => ! $b->ehIndemolivel(),
                    'repetivel' => $b->podeRepetir(),
                    // O que ela FAZ: a frase do GDD, a fonte, e a nota honesta de quando o efeito
                    // ainda não morde no jogo.
                    'funcao' => Funcoes::de($b->type),
                    'efeito_atual' => $efeito($b->type, $b->level),
                    'efeito_proximo' => $efeito($b->type, $alvo),
                    // Só a Oficina escolhe receita (§24.5). Sem este campo a UI não teria como
                    // mostrar qual das três está ativa, e o `PATCH .../recipe` ficava órfão.
                    'recipe' => $b->type === 'oficina' ? ($b->recipe ?? ColonyTick::RECEITA_PADRAO) : null,
                ];

                if ($alvo > $max) {
                    return $base;
                }

                try {
                    $spec = $specs->para($b->type, $alvo);
                } catch (DomainRuleException $e) {
                    // tempo_indefinido: o GDD não cronometra esta construção (D-10).
                    return [...$base, 'blocked' => $e->codigo];
                }

                return [
                    ...$base,
                    'next_level' => $alvo,
                    // §24.7: "o custo aparece normalmente na interface, mas junto com a mensagem
                    // 'Esta construção será custeada pelo Governo Central até o nível 3'".
                    'cost' => $spec['custo'],
                    'build_time_seconds' => $spec['tempo_segundos'],
                    'subsidized' => $b->ehEssencial() && $alvo <= 3 && $user->tutoriaConcluida(),
                ];
            })->values(),
        );
    }
}
