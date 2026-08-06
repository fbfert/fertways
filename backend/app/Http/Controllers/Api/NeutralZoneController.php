<?php

namespace App\Http\Controllers\Api;

use App\Domain\Logistics\DespacharVeiculo;
use App\Domain\Logistics\OcuparZonaNeutra;
use App\Domain\Logistics\RequisitosDeOcupacao;
use App\Domain\Zona\Estruturas;
use App\Domain\Zona\SubirNivelDaZona;
use App\Http\Controllers\Controller;
use App\Models\FilaSetting;
use App\Models\NeutralZone;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * As zonas neutras (GDD §07, §24.4; docs/decisoes.md D-52, Fatia 1).
 *
 * Lista as 120 zonas e ocupa uma livre. A extração acontece no tick; a retirada da carga é pela
 * logística (despacho + retirada), como no Mercado.
 */
class NeutralZoneController extends Controller
{
    /**
     * As 120 zonas, com o que é público: célula, distrito, mineral, nível e o estado da ocupação.
     * Quem ocupa o quê é visível, como o diretório de colônias (D-37).
     *
     * ⚠️ **O INTERIOR de zona alheia é névoa desde o D-74** — guarnição e depósito vêm `null` para
     * quem nunca mandou um Drone lá. Null, e não zero: zero é um fato ("está indefesa"); null é a
     * honestidade de não saber. O `intel` diz com que olhos se vê (`dona`/`livre`/`ao_vivo`/`foto`/
     * `nenhuma`), e o `intel_em` data a foto — informação que envelhece é informação honesta.
     */
    public function index(Request $request, \App\Domain\Drone\Avistamentos $avistamentos): JsonResponse
    {
        $minha = $request->user()->colony()->first();

        $zonas = NeutralZone::with('owner:id,name,user_id')->orderBy('district')->orderBy('x')->orderBy('y')->get()
            ->map(function (NeutralZone $z) use ($minha, $avistamentos) {
                $visto = $minha
                    ? $avistamentos->de($minha, $z)
                    // Sem colônia não há drone nem direito a interior nenhum — só o público.
                    : ($z->owner_colony_id === null
                        ? ['intel' => 'livre', 'garrison' => $z->guarnicao(), 'deposit_amount' => (int) $z->deposit_amount, 'visto_em' => null]
                        : ['intel' => 'nenhuma', 'garrison' => null, 'deposit_amount' => null, 'visto_em' => null]);

                $mine = $minha && $z->owner_colony_id === $minha->id;

                return [
                    'id' => $z->id,
                    // Público, como o nome da colônia — quem passa o mouse na zona sabe de quem é.
                    'name' => $z->name,
                    'x' => $z->x,
                    'y' => $z->y,
                    'district' => $z->district,
                    'mineral' => $z->mineral,
                    'level' => $z->level,
                    'status' => $z->status,
                    // `user_id`: como no diretório de colônias (D-37) — quem é dono já é público.
                    // É o que deixa o mapa abrir a ficha do jogador (e o chat privado) a partir da zona.
                    'owner' => $z->owner ? ['id' => $z->owner->id, 'name' => $z->owner->name, 'user_id' => $z->owner->user_id] : null,
                    'mine' => $mine,
                    // Derivados do nível, que é público — esconder seria teatro: qualquer um calcula.
                    'deposit_cap' => $z->capacidadeDeposito(),
                    'extraction_per_hour' => $z->extracaoPorHora(),
                    'productive_at' => $z->productive_at,
                    // Os dois únicos segredos do interior, pelos olhos de quem pergunta (D-74).
                    'deposit_amount' => $visto['deposit_amount'],
                    'garrison' => $visto['garrison'],
                    'intel' => $visto['intel'],
                    'intel_em' => $visto['visto_em'],
                    // Upgrade e manutenção territorial (D-84) — só o dono vê o extrato financeiro;
                    // o EFEITO da manutenção em atraso (Pontos de Defesa perdidos) é real para
                    // qualquer atacante, mesmo sem ver o motivo.
                    'upgrade' => $mine ? [
                        'target' => $z->level_target,
                        'finishes_at' => $z->level_upgrade_finishes_at,
                        'proximo_custo' => $z->level < \App\Models\NeutralZone::NIVEL_MAXIMO
                            ? \App\Models\NeutralZone::custoDeUpgrade($z->level + 1)
                            : null,
                        'proxima_guarnicao' => $z->level < \App\Models\NeutralZone::NIVEL_MAXIMO
                            ? \App\Models\NeutralZone::guarnicaoAlvo($z->level + 1)
                            : null,
                    ] : null,
                    'manutencao' => $mine ? [
                        'custo_diario' => $z->custoDeManutencao(),
                        'proximo_vencimento' => $z->maintenance_next_due_at,
                        'inadimplente_desde' => $z->maintenance_unpaid_since,
                        'penalidade_bps' => $z->penalidadeManutencaoBps(),
                    ] : null,
                    // A fila de construção (D-125) — o painel do Mapa nunca mostrou isso, só a
                    // ficha inteira da zona (`/zones/{id}`) mostrava. Só o dono vê, como o resto
                    // da seção de obras (canteiro, upgrade) — de fora, é o mesmo segredo do D-74.
                    'obras' => $mine ? $z->obras()->orderBy('id')->get()->map(fn ($o) => [
                        'structure' => $o->structure,
                        'nome' => Estruturas::de($o->structure)['nome'],
                        'target_level' => $o->target_level,
                        'finishes_at' => $o->finishes_at,
                    ])->values() : null,
                    'obras_vagas' => $mine ? FilaSetting::singleton()->zona_vagas : null,
                ];
            });

        return response()->json(['zones' => $zonas]);
    }

    /**
     * O que ocupar exige, e o que falta a esta colônia (A2.V4, D-224).
     *
     * O painel do mapa anunciava o custo numa frase escrita à mão — que dizia 800 de Metal Bruto
     * para uma cobrança de 1.020, e não citava as 1.200 Ligas nem os 400 Componentes — e oferecia um
     * botão **sempre habilitado**. Agora a tela lê o mesmo código que o comando cobra.
     */
    public function requisitos(Request $request, RequisitosDeOcupacao $requisitos): JsonResponse
    {
        $colony = $request->user()->colony()->first();

        if (! $colony) {
            return response()->json(['message' => 'Funde uma colônia antes de ocupar zonas.'], 422);
        }

        return response()->json($requisitos->para($colony));
    }

    public function occupy(Request $request, NeutralZone $zone, OcuparZonaNeutra $ocupar): JsonResponse
    {
        $colony = $request->user()->colony()->first();

        if (! $colony) {
            return response()->json(['message' => 'Funde uma colônia antes de ocupar zonas.'], 422);
        }

        $zona = $ocupar->handle($colony, $zone);

        return response()->json([
            'id' => $zona->id,
            'x' => $zona->x,
            'y' => $zona->y,
            'mineral' => $zona->mineral,
            'status' => $zona->status,
            'garrison' => $zona->guarnicao(),
            'productive_at' => $zona->productive_at,
            'protected_until' => $zona->protected_until,
        ], 201);
    }

    /** Sobe o nível da zona (D-84): custo e guarnição na hora, o nível sobe no tick seguinte. */
    public function upgrade(Request $request, NeutralZone $zone, SubirNivelDaZona $subir): JsonResponse
    {
        $colony = $request->user()->colony()->first();

        if (! $colony) {
            return response()->json(['message' => 'Funde uma colônia antes de investir numa zona.'], 422);
        }

        $zona = $subir->handle($colony, $zone);

        return response()->json([
            'id' => $zona->id,
            'level' => $zona->level,
            'level_target' => $zona->level_target,
            'level_upgrade_finishes_at' => $zona->level_upgrade_finishes_at,
        ], 201);
    }

    /**
     * Retira o mineral extraído: um veículo seu vai à zona, carrega o Depósito e volta. O tributo
     * incide na chegada ao slot (§25.2), como qualquer entrega.
     */
    public function withdraw(Request $request, NeutralZone $zone, DespacharVeiculo $despachar): JsonResponse
    {
        $dados = $request->validate([
            'vehicle_id' => ['required', 'integer'],
            'cargo' => ['required', 'array', 'min:1'],
            'cargo.*' => ['integer', 'min:1'],
        ]);

        $colony = $request->user()->colony()->first();
        if (! $colony) {
            return response()->json(['message' => 'Funde uma colônia antes de retirar de zonas.'], 422);
        }

        $veiculo = Vehicle::whereKey($dados['vehicle_id'])->where('colony_id', $colony->id)->firstOrFail();

        $veiculo = $despachar->retirarDeZona($colony, $veiculo, $zone, $dados['cargo']);

        return response()->json([
            'id' => $veiculo->id,
            // Tipo e placa: mesma razão do D-80 na entrega de material — "um veículo a caminho"
            // não diz QUAL, numa colônia com mais de um Furgão.
            'type' => $veiculo->type,
            'plate' => $veiculo->plate,
            'status' => $veiculo->status,
            'leg' => $veiculo->leg,
            'trip_purpose' => $veiculo->trip_purpose,
            'distance_slots' => $veiculo->distance_slots,
            'arrives_at' => $veiculo->arrives_at,
            'cargo' => $veiculo->cargo_json,
        ], 201);
    }
}
