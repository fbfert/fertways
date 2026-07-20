<?php

namespace App\Http\Controllers\Api;

use App\Domain\Leilao\CancelarLeilao;
use App\Domain\Leilao\DarLance;
use App\Domain\Leilao\ListarLeilao;
use App\Exceptions\DomainRuleException;
use App\Http\Controllers\Controller;
use App\Models\Auction;
use App\Models\Colony;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Leilões (D-129) — desenho nosso, sem seção no GDD. O texto só cita leilões como alvo de uma
 * punição (§9.4/D-49/D-50: "reputação negativa bloqueia acesso"); o mecanismo — lote único, lance
 * escrow, fechamento por prazo — reaproveita o Mercado Central ponta a ponta.
 */
class AuctionController extends Controller
{
    /** A vitrine dos leilões abertos, mais os que esta colônia anunciou ou deu lance (qualquer status). */
    public function index(Request $request): JsonResponse
    {
        $colony = $this->colonia($request);

        $abertos = Auction::where('status', 'aberto')
            ->with(['colony:id,name', 'lanceColony:id,name'])
            ->orderBy('deadline_at')
            ->get()
            ->map(fn (Auction $a) => $this->linha($a, $colony));

        $minhas = Auction::where(fn ($q) => $q
                ->where('colony_id', $colony->id)
                ->orWhere('lance_colony_id', $colony->id))
            ->with(['colony:id,name', 'lanceColony:id,name'])
            ->orderByDesc('updated_at')
            ->limit(20)
            ->get()
            ->map(fn (Auction $a) => $this->linha($a, $colony));

        return response()->json(['abertos' => $abertos, 'minhas' => $minhas]);
    }

    private function linha(Auction $a, Colony $colony): array
    {
        $minimoProximoLance = $a->lance_atual_micro !== null ? $a->lance_atual_micro + 1 : $a->lance_minimo_micro;

        return [
            'id' => $a->id,
            'resource_type' => $a->resource_type,
            'qty' => (int) $a->qty,
            'colony_id' => $a->colony_id,
            'colonia' => $a->colony?->name,
            'minha' => $a->colony_id === $colony->id,
            'lance_minimo_fert' => $a->lance_minimo_micro / Colony::MICRO_POR_FERT,
            'lance_atual_fert' => $a->lance_atual_micro !== null ? $a->lance_atual_micro / Colony::MICRO_POR_FERT : null,
            'proximo_lance_minimo_fert' => $minimoProximoLance / Colony::MICRO_POR_FERT,
            'lance_colony_id' => $a->lance_colony_id,
            'lance_colonia' => $a->lanceColony?->name,
            'meu_lance' => $a->lance_colony_id === $colony->id,
            'status' => $a->status,
            'deadline_at' => $a->deadline_at,
        ];
    }

    /** Anuncia um leilão. Exige o lote já entregue na doca (§25.8, como o Mercado Central). */
    public function store(Request $request, ListarLeilao $listar): JsonResponse
    {
        $dados = $request->validate([
            'resource_type' => ['required', 'string'],
            'qty' => ['required', 'integer', 'min:1'],
            'lance_minimo_fert' => ['required', 'numeric', 'min:0.000001'],
            'duracao_horas' => ['required', 'integer', 'min:'.ListarLeilao::DURACAO_MIN_HORAS, 'max:'.ListarLeilao::DURACAO_MAX_HORAS],
        ]);

        $micro = (int) round($dados['lance_minimo_fert'] * Colony::MICRO_POR_FERT);

        $leilao = $listar->handle(
            $this->colonia($request),
            $dados['resource_type'],
            $dados['qty'],
            $micro,
            $dados['duracao_horas'],
        );

        return response()->json(['id' => $leilao->id, 'status' => $leilao->status, 'deadline_at' => $leilao->deadline_at], 201);
    }

    /** Dá um lance. Escrowa o Fert$ na hora; quem for superado recebe de volta no mesmo instante. */
    public function lance(Request $request, Auction $auction, DarLance $darLance): JsonResponse
    {
        $dados = $request->validate(['lance_fert' => ['required', 'numeric', 'min:0.000001']]);

        $micro = (int) round($dados['lance_fert'] * Colony::MICRO_POR_FERT);

        $leilao = $darLance->handle($this->colonia($request), $auction->id, $micro);

        return response()->json([
            'id' => $leilao->id,
            'lance_atual_fert' => $leilao->lance_atual_micro / Colony::MICRO_POR_FERT,
        ]);
    }

    /** Cancela um leilão seu, só enquanto ninguém deu lance. */
    public function destroy(Request $request, Auction $auction, CancelarLeilao $cancelar): JsonResponse
    {
        $leilao = $cancelar->handle($this->colonia($request), $auction);

        return response()->json(['id' => $leilao->id, 'status' => $leilao->status]);
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
