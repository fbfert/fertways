<?php

namespace App\Http\Controllers\Api;

use App\Domain\Logistics\VeiculoSpecs;
use App\Domain\Transport\ComprarVeiculo;
use App\Domain\Transport\Conservacao;
use App\Domain\Transport\Manutencao;
use App\Domain\Transport\MercadoDeUsados;
use App\Domain\Transport\Ministerio;
use App\Domain\Transport\Sucatear;
use App\Domain\Transport\Vagas;
use App\Http\Controllers\Controller;
use App\Models\Colony;
use App\Models\Vehicle;
use App\Models\VehicleListing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * O Ministério dos Transportes (§16), na vista do colono — slot 8 da Capital (D-60).
 *
 * A fábrica (fatia 1), o registro de placas com o estado de conservação (fatia 2), a manutenção e a
 * sucata, e o mercado de usados (fatia 3).
 */
class TransportController extends Controller
{
    public function __construct(
        private readonly Vagas $vagas,
        private readonly ComprarVeiculo $comprar,
        private readonly Conservacao $conservacao,
        private readonly Manutencao $manutencao,
        private readonly Sucatear $sucatear,
        private readonly MercadoDeUsados $usados,
    ) {}

    /** GET /central/transport — a vitrine, a frota do colono e o resumo público do planeta. */
    public function index(Request $request): JsonResponse
    {
        $colony = $this->colonia($request);

        $fabrica = [];
        foreach (Ministerio::TIPOS as $tipo) {
            $config = Ministerio::config($tipo);
            $fabrica[$tipo] = [
                'tipo' => $tipo,
                'preco_fert' => $config['preco_micro'] / Colony::MICRO_POR_FERT,
                'capacidade' => VeiculoSpecs::CAPACIDADE[$tipo],
                'em_estoque' => Vehicle::whereNull('colony_id')->where('type', $tipo)->where('status', 'estoque')->count(),
                'em_fabricacao' => Vehicle::whereNull('colony_id')->where('type', $tipo)->where('status', 'fabricando')->count(),
                'minutos_fabricacao' => $config['minutos_fabricacao'],
            ];
        }

        return response()->json([
            'fabrica' => $fabrica,
            'frota' => [
                'teto' => $this->vagas->teto($colony),
                'ocupadas' => $this->vagas->ocupadas($colony),
                'livres' => $this->vagas->livres($colony),
                'regra' => 'O teto é o nível da sua Central de Transportes (mínimo 1).',
            ],
            'veiculos' => $colony->vehicles()->orderBy('id')->get()->map(
                fn (Vehicle $v) => $this->registro($v),
            ),
            // §16, 6ª atribuição do painel: "visualizar volume de veículos registrados, vendidos e
            // sucateados por período". O painel completo é do operador; ao colono vai o resumo.
            'planeta' => $this->resumoPublico(),
        ]);
    }

    /** O Registro de Veículo do §16.3, com os campos que o GDD desenha. */
    private function registro(Vehicle $v): array
    {
        $conservacao = (int) $v->conservacao_bps;

        return [
            'id' => $v->id,
            'placa' => $v->plate,
            'tipo' => $v->type,
            'status' => $v->status,
            'chega_em' => $v->arrives_at?->toIso8601String(),

            // §16.3: "horas de uso ativo" e "estado de conservação" são campos do registro.
            'horas_de_uso' => intdiv((int) $v->uso_ativo_seg, 3_600),
            'conservacao' => $conservacao / 100,
            'teto_conservacao' => (int) $v->teto_conservacao_bps / 100,
            'manutencoes' => (int) $v->manutencoes,

            // O que o desgaste FAZ, e não só o número: velocidade e capacidade encolhem com ele.
            'desempenho' => $this->conservacao->desempenhoBps($v) / 100,
            'capacidade_efetiva' => $this->conservacao->capacidadeEfetiva($v),

            'deprecia' => $this->conservacao->deprecia($v),
            'custo_manutencao' => $this->conservacao->deprecia($v) ? $this->manutencao->custo($v) : null,
            'pode_reparar' => $this->conservacao->deprecia($v)
                && $v->status === 'ocioso'
                && $conservacao < (int) $v->teto_conservacao_bps,

            'teto_de_revenda_fert' => ($teto = $this->conservacao->tetoDeRevendaMicro($v)) !== null
                ? $teto / Colony::MICRO_POR_FERT
                : null,
            'anunciado' => VehicleListing::where('vehicle_id', $v->id)->where('status', 'aberto')->exists(),
        ];
    }

    /**
     * O resumo público do planeta — a 6ª atribuição do painel do §16, na porção que vai ao colono.
     *
     * Os sucateados são **contados, não deduzidos**: a sucata arquiva o veículo em vez de apagá-lo
     * (`SoftDeletes` no `Vehicle`), então o registro do Ministério sabe exatamente quantos morreram
     * e quando. Uma versão anterior disto tentava inferi-los por buracos na sequência de placas, e
     * era frágil e errada.
     */
    private function resumoPublico(): array
    {
        return [
            'veiculos_registrados' => Vehicle::whereNotNull('plate')->count(),
            'vendidos' => VehicleListing::where('status', 'concluido')->count(),
            'sucateados' => Vehicle::onlyTrashed()->count(),
        ];
    }

    /** POST /central/transport/buy — compra um Caminhão novo da prateleira do governo. */
    public function comprar(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'tipo' => ['required', 'string', Rule::in(Ministerio::TIPOS)],
        ]);

        $veiculo = $this->comprar->handle($this->colonia($request), $dados['tipo']);

        return response()->json([
            'comprado' => [
                'id' => $veiculo->id,
                'placa' => $veiculo->plate,
                'tipo' => $veiculo->type,
                'a_caminho' => true,
                'chega_em' => $veiculo->arrives_at?->toIso8601String(),
            ],
        ], 201);
    }

    /** POST /central/transport/vehicles/{v}/maintain — manutenção na Central de Transportes. */
    public function reparar(Request $request, Vehicle $vehicle): JsonResponse
    {
        $reparado = $this->manutencao->handle($this->colonia($request), $vehicle);

        return response()->json(['veiculo' => $this->registro($reparado)]);
    }

    /**
     * POST /central/transport/vehicles/{v}/upgrade — sobe o nível (A2.7).
     *
     * `vehicles.level` existia desde sempre **sem caminho para subir**. Esta é a rota que faltava.
     * Capacidade sobe e manutenção sobe junto — a contrapartida que torna o upgrade uma escolha
     * econômica, e não um aumento nominal. Velocidade não é tocada: é traço do tipo.
     */
    public function melhorar(Request $request, Vehicle $vehicle): JsonResponse
    {
        $melhorado = app(\App\Domain\Transport\UpgradeVeiculo::class)
            ->handle($this->colonia($request), $vehicle);

        return response()->json(['veiculo' => $this->registro($melhorado)]);
    }

    /** DELETE /central/transport/vehicles/{v} — sucatear. Sem devolução (D-60). */
    public function sucatear(Request $request, Vehicle $vehicle): JsonResponse
    {
        $this->sucatear->handle($this->colonia($request), $vehicle);

        return response()->json(['sucateado' => true]);
    }

    // ------------------------------------------------------------------ mercado de usados

    /** GET /central/transport/listings — a vitrine dos usados. */
    public function anuncios(Request $request): JsonResponse
    {
        $colony = $this->colonia($request);

        $abertos = VehicleListing::with(['vehicle', 'vendedor'])
            ->where('status', 'aberto')
            ->orderBy('id')
            ->get()
            ->filter(fn (VehicleListing $a) => $a->vehicle !== null);

        return response()->json([
            'anuncios' => $abertos->map(fn (VehicleListing $a) => [
                'id' => $a->id,
                'preco_fert' => $a->price_micro / Colony::MICRO_POR_FERT,
                'meu' => $a->seller_colony_id === $colony->id,
                'vendedor' => $a->vendedor?->name,
                // O estado de conservação "afeta diretamente o preço de venda no mercado de usados"
                // (§16.4) — então ele tem de estar no anúncio, ou o comprador compra às cegas.
                'veiculo' => $this->registro($a->vehicle),
            ])->values(),
        ]);
    }

    /** POST /central/transport/listings — anuncia um veículo seu. */
    public function anunciar(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'vehicle_id' => ['required', 'integer'],
            'preco_fert' => ['required', 'numeric', 'min:0.000001'],
        ]);

        $colony = $this->colonia($request);
        $veiculo = Vehicle::findOrFail($dados['vehicle_id']);
        $micro = (int) round($dados['preco_fert'] * Colony::MICRO_POR_FERT);

        $anuncio = $this->usados->anunciar($colony, $veiculo, $micro);

        return response()->json(['anuncio' => ['id' => $anuncio->id]], 201);
    }

    /** POST /central/transport/listings/{a}/buy — compra um usado. Escrow até a chegada. */
    public function comprarUsado(Request $request, VehicleListing $listing): JsonResponse
    {
        $veiculo = $this->usados->comprar($this->colonia($request), $listing);

        return response()->json([
            'comprado' => [
                'id' => $veiculo->id,
                'placa' => $veiculo->plate,
                'tipo' => $veiculo->type,
                'a_caminho' => true,
                'chega_em' => $veiculo->arrives_at?->toIso8601String(),
            ],
        ], 201);
    }

    /** DELETE /central/transport/listings/{a} — desiste do anúncio. */
    public function cancelarAnuncio(Request $request, VehicleListing $listing): JsonResponse
    {
        $this->usados->cancelar($this->colonia($request), $listing);

        return response()->json(['cancelado' => true]);
    }

    private function colonia(Request $request): Colony
    {
        return $request->user()->colony;
    }
}
