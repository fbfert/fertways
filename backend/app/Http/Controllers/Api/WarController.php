<?php

namespace App\Http\Controllers\Api;

use App\Domain\Colony\Silo;
use App\Domain\Drone\DroneSpecs;
use App\Domain\Guerra\Atacar;
use App\Domain\Guerra\ComprarNiobio;
use App\Domain\Guerra\FabricarUnidade;
use App\Domain\Guerra\Forcas;
use App\Domain\Guerra\Protegido;
use App\Domain\Guerra\RankingDeGuerras;
use App\Domain\Guerra\Reforcar;
use App\Domain\Guerra\RomperCerco;
use App\Domain\GuerraFederativa\AtacarColonia;
use App\Http\Controllers\Controller;
use App\Models\Colony;
use App\Models\Combat;
use App\Models\FederationWar;
use App\Models\NeutralZone;
use App\Models\Unit;
use App\Models\WarSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * A guerra (GDD §27, §28.10; docs/decisoes.md D-66). A Fatia 2 do D-52.
 *
 * O exército (`GET /war`), a fábrica (`POST /war/units`), o Nióbio do governo (`POST /war/niobio`),
 * o ataque (`POST /war/attack`) e as batalhas em curso (`GET /war/combats`).
 */
class WarController extends Controller
{
    /** O que a colônia tem para guerrear, e a que preço o governo vende o que falta. */
    public function index(Request $request, Forcas $forcas): JsonResponse
    {
        $colony = $request->user()->colony()->firstOrFail();
        $config = WarSetting::singleton();

        $unidades = Unit::where('colony_id', $colony->id)
            ->where('status', 'casa')
            ->get()
            ->map(fn (Unit $u) => [
                'id' => $u->id,
                'type' => $u->type,
                'level' => $u->level,
                // O HP é o que decide o que ela vale: ferida, ataca e defende menos (§27.6).
                'hp_pct' => round($u->hp_bps / 100, 1),
                'ataque' => $u->ataque(),
                'defesa' => $u->defesa(),
            ]);

        $quartel = $colony->buildings()->where('type', 'quartel')->value('level') ?? 0;

        /*
         * Os Drones aparecem AQUI porque o §21.4 os põe aqui: "armazenado e recarregado no
         * Quartel". A fábrica é a Oficina (D-74) — a tela do Quartel é só o hangar deles.
         */
        $drones = $colony->vehicles()
            ->where('type', DroneSpecs::TIPO)
            ->get()
            ->map(fn ($d) => [
                'id' => $d->id,
                'placa' => $d->plate,
                'level' => $d->level,
                'status' => $d->status,
                // A fase da missão é o `leg`; a tela traduz (voando / sobrevoando / voltando).
                'fase' => $d->leg,
                'modo' => $d->trip_purpose,
                'alvo_zone_id' => $d->leg ? $d->destination_id : null,
                'chega_at' => $d->arrives_at?->toIso8601String(),
                'raio' => DroneSpecs::RAIO[$d->level] ?? 6,
                'bateria_horas' => DroneSpecs::BATERIA_HORAS[$d->level] ?? 24,
            ]);

        return response()->json([
            'quartel_nivel' => $quartel,
            'unidades' => $unidades,
            'drones' => $drones,
            'oficina_nivel' => $colony->buildings()->where('type', 'oficina')->value('level') ?? 0,
            'drone_custos' => DroneSpecs::CUSTO,
            'niobio' => [
                // Sem Nióbio não há Sentinela, e nada no jogo o produz (D-66). O governo vende.
                'em_estoque' => $colony->resources()->where('resource_type', 'niobio_alienigena')->value('amount') ?? 0,
                'preco_fert' => $config->niobio_preco_micro / 1_000_000,
            ],
            'bonus_defensivos' => [
                'muralha_pct_por_nivel' => $config->muralha_bonus_bps / 100,
                'torre_de_vigia_pct_por_nivel' => $config->torre_bonus_bps / 100,
                'bastiao_pct_por_nivel' => $config->bastiao_bonus_bps / 100,
            ],
        ]);
    }

    /** Fabrica no Quartel. Instantâneo: o freio é o Nióbio, não o relógio (D-66). */
    public function fabricar(Request $request, FabricarUnidade $fabrica): JsonResponse
    {
        $dados = $request->validate([
            'type' => ['required', Rule::in(FabricarUnidade::TIPOS)],
            'level' => ['required', 'integer', 'min:1', 'max:5'],
            'quantidade' => ['required', 'integer', 'min:1', 'max:50'],
        ]);

        $colony = $request->user()->colony()->firstOrFail();

        $feitas = $fabrica->handle($colony, $dados['type'], $dados['level'], $dados['quantidade']);

        return response()->json(['fabricadas' => $feitas], 201);
    }

    /** O governo vende Nióbio do caixa do Tesouro (D-17, D-66). Se o caixa secar, não há. */
    public function niobio(Request $request, ComprarNiobio $compra): JsonResponse
    {
        $dados = $request->validate([
            'quantidade' => ['required', 'integer', 'min:1', 'max:1000'],
        ]);

        $colony = $request->user()->colony()->firstOrFail();

        return response()->json(
            ['comprado' => $compra->handle($colony, $dados['quantidade'])],
            201,
        );
    }

    /** Despacha um dos quatro ataques do §27. A marcha é 1,3× mais lenta que a civil (§27.4). */
    /**
     * POST /war/attack-colony — marchar sobre a colônia de uma federação inimiga (A2.10).
     *
     * ⚠️ Rota separada da de zona, e não um parâmetro dela. O alvo muda tudo: as travas são outras
     * (guerra declarada, Quartel), o desfecho é outro (saque em vez de tomada), e o §01 só cai aqui.
     * Um `alvo_tipo` na rota antiga faria duas regras muito diferentes entrarem pela mesma porta.
     */
    public function atacarColonia(Request $request, AtacarColonia $atacar): JsonResponse
    {
        $dados = $request->validate([
            'colony_id' => ['required', 'integer', 'exists:colonies,id'],
            'unit_ids' => ['required', 'array', 'min:1'],
            'unit_ids.*' => ['integer'],
        ]);

        $combate = $atacar->handle(
            $request->user()->colony()->firstOrFail(),
            Colony::findOrFail($dados['colony_id']),
            $dados['unit_ids'],
        );

        return response()->json([
            'combate' => ['id' => $combate->id, 'chega_em' => $combate->chega_at?->toIso8601String()],
        ]);
    }

    /**
     * GET /war/inimigos — as colônias que podem ser atacadas agora (A2.10).
     *
     * ⚠️ Sem esta lista, atacar exigiria adivinhar o id de uma colônia alheia. E ela só devolve quem
     * está de facto em guerra com a sua federação: a tela não deve oferecer o que a regra recusaria.
     */
    public function inimigos(Request $request): JsonResponse
    {
        $colony = $request->user()->colony()->firstOrFail();

        if (! $colony->federation_id) {
            return response()->json(['inimigos' => []]);
        }

        $emGuerra = FederationWar::where('status', 'ativa')
            ->where(fn ($q) => $q->where('declarante_id', $colony->federation_id)
                ->orWhere('alvo_id', $colony->federation_id))
            ->get()
            ->map(fn ($g) => (int) $g->declarante_id === (int) $colony->federation_id
                ? $g->alvo_id
                : $g->declarante_id);

        $silo = app(Silo::class);

        return response()->json([
            'tem_quartel' => (int) $colony->buildings()->where('type', 'quartel')->value('level') >= 1,
            'inimigos' => Colony::whereIn('federation_id', $emGuerra)
                ->with(['resources', 'buildings'])
                ->get()
                ->map(function ($c) use ($silo) {
                    $exposto = 0;

                    foreach ($c->resources as $r) {
                        $exposto += $silo->exposto($c, $r->resource_type, (int) $r->amount);
                    }

                    return [
                        'id' => $c->id,
                        'nome' => $c->name,
                        // O que estaria em risco: é o que torna a decisão de marchar informada.
                        'exposto' => $exposto,
                        'torre' => (int) ($c->buildings->firstWhere('type', 'torre_de_defesa')->level ?? 0),
                        'sob_cerco' => Combat::whereNull('zone_id')
                            ->where('defender_colony_id', $c->id)
                            ->whereIn('status', ['marchando', 'em_curso'])->exists(),
                    ];
                })->values(),
        ]);
    }

    public function atacar(Request $request, Atacar $atacar): JsonResponse
    {
        $dados = $request->validate([
            'zone_id' => ['required', 'integer', 'exists:neutral_zones,id'],
            'tipo' => ['required', Rule::in(['invasao', 'cerco', 'sabotagem', 'apreensao'])],
            'unit_ids' => ['required', 'array', 'min:1'],
            'unit_ids.*' => ['integer'],
            'alvo' => ['nullable', 'string', 'max:32'],
        ]);

        $colony = $request->user()->colony()->firstOrFail();
        $zona = NeutralZone::findOrFail($dados['zone_id']);

        $combate = $atacar->handle(
            $colony,
            $zona,
            $dados['tipo'],
            $dados['unit_ids'],
            $dados['alvo'] ?? null,
        );

        return response()->json([
            'id' => $combate->id,
            'tipo' => $combate->tipo,
            'status' => $combate->status,
            'chega_at' => $combate->chega_at,
        ], 201);
    }

    /**
     * Reforça uma zona sua (§27.5, D-70).
     *
     * ⚠️ **A tela já prometia isto e a ação não existia.** O Quartel dizia ao defensor "ainda dá tempo
     * de reforçar" e não havia rota nem botão — o motor contava reforços desde o D-66 e ninguém podia
     * mandá-los.
     */
    public function reforcar(Request $request, Reforcar $reforcar): JsonResponse
    {
        $dados = $request->validate([
            'zone_id' => ['required', 'integer', 'exists:neutral_zones,id'],
            'unit_ids' => ['required', 'array', 'min:1'],
            'unit_ids.*' => ['integer'],
        ]);

        $colony = $request->user()->colony()->firstOrFail();
        $zona = NeutralZone::findOrFail($dados['zone_id']);

        $n = $reforcar->handle($colony, $zona, $dados['unit_ids']);

        return response()->json(['marcharam' => $n], 201);
    }

    /**
     * Rompe um cerco (§28.10, D-70).
     *
     * O documento dá ao sitiado DUAS saídas — "romper o cerco ou render-se" — e o jogo só tinha uma:
     * esperar as 48 h e entregar 30%. Agora ele pode lutar.
     */
    public function romper(Request $request, RomperCerco $romper): JsonResponse
    {
        $dados = $request->validate([
            'combat_id' => ['required', 'integer', 'exists:combats,id'],
            'unit_ids' => ['required', 'array', 'min:1'],
            'unit_ids.*' => ['integer'],
        ]);

        $colony = $request->user()->colony()->firstOrFail();
        $cerco = Combat::findOrFail($dados['combat_id']);

        $r = $romper->handle($colony, $cerco, $dados['unit_ids']);

        return response()->json([
            'id' => $r->id,
            'status' => $r->status,
            'chega_at' => $r->chega_at,
        ], 201);
    }

    /**
     * As batalhas que envolvem esta colônia — atacando ou defendendo.
     *
     * O defensor precisa **ver** o ataque a caminho: é o desenho declarado do §27.5, que faz o
     * combate durar ~2 h justamente para dar "tempo suficiente para o defensor receber notificação,
     * recrutar reforços e despachá-los".
     */
    public function combates(Request $request, Protegido $protegido): JsonResponse
    {
        $colony = $request->user()->colony()->firstOrFail();

        $aviso = WarSetting::singleton()->torre_aviso_minutos_por_nivel;

        $combates = Combat::with('zone')
            ->where(fn ($q) => $q
                ->where('attacker_colony_id', $colony->id)
                ->orWhere('defender_colony_id', $colony->id))
            ->whereIn('status', ['marchando', 'em_curso'])
            ->orderBy('proxima_rodada_at')
            ->get()
            /*
             * ⚠️ **A Torre de Vigia decide o que o DEFENSOR vê, e essa é a mudança do D-67.**
             *
             * Até aqui o defensor via o ataque desde o instante em que ele era despachado — o que
             * tornava a Torre de Vigia **inútil**: o §17.4 lhe dá justamente o papel de "detectar a
             * aproximação de unidades inimigas com antecedência", e não há antecedência a ganhar
             * quando já se vê tudo.
             *
             * Agora: **sem Torre, o defensor só vê o inimigo quando ele chega.** Com Torre, vê
             * `10 min × nível` antes de a marcha terminar — no nível 5, na maioria das distâncias,
             * é ver o exército partir. É o que dá sentido ao §27.5, que fez o combate durar ~2 h
             * "para o defensor receber notificação, recrutar reforços e despachá-los": a notificação
             * agora é uma coisa que se **constrói**.
             *
             * O atacante vê sempre o próprio ataque — ele o mandou.
             */
            ->filter(function (Combat $c) use ($colony, $aviso) {
                if ($c->attacker_colony_id === $colony->id) {
                    return true;
                }

                $antecedencia = $aviso * $c->zone->nivelDe('torre_de_vigia');

                return $c->chega_at->copy()->subMinutes($antecedencia)->isPast();
            })
            ->values()
            ->map(fn (Combat $c) => [
                'id' => $c->id,
                'tipo' => $c->tipo,
                'status' => $c->status,
                'sou_o_atacante' => $c->attacker_colony_id === $colony->id,
                'zona' => ['id' => $c->zone->id, 'x' => $c->zone->x, 'y' => $c->zone->y],
                'rodada' => $c->rodada,
                'chega_at' => $c->chega_at,
                'proxima_rodada_at' => $c->proxima_rodada_at,
                'prazo_at' => $c->prazo_at,
                'alvo' => $c->alvo,
                'forca_ofensiva' => $c->resultado['forca_ofensiva'] ?? null,
                'forca_defensiva' => $c->resultado['forca_defensiva'] ?? null,
                // O que está em jogo: só o exposto é saqueável (D-66).
                'exposto' => $protegido->exposto($c->zone),
                // Cercada, nada entra nem sai — nem tropa (§28.10). É o que decide se a tela oferece
                // "reforçar" ou "romper o cerco": sob sítio, reforçar é impossível por desenho (D-70).
                'cercada' => $c->zone->cercada(),
            ]);

        return response()->json(['combats' => $combates]);
    }

    /**
     * O Ranking de Guerras (§27.13, D-128) — cinco sub-rankings normalizados por percentil, com o
     * Ranking Geral publicado. `mine` marca a linha da própria colônia, como o Mapa já faz (D-74).
     */
    public function ranking(Request $request, RankingDeGuerras $ranking): JsonResponse
    {
        $colony = $request->user()->colony()->first();

        $linhas = $ranking->geral()->map(fn (array $l) => array_merge($l, [
            'mine' => $colony !== null && $l['colony_id'] === $colony->id,
        ]));

        return response()->json(['ranking' => $linhas]);
    }
}
