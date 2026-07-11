<?php

namespace App\Http\Controllers\Api;

use App\Domain\Logistics\MapaFertways;
use App\Domain\Trade\AceitarOferta;
use App\Domain\Trade\AcordoSpecs;
use App\Domain\Trade\ConfirmarAcordo;
use App\Domain\Trade\ProporAcordo;
use App\Exceptions\DomainRuleException;
use App\Http\Controllers\Controller;
use App\Models\Colony;
use App\Models\ResourceType;
use App\Models\TradeAgreement;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Acordo de Troca — GDD §26.5. Ver docs/decisoes.md D-40 a D-43.
 *
 * O Acordo não tem escrow: estas rotas registram promessas, não movem recursos. O que move recurso
 * é o despacho de veículo, e é ele que abate a promessa ao chegar.
 */
class TradeController extends Controller
{
    /** Os acordos em que esta colônia é parte, dos mais recentes aos mais antigos. */
    public function index(Request $request): JsonResponse
    {
        $colonia = $this->colonia($request);

        $acordos = TradeAgreement::where('colony_a_id', $colonia->id)
            ->orWhere('colony_b_id', $colonia->id)
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'confianca_comercial' => $request->user()->confianca_comercial,
            'limiar_mercado' => AcordoSpecs::LIMIAR_MERCADO,
            'agreements' => $acordos->map(fn (TradeAgreement $a) => $this->exibir($a, $colonia->id))->values(),
        ]);
    }

    /**
     * O prazo mínimo propunível para um acordo com esta colônia (D-42): a viagem do veículo mais
     * lento, mais 12 h. A UI precisa dele antes de o colono escolher uma data.
     */
    public function prazoMinimo(Request $request): JsonResponse
    {
        $dados = $request->validate(['counterparty_id' => ['required', 'integer']]);

        $colonia = $this->colonia($request);
        $outra = Colony::find($dados['counterparty_id']);

        if (! $outra || $outra->id === $colonia->id) {
            throw new DomainRuleException('contraparte_invalida', 'Colônia de contraparte inexistente.');
        }

        $distancia = MapaFertways::distancia($colonia->x, $colonia->y, $outra->x, $outra->y);
        $segundos = AcordoSpecs::prazoMinimoSegundos($distancia);

        return response()->json([
            'distance_slots' => $distancia,
            'minimum_seconds' => $segundos,
            'minimum_deadline_at' => now()->addSeconds($segundos),
        ]);
    }

    /**
     * O mural: as ofertas abertas de todos os colonos (D-58).
     *
     * Não é o `index`, que só mostra o que é seu. Aqui está o que os outros estão propondo a quem
     * quiser — sem contraparte definida, primeiro a aceitar leva.
     */
    public function mural(Request $request): JsonResponse
    {
        $colonia = $this->colonia($request);

        $ofertas = TradeAgreement::whereNull('colony_b_id')
            ->where('status', 'proposto')
            ->where('deadline_at', '>', now())
            ->with('colonyA:id,name')
            ->orderByDesc('id')
            ->get();

        $aberto = (string) ProporAcordo::LADO_ABERTO;

        return response()->json([
            'ofertas' => $ofertas->map(fn (TradeAgreement $o) => [
                'id' => $o->id,
                'colony_id' => $o->colony_a_id,
                'colonia' => $o->colonyA?->name,
                'minha' => $o->colony_a_id === $colonia->id,
                // "Ele dá" e "ele quer", da perspectiva de quem lê o mural.
                'oferece' => $o->terms_json[(string) $o->colony_a_id] ?? [],
                'quer' => $o->terms_json[$aberto] ?? [],
                'deadline_at' => $o->deadline_at,
                'value_micro' => (int) $o->value_micro,
            ])->values(),
        ]);
    }

    /**
     * Propõe um acordo. `counterparty_id` é **opcional** desde o D-58: sem ele, a oferta vai ao
     * mural, aberta a quem quiser.
     */
    public function store(Request $request, ProporAcordo $propor): JsonResponse
    {
        $dados = $request->validate([
            'counterparty_id' => ['sometimes', 'nullable', 'integer'],
            'deadline_at' => ['required', 'date'],
            'i_promise' => ['required', 'array', 'min:1'],
            'i_promise.*' => ['integer', 'min:1'],
            'they_promise' => ['required', 'array', 'min:1'],
            'they_promise.*' => ['integer', 'min:1'],
        ]);

        $colonia = $this->colonia($request);
        $outra = null;

        if (! empty($dados['counterparty_id'])) {
            $outra = Colony::find($dados['counterparty_id']);

            if (! $outra) {
                throw new DomainRuleException('contraparte_invalida', 'Colônia de contraparte inexistente.');
            }
        }

        $acordo = $propor->handle(
            $colonia,
            $outra,
            $dados['i_promise'],
            $dados['they_promise'],
            Carbon::parse($dados['deadline_at']),
        );

        return response()->json($this->exibir($acordo, $colonia->id), 201);
    }

    /** Aceita uma oferta do mural: quem chega primeiro vira a contraparte (D-58). */
    public function aceitar(Request $request, TradeAgreement $agreement, AceitarOferta $aceitar): JsonResponse
    {
        $colonia = $this->colonia($request);

        return response()->json($this->exibir($aceitar->handle($colonia, $agreement), $colonia->id));
    }

    public function confirm(Request $request, TradeAgreement $agreement, ConfirmarAcordo $confirmar): JsonResponse
    {
        $colonia = $this->colonia($request);

        return response()->json($this->exibir($confirmar->handle($colonia, $agreement), $colonia->id));
    }

    public function destroy(Request $request, TradeAgreement $agreement, ConfirmarAcordo $confirmar): JsonResponse
    {
        $colonia = $this->colonia($request);

        return response()->json($this->exibir($confirmar->cancelar($colonia, $agreement), $colonia->id));
    }

    /**
     * A visão do acordo pelos olhos de `$euId`.
     *
     * `gross_needed` é o D-41 em ato: o que falta entregar é líquido, mas quem despacha precisa
     * embarcar mais, porque o tributo do §25.2 come a carga na chegada. A UI mostra o número
     * pronto — ninguém deve descobrir que caloteou por três unidades de tributo.
     */
    private function exibir(TradeAgreement $a, int $euId): array
    {
        $bps = ResourceType::pluck('tax_bps', 'code');
        $outroId = $a->contraparte($euId);

        $falta = [];
        $bruto = [];

        foreach ($a->prometido($euId) as $recurso => $qtd) {
            $restante = max(0, $qtd - ($a->entregue($euId)[$recurso] ?? 0));

            if ($restante > 0) {
                $falta[$recurso] = $restante;
                $bruto[$recurso] = AcordoSpecs::brutoParaLiquido($restante, (int) ($bps[$recurso] ?? 0));
            }
        }

        return [
            'id' => $a->id,
            'status' => $a->status,
            'proposed_by_me' => $a->proposer_colony_id === $euId,
            'counterparty_id' => $outroId,
            'deadline_at' => $a->deadline_at,
            'accepted_at' => $a->accepted_at,
            'executed_at' => $a->executed_at,
            'i_promise' => $a->prometido($euId),
            'they_promise' => $a->prometido($outroId),
            'i_delivered' => $a->entregue($euId),
            'they_delivered' => $a->entregue($outroId),
            'i_still_owe' => $falta,
            'gross_needed' => $bruto,
            'value_micro' => $a->value_micro,
            // Abaixo do piso do §26.3 o acordo vale como registro, mas não move reputação (D-43).
            'moves_reputation' => $a->value_micro >= AcordoSpecs::PISO_REPUTACAO_MICRO,
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
