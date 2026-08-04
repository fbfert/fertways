<?php

namespace App\Http\Controllers\Api;

use App\Domain\Building\Demolir;
use App\Domain\Guerra\Protegido;
use App\Domain\GuerraFederativa\EmGuerra;
use App\Domain\Logistics\DespacharVeiculo;
use App\Domain\Populacao\Parametros;
use App\Domain\Zona\ConstruirNaZona;
use App\Domain\Zona\DemolirEstruturaDaZona;
use App\Domain\Zona\Estruturas;
use App\Domain\Zona\Operadores;
use App\Domain\Zona\RepararModulo;
use App\Domain\Zona\ZonaSlots;
use App\Exceptions\DomainRuleException;
use App\Http\Controllers\Controller;
use App\Models\Colony;
use App\Models\Combat;
use App\Models\FilaSetting;
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
     *  - **exposto** — o que a guerra pode levar. Fora de guerra federativa é só o que EXCEDE o
     *    Depósito (D-66), e uma zona com 3.000 expostos é um convite pendurado no mapa; **em guerra
     *    é o estoque inteiro** (D-205), e o campo passa a valer isso.
     *  - **cercada** — nada entra nem sai, e o que se extrai se perde (§28.10). É a urgência maior.
     *  - **obra** — o que está sendo erguido, e quando fica pronto.
     *  - **guarnição, canteiro, nível/upgrade, manutenção** (D-88) — o card ganhou mais informação
     *    de propósito: antes, para saber se a defesa estava fraca ou a manutenção atrasada, era
     *    preciso abrir a zona. É a mesma zona da tela cheia (`show()`), resumida para a lateral.
     */
    public function minhas(Request $request, Protegido $protegido, EmGuerra $emGuerra): JsonResponse
    {
        $colony = $request->user()->colony()->firstOrFail();

        $zonas = NeutralZone::where('owner_colony_id', $colony->id)
            ->with('obras', 'materiais')
            ->orderBy('id')
            ->get()
            ->map(function (NeutralZone $z) use ($protegido, $emGuerra, $colony) {
                $obra = $z->obras->first();

                return [
                    'id' => $z->id,
                    'name' => $z->name,
                    'x' => $z->x,
                    'y' => $z->y,
                    'mineral' => $z->mineral,
                    'deposito' => $z->estoqueTotal(),
                    'capacidade' => $z->capacidadeDeposito(),
                    /*
                     * ⚠️ O número que decide se há urgência: é isto que um invasor leva.
                     *
                     * **Em guerra federativa é o estoque inteiro** (D-205) — o Depósito para de
                     * proteger, e mandar o jogador olhar para o "exposto" seria mandá-lo olhar para
                     * o número errado no exato momento em que ele mais importa.
                     */
                    'exposto' => $emGuerra->federacaoEmGuerra($colony->federation_id)
                        ? $z->estoqueTotal()
                        : $protegido->exposto($z),
                    'saque_total_por_guerra' => $emGuerra->federacaoEmGuerra($colony->federation_id),
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
    /**
     * A equipe da zona (A2.6): quantos há, quantos ela pede, e quanto a falta está custando.
     *
     * @return array<string,mixed>
     */
    private function equipe(NeutralZone $zone, Colony $colony): array
    {
        $operadores = app(Operadores::class);
        $ativo = app(Parametros::class)->ativo();

        return [
            'ativo' => $ativo,
            'na_zona' => (int) $zone->operadores,
            'exigidos' => $operadores->exigidos($zone),
            // Em porcentagem: é como o jogador sente a perda, e o bps é vocabulário de dentro.
            'eficiencia' => $operadores->eficienciaBps($zone) / 100,
            'livres_na_colonia' => max(0, $operadores->disponivel($colony)),
        ];
    }

    public function show(
        Request $request,
        NeutralZone $zone,
        Protegido $protegido,
        RepararModulo $reparo,
        EmGuerra $emGuerra,
    ): JsonResponse {
        $colony = $request->user()->colony()->firstOrFail();

        if ($zone->owner_colony_id !== $colony->id) {
            return response()->json([
                'message' => 'Esta zona não é sua.',
                'code' => 'zona_nao_e_sua',
            ], 403);
        }

        /*
         * A fila inteira, não só a primeira (revisão de 2026-07-19, achado #10). O teto de obras
         * simultâneas (`FilaSetting::zona_vagas`, D-111) é do operador e pode passar de 1 — mas
         * `ConstruirNaZona` já aceitava a segunda obra desde então, e a tela nunca mostrava (nem
         * deixava iniciar) a segunda: só lia `obras()->first()`, e o botão "Construir" desabilitava
         * assim que UMA obra qualquer existisse, mesmo com vaga sobrando.
         */
        $obras = $zone->obras()->orderBy('id')->get();

        return response()->json([
            'id' => $zone->id,
            'name' => $zone->name,
            'x' => $zone->x,
            'y' => $zone->y,
            'district' => $zone->district,
            'mineral' => $zone->mineral,
            'status' => $zone->status,
            'cercada' => $zone->cercada(),

            /*
             * A2.6: a equipe da zona e o que a falta dela custa.
             *
             * "Visualização de operadores" é entrega da fase, e sem ela a degradação do §6.6 seria
             * invisível — o jogador veria a extração cair e não teria como saber por quê. Uma
             * penalidade que não se consegue ver é indistinguível de um defeito.
             */
            'operadores' => $this->equipe($zone, $colony),
            'productive_at' => $zone->productive_at,
            'protected_until' => $zone->protected_until,

            // Upgrade de nível e manutenção territorial (D-84).
            'level' => $zone->level,
            'upgrade' => [
                'target' => $zone->level_target,
                'finishes_at' => $zone->level_upgrade_finishes_at,
                'proximo_custo' => $zone->level < NeutralZone::NIVEL_MAXIMO
                    ? NeutralZone::custoDeUpgrade($zone->level + 1)
                    : null,
                'proxima_guarnicao' => $zone->level < NeutralZone::NIVEL_MAXIMO
                    ? NeutralZone::guarnicaoAlvo($zone->level + 1)
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
                /*
                 * ⚠️ **E em guerra federativa o cofre não vale nada** (D-205). Com uma guerra ativa
                 * contra a federação do dono, uma invasão leva o estoque INTEIRO — `protegido` vira
                 * um número que mente. A tela precisa dizer qual dos dois regimes está valendo, ou
                 * o jogador defende a zona errada.
                 */
                'saque_total_por_guerra' => $emGuerra->federacaoEmGuerra($colony->federation_id),
            ],

            'extracao_hora' => $zone->extracaoPorHora(),
            'refino_hora' => $zone->refinoPorHora(),

            'guarnicao' => [
                'robos' => $zone->guarnicao(),
                'sentinelas' => $zone->units()->where('type', 'sentinela')->where('hp_bps', '>', 0)->count(),
                'defesa' => Unit::where('zone_id', $zone->id)->where('hp_bps', '>', 0)->get()
                    ->sum(fn (Unit $u) => $u->defesa()),
            ],

            'estruturas' => $this->estruturas($zone, $reparo),
            'canteiro' => $zone->materiais()->orderBy('resource_type')->get(['resource_type', 'amount']),
            'obras' => $obras->map(fn ($o) => [
                'structure' => $o->structure,
                'slot' => $o->slot,
                'nome' => Estruturas::de($o->structure)['nome'],
                'target_level' => $o->target_level,
                'finishes_at' => $o->finishes_at,
            ])->values(),
            'obras_vagas' => FilaSetting::singleton()->zona_vagas,

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
                // `resource_type` null é Fert$, guardado em micro (1 Fert$ = 1.000.000) — mesma
                // escala de `colonies.fert_micro` (`ProfileController::extrato()` já converte
                // assim). Sem isto, o Histórico mostrava "-300000000" em vez de "-300".
                'quantidade' => $l->resource_type === null ? $l->amount / 1_000_000 : $l->amount,
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
            'slot' => ['required', 'integer', 'min:0', 'max:'.(ZonaSlots::TOTAL - 1)],
        ]);

        $colony = $request->user()->colony()->firstOrFail();

        $construir->handle($colony, $zone, $dados['structure'], $dados['slot']);

        return response()->json(['obra' => $zone->fresh()->obras()->latest('id')->first()], 201);
    }

    /**
     * Demole uma estrutura da zona — o espelho de `BuildingController::demolir` que faltava
     * (D-122/D-123, achado 7; fechado no D-138). O investido não volta, e a API **exige a mesma
     * palavra** que a colônia já exige, pelo mesmo motivo: uma confirmação que vivesse só no React
     * protegeria contra o dedo escorregando e contra mais nada.
     */
    public function demolir(
        Request $request,
        NeutralZone $zone,
        int $slot,
        DemolirEstruturaDaZona $demolir,
    ): JsonResponse {
        $confirmacao = (string) $request->input('confirmacao');

        if ($confirmacao !== Demolir::PALAVRA) {
            throw new DomainRuleException(
                'confirmacao_invalida',
                'Para demolir, escreva '.Demolir::PALAVRA.'. Nada é devolvido, e não há volta.',
            );
        }

        $colony = $request->user()->colony()->firstOrFail();

        $demolir->handle($colony, $zone, $slot);

        return response()->json(['demolida' => true]);
    }

    /**
     * Repara uma estrutura sabotada, ou resgata antecipadamente uma apreendida (D-118). A Apreensão
     * também repara sozinha em 24h (o tick de `ExpirarApreensoes`) — isto é o atalho pago.
     */
    public function reparar(Request $request, NeutralZone $zone, RepararModulo $reparo): JsonResponse
    {
        $dados = $request->validate([
            'estrutura' => ['required', Rule::in(Estruturas::TODAS)],
        ]);

        $colony = $request->user()->colony()->firstOrFail();

        $reparo->handle($colony, $zone, $dados['estrutura']);

        return response()->json(['estruturas' => $this->estruturas($zone->fresh(), $reparo)]);
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
    /**
     * POST /zones/{zone}/operadores — manda colonos da colônia para a zona (A2.6).
     *
     * A dona é conferida no domínio, e não aqui: `Operadores` é chamado também pelo painel do
     * operador e por teste, e uma trava que mora só no controller é uma trava que não existe.
     */
    public function alocarOperadores(Request $request, NeutralZone $zone): JsonResponse
    {
        $dados = $request->validate(['quantos' => ['required', 'integer', 'min:1']]);
        $colony = $request->user()->colony()->firstOrFail();

        $zona = app(Operadores::class)->alocar($colony, $zone, (int) $dados['quantos']);

        return response()->json(['operadores' => $this->equipe($zona, $colony->fresh())]);
    }

    /** DELETE /zones/{zone}/operadores — o "retorno": traz colonos de volta. */
    public function devolverOperadores(Request $request, NeutralZone $zone): JsonResponse
    {
        $dados = $request->validate(['quantos' => ['required', 'integer', 'min:1']]);
        $colony = $request->user()->colony()->firstOrFail();

        $zona = app(Operadores::class)->devolver($colony, $zone, (int) $dados['quantos']);

        return response()->json(['operadores' => $this->equipe($zona, $colony->fresh())]);
    }

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
     * As estruturas da zona (D-144: colmeia de slots) — o que está erguido em CADA slot, o que
     * cada uma FAZ, e o mapa da colmeia (linhas, slots desbloqueados no nível atual, slots
     * ocupados) para o front desenhar a planta em SVG.
     *
     * O `hoje` e o `gdd` vêm de `Domain\Zona\Estruturas` e são duas coisas diferentes de propósito —
     * o que o documento promete e o que o jogo entrega. Sem essa separação, o colono gasta 600 Metal
     * Bruto num Cemitério e só depois descobre que o próprio GDD o declara "apenas visual".
     */
    private function estruturas(NeutralZone $zone, RepararModulo $reparo): array
    {
        $erguidas = $zone->zoneStructures()->orderBy('slot')->get();

        $porSlot = $erguidas->map(function ($linha) use ($zone, $reparo) {
            $tipo = $linha->type;
            $nivel = $linha->level;
            $info = Estruturas::de($tipo);

            $proximo = DB::table('building_specs')
                ->where('building_type', $tipo)
                ->where('level', $nivel + 1)
                ->first();

            // Apreensão (Predador, binária) e Sabotagem (Infiltrador, proporcional) — D-118. Uma
            // estrutura nunca está nas duas ao mesmo tempo (a Apreensão já zera `fracaoEfetiva`).
            // As duas só miram tipos ÚNICOS (`Domain\Guerra\Atacar::ALVOS_ATACAVEIS`), então o tipo
            // ainda identifica a estrutura sem ambiguidade de slot.
            $apreendida = in_array($tipo, $zone->modules_offline ?? [], true);
            $nivelSabotagem = ($zone->structures_saboted ?? [])[$tipo] ?? null;

            return [
                'slot' => $linha->slot,
                'type' => $tipo,
                'nome' => $info['nome'],
                'level' => $nivel,
                'gdd' => $info['gdd'],
                'hoje' => $info['hoje'],
                'inerte' => $info['inerte'],
                'indemolivel' => $linha->slot === ZonaSlots::POSTO_SLOT,
                'offline' => $apreendida,   // mantido: é o que a UI já lia para o badge.
                'fracao_efetiva' => $zone->fracaoEfetiva($tipo),
                'apreendida' => $apreendida ? [
                    'expira_em' => ($zone->modules_offline_expira_em ?? [])[$tipo] ?? null,
                ] : null,
                'sabotada' => $nivelSabotagem !== null ? [
                    'nivel_do_infiltrador' => $nivelSabotagem,
                ] : null,
                // Custo do reparo/resgate — só faz sentido pedir se há nível construído; nenhuma
                // das duas degradações acontece numa estrutura em nível 0.
                'custo_reparo' => ($apreendida || $nivelSabotagem !== null)
                    ? $reparo->custo($tipo, $nivel)
                    : null,
                'proximo' => $proximo ? [
                    'level' => $nivel + 1,
                    'custo' => json_decode($proximo->cost_json, true),
                    'segundos' => (int) $proximo->build_time_seconds,
                ] : null,
            ];
        })->values();

        // O catálogo: o que se PODE erguer num slot vazio, e o que cada tipo faz — mesmo padrão de
        // `BuildingController::catalogo()` (D-59), com `repetivel`/`quantas` no lugar de
        // `disponivel` booleano: um tipo repetível está sempre disponível, com N cópias.
        $quantasPorTipo = $erguidas->groupBy('type')->map->count();

        $catalogo = collect(Estruturas::CONSTRUIVEIS)->map(function (string $tipo) use ($quantasPorTipo) {
            $info = Estruturas::de($tipo);
            $spec = DB::table('building_specs')->where('building_type', $tipo)->where('level', 1)->first();
            $repetivel = in_array($tipo, Estruturas::REPETIVEIS, true);

            return [
                'type' => $tipo,
                'nome' => $info['nome'],
                'gdd' => $info['gdd'],
                'hoje' => $info['hoje'],
                'inerte' => $info['inerte'],
                'repetivel' => $repetivel,
                'quantas' => $quantasPorTipo->get($tipo, 0),
                'disponivel' => $repetivel || $quantasPorTipo->get($tipo, 0) === 0,
                'custo_nivel_1' => $spec ? json_decode($spec->cost_json, true) : null,
                'segundos_nivel_1' => $spec ? (int) $spec->build_time_seconds : null,
            ];
        })->values();

        return [
            'colmeia' => [
                'linhas' => ZonaSlots::LINHAS,
                'total' => ZonaSlots::TOTAL,
                'slot_do_posto' => ZonaSlots::POSTO_SLOT,
                'desbloqueados' => ZonaSlots::desbloqueadosAte($zone->level),
            ],
            'erguidas' => $porSlot,
            'catalogo' => $catalogo,
        ];
    }
}
