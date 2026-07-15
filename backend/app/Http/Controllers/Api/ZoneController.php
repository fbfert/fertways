<?php

namespace App\Http\Controllers\Api;

use App\Domain\Guerra\Protegido;
use App\Domain\Logistics\DespacharVeiculo;
use App\Domain\Zona\ConstruirNaZona;
use App\Domain\Zona\Estruturas;
use App\Http\Controllers\Controller;
use App\Models\Combat;
use App\Models\Ledger;
use App\Models\NeutralZone;
use App\Models\Unit;
use App\Models\Vehicle;
use App\Models\ZoneEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * A zona neutra como LUGAR (GDD §17.4; docs/decisoes.md D-67).
 *
 * A tela da zona precisa de tudo o que ela é: as estruturas erguidas, o canteiro de obras, o que está
 * exposto ao saque, a guarnição, e o que o GDD promete mas o jogo ainda não entrega.
 */
class ZoneController extends Controller
{
    /**
     * As MINHAS zonas, com o que exige ação (docs/decisoes.md D-69, D-88).
     *
     * É o que a barra lateral da colônia lista. Cada linha traz o que decide se o colono precisa
     * largar o que está fazendo:
     *
     *  - **exposto** — o que a guerra pode levar. Só o que EXCEDE o Depósito é saqueável (D-66), e
     *    uma zona com 3.000 expostos é um convite pendurado no mapa.
     *  - **cercada** — nada entra nem sai, e o que se extrai se perde (§28.10). É a urgência maior.
     *  - **obra** — o que está sendo erguido, e quando fica pronto.
     *  - **guarnição, canteiro, nível/upgrade, manutenção** (D-88) — o card ganhou mais informação
     *    de propósito: antes, para saber se a defesa estava fraca ou a manutenção atrasada, era
     *    preciso abrir a zona. É a mesma zona da tela cheia (`show()`), resumida para a lateral.
     */
    public function minhas(Request $request, Protegido $protegido): JsonResponse
    {
        $colony = $request->user()->colony()->firstOrFail();

        $zonas = NeutralZone::where('owner_colony_id', $colony->id)
            ->with('obras', 'materiais')
            ->orderBy('id')
            ->get()
            ->map(function (NeutralZone $z) use ($protegido) {
                $obra = $z->obras->first();

                return [
                    'id' => $z->id,
                    'name' => $z->name,
                    'x' => $z->x,
                    'y' => $z->y,
                    'mineral' => $z->mineral,
                    'deposito' => $z->estoqueTotal(),
                    'capacidade' => $z->capacidadeDeposito(),
                    // ⚠️ O número que decide se há urgência: é isto que um invasor leva.
                    'exposto' => $protegido->exposto($z),
                    'cercada' => $z->cercada(),
                    'produtiva' => $z->estaProdutiva(),
                    'obra' => $obra ? [
                        'nome' => Estruturas::de($obra->structure)['nome'],
                        'nivel' => $obra->target_level,
                        'termina_at' => $obra->finishes_at,
                    ] : null,

                    'level' => $z->level,
                    'upgrade' => $z->level_target ? [
                        'target' => $z->level_target,
                        'finishes_at' => $z->level_upgrade_finishes_at,
                    ] : null,

                    'guarnicao' => [
                        'robos' => $z->guarnicao(),
                        'sentinelas' => $z->units()->where('type', 'sentinela')->where('hp_bps', '>', 0)->count(),
                        'defesa' => Unit::where('zone_id', $z->id)->where('hp_bps', '>', 0)->get()
                            ->sum(fn (Unit $u) => $u->defesa()),
                    ],

                    'manutencao' => [
                        'inadimplente_desde' => $z->maintenance_unpaid_since,
                        'penalidade_bps' => $z->penalidadeManutencaoBps(),
                    ],

                    'canteiro' => $z->materiais->where('amount', '>', 0)
                        ->map(fn ($m) => ['resource_type' => $m->resource_type, 'amount' => $m->amount])
                        ->values(),
                ];
            });

        return response()->json(['zones' => $zonas]);
    }

    /** A ficha da zona. Só o dono a vê por dentro — para os outros, o mapa já diz o essencial. */
    public function show(Request $request, NeutralZone $zone, Protegido $protegido): JsonResponse
    {
        $colony = $request->user()->colony()->firstOrFail();

        if ($zone->owner_colony_id !== $colony->id) {
            return response()->json([
                'message' => 'Esta zona não é sua.',
                'code' => 'zona_nao_e_sua',
            ], 403);
        }

        $obra = $zone->obras()->first();

        return response()->json([
            'id' => $zone->id,
            'name' => $zone->name,
            'x' => $zone->x,
            'y' => $zone->y,
            'district' => $zone->district,
            'mineral' => $zone->mineral,
            'status' => $zone->status,
            'cercada' => $zone->cercada(),
            'productive_at' => $zone->productive_at,
            'protected_until' => $zone->protected_until,

            // Upgrade de nível e manutenção territorial (D-84).
            'level' => $zone->level,
            'upgrade' => [
                'target' => $zone->level_target,
                'finishes_at' => $zone->level_upgrade_finishes_at,
                'proximo_custo' => $zone->level < \App\Models\NeutralZone::NIVEL_MAXIMO
                    ? \App\Models\NeutralZone::custoDeUpgrade($zone->level + 1)
                    : null,
                'proxima_guarnicao' => $zone->level < \App\Models\NeutralZone::NIVEL_MAXIMO
                    ? \App\Models\NeutralZone::guarnicaoAlvo($zone->level + 1)
                    : null,
            ],
            'manutencao' => [
                'custo_diario' => $zone->custoDeManutencao(),
                'proximo_vencimento' => $zone->maintenance_next_due_at,
                'inadimplente_desde' => $zone->maintenance_unpaid_since,
                'penalidade_bps' => $zone->penalidadeManutencaoBps(),
            ],

            'deposito' => [
                'bruto' => $zone->deposit_amount,
                // O que a Refinaria de Campo já converteu. Ocupa o mesmo Depósito (D-67).
                'refinado' => $zone->refined_amount,
                'refinado_recurso' => $zone->recursoRefinado(),
                // Os cinco minerais eletrônicos da Indústria Siderúrgica (D-82) — mesmo Depósito,
                // mesma capacidade, mesmo saque. Só os que já têm alguma coisa aparecem.
                'minerais' => $zone->minerais()->where('amount', '>', 0)
                    ->orderBy('resource_type')->get(['resource_type', 'amount']),
                'capacidade' => $zone->capacidadeDeposito(),
                // ⚠️ O que a guerra pode levar. Só o que EXCEDE a capacidade é saqueável (D-66):
                // o Depósito é o cofre, e o que transborda é butim.
                'protegido' => $protegido->protegido($zone),
                'exposto' => $protegido->exposto($zone),
            ],

            'extracao_hora' => $zone->extracaoPorHora(),
            'refino_hora' => $zone->refinoPorHora(),

            'guarnicao' => [
                'robos' => $zone->guarnicao(),
                'sentinelas' => $zone->units()->where('type', 'sentinela')->where('hp_bps', '>', 0)->count(),
                'defesa' => Unit::where('zone_id', $zone->id)->where('hp_bps', '>', 0)->get()
                    ->sum(fn (Unit $u) => $u->defesa()),
            ],

            'estruturas' => $this->estruturas($zone),
            'canteiro' => $zone->materiais()->orderBy('resource_type')->get(['resource_type', 'amount']),
            'obra' => $obra ? [
                'structure' => $obra->structure,
                'nome' => Estruturas::de($obra->structure)['nome'],
                'target_level' => $obra->target_level,
                'finishes_at' => $obra->finishes_at,
            ] : null,

            // O que o §17.4 lista e o jogo NÃO tem, e por quê. A tela as mostra como buraco marcado,
            // em vez de fingir que não existem — é o padrão do Gagarin e do Espaçoporto (D-55, D-63).
            'ausentes' => Estruturas::AUSENTES,

            'modules_offline' => $zone->modules_offline ?? [],
        ]);
    }

    /**
     * O Histórico da zona (docs/decisoes.md D-86). Só o dono a vê — mesma régua do `show()`.
     *
     * Três fontes, normalizadas numa linha do tempo só:
     *  - **financeiro**: `Ledger` cujo `ref` começa em `zona:{id}:` (ocupação, upgrade,
     *    manutenção, saque/cerco). O material entregue ao canteiro (`custo_obra_zona`) fica de
     *    fora: o ledger dele é indexado pela viagem do veículo, não pela zona — não há como
     *    filtrar por zona sem um JOIN que este histórico não vale a pena pagar.
     *  - **guerra**: `Combat` desta zona — invasões, cercos, sabotagens, apreensões.
     *  - **posse**: `ZoneEvent` — ocupação, abandono por manutenção, conquista.
     */
    public function historico(Request $request, NeutralZone $zone): JsonResponse
    {
        $colony = $request->user()->colony()->firstOrFail();

        if ($zone->owner_colony_id !== $colony->id) {
            return response()->json([
                'message' => 'Esta zona não é sua.',
                'code' => 'zona_nao_e_sua',
            ], 403);
        }

        $financeiro = Ledger::where('ref', 'like', "zona:{$zone->id}:%")
            ->orderByDesc('id')
            ->get()
            ->map(fn (Ledger $l) => [
                'categoria' => 'financeiro',
                'em' => $l->created_at,
                'tipo' => $l->type,
                'recurso' => $l->resource_type,
                'quantidade' => $l->amount,
                'ref' => $l->ref,
            ]);

        $guerra = Combat::where('zone_id', $zone->id)
            ->with(['attacker:id,name', 'defender:id,name'])
            ->orderByDesc('id')
            ->get()
            ->map(fn (Combat $c) => [
                'categoria' => 'guerra',
                'em' => $c->updated_at,
                'tipo' => $c->tipo,
                'status' => $c->status,
                'atacante' => $c->attacker?->name,
                'defensor' => $c->defender?->name,
                'resultado' => $c->resultado,
            ]);

        $posse = ZoneEvent::where('zone_id', $zone->id)
            ->with('colony:id,name')
            ->orderByDesc('id')
            ->get()
            ->map(fn (ZoneEvent $e) => [
                'categoria' => 'posse',
                'em' => $e->created_at,
                'tipo' => $e->type,
                'colonia' => $e->colony?->name,
                'meta' => $e->meta,
            ]);

        $linha = $financeiro->concat($guerra)->concat($posse)
            ->sortByDesc('em')
            ->values();

        return response()->json(['eventos' => $linha]);
    }

    /**
     * Ergue ou evolui uma estrutura. **O material sai do canteiro**, não do estoque da colônia — as
     * obras da zona exigem entrega física (D-67).
     */
    public function construir(Request $request, NeutralZone $zone, ConstruirNaZona $construir): JsonResponse
    {
        $dados = $request->validate([
            'structure' => ['required', Rule::in(Estruturas::CONSTRUIVEIS)],
        ]);

        $colony = $request->user()->colony()->firstOrFail();

        $construir->handle($colony, $zone, $dados['structure']);

        return response()->json(['obra' => $zone->fresh()->obras()->first()], 201);
    }

    /** Despacha um veículo com material de obra até o canteiro da zona (D-67). */
    public function entregarMaterial(
        Request $request,
        NeutralZone $zone,
        DespacharVeiculo $despachar,
    ): JsonResponse {
        $dados = $request->validate([
            'vehicle_id' => ['required', 'integer'],
            'cargo' => ['required', 'array', 'min:1'],
            'cargo.*' => ['integer', 'min:1'],
        ]);

        $colony = $request->user()->colony()->firstOrFail();
        $veiculo = Vehicle::where('colony_id', $colony->id)->findOrFail($dados['vehicle_id']);

        $v = $despachar->entregarMaterialNaZona($colony, $veiculo, $zone, $dados['cargo']);

        return response()->json([
            'id' => $v->id,
            // Tipo e placa: sem eles, a tela só podia dizer "um veículo" — o colono tem de
            // adivinhar QUAL, com dois ou três Furgões numa colônia (D-79, aditivo de UX).
            'type' => $v->type,
            'plate' => $v->plate,
            'status' => $v->status,
            'arrives_at' => $v->arrives_at,
        ], 201);
    }

    /**
     * Renomeia a zona, como o colono já nomeia a colônia (D-79, aditivo de UX). Sem regra no GDD —
     * é conveniência, não muda função nenhuma. Vazio volta a mostrar as coordenadas.
     */
    public function renomear(Request $request, NeutralZone $zone): JsonResponse
    {
        $colony = $request->user()->colony()->firstOrFail();

        if ($zone->owner_colony_id !== $colony->id) {
            return response()->json([
                'message' => 'Esta zona não é sua.',
                'code' => 'zona_nao_e_sua',
            ], 403);
        }

        $dados = $request->validate([
            'name' => ['nullable', 'string', 'max:120'],
        ]);

        $zone->update(['name' => $dados['name'] !== '' ? ($dados['name'] ?? null) : null]);

        return response()->json(['name' => $zone->name]);
    }

    /**
     * As estruturas da zona: o que está erguido, o que se pode erguer, e o que cada uma FAZ.
     *
     * O `hoje` e o `gdd` vêm de `Domain\Zona\Estruturas` e são duas coisas diferentes de propósito —
     * o que o documento promete e o que o jogo entrega. Sem essa separação, o colono gasta 600 Metal
     * Bruto num Cemitério e só depois descobre que o próprio GDD o declara "apenas visual".
     */
    private function estruturas(NeutralZone $zone): array
    {
        $out = [];

        foreach (Estruturas::COLUNA as $tipo => $coluna) {
            $nivel = (int) $zone->{$coluna};
            $info = Estruturas::de($tipo);

            $proximo = DB::table('building_specs')
                ->where('building_type', $tipo)
                ->where('level', $nivel + 1)
                ->first();

            $out[] = [
                'type' => $tipo,
                'nome' => $info['nome'],
                'level' => $nivel,
                'gdd' => $info['gdd'],
                'hoje' => $info['hoje'],
                'inerte' => $info['inerte'],
                'construivel' => in_array($tipo, Estruturas::CONSTRUIVEIS, true),
                'offline' => in_array($tipo, $zone->modules_offline ?? [], true),
                'proximo' => $proximo ? [
                    'level' => $nivel + 1,
                    'custo' => json_decode($proximo->cost_json, true),
                    'segundos' => (int) $proximo->build_time_seconds,
                ] : null,
            ];
        }

        return $out;
    }
}
