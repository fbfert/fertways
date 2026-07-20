<?php

namespace App\Http\Controllers\Api;

use App\Domain\Endurance\ComprarItem;
use App\Domain\Endurance\EfeitosDaEndurance;
use App\Domain\Marco\Curva;
use App\Exceptions\DomainRuleException;
use App\Http\Controllers\Controller;
use App\Models\Colony;
use App\Models\ColonyEnduranceItem;
use App\Models\EnduranceItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A Loja de Peças da Endurance (D-135) — uma loja POR SEÇÃO do casco, catálogo dinâmico, efeitos
 * empilháveis. Substitui `EnduranceController` do D-132/D-133 (catálogo fixo, um efeito só).
 */
class EnduranceController extends Controller
{
    /** Os itens de UMA seção — a loja que abre quando o jogador clica naquele destroço no mapa. */
    public function secao(Request $request, string $secao): JsonResponse
    {
        $colony = $this->colonia($request);
        $marco = Curva::marco((int) $colony->xp);

        $itens = EnduranceItem::where('secao', $secao)->with('efeitos')->orderBy('preco_micro')->get();

        $minhas = ColonyEnduranceItem::where('colony_id', $colony->id)
            ->pluck('quantidade', 'endurance_item_id');

        $catalogo = $itens->map(function (EnduranceItem $item) use ($marco, $minhas) {
            $possuo = (int) ($minhas[$item->id] ?? 0);

            $estado = match (true) {
                $item->esgotado() && $possuo === 0 => 'esgotado',
                $item->marco_minimo !== null && $marco < $item->marco_minimo => 'bloqueado',
                default => 'disponivel',
            };

            return [
                'item_key' => $item->item_key,
                'nome' => $item->nome,
                'tipo' => $item->tipo,
                'estoque_livre' => $item->estoqueLivre(),
                'quantidade_total' => $item->quantidade_total,
                'preco_fert' => $item->preco_micro / Colony::MICRO_POR_FERT,
                'marco_minimo' => $item->marco_minimo,
                'vendavel_em_leilao' => $item->vendavel_em_leilao,
                'descricao' => $item->descricao,
                'possuo' => $possuo,
                'estado' => $estado,
                'efeitos' => $item->efeitos->map(fn ($e) => [
                    'tipo_efeito' => $e->tipo_efeito,
                    'alvo' => $e->alvo,
                    'valor_bps' => $e->valor_bps,
                ]),
            ];
        })->values();

        return response()->json([
            'secao' => $secao,
            'meu_marco' => $marco,
            'itens' => $catalogo,
        ]);
    }

    /** Os efeitos ATIVOS da colônia hoje — para a tela mostrar "seu bônus atual" por tipo. */
    public function meusEfeitos(Request $request): JsonResponse
    {
        $colony = $this->colonia($request);
        $efeitos = app(EfeitosDaEndurance::class);

        return response()->json([
            'desconto_tributo_pct' => $efeitos->descontoDeTributo($colony) / 100,
            'teto_desconto_tributo_pct' => EfeitosDaEndurance::tetoBps(EfeitosDaEndurance::DESCONTO_TRIBUTO) / 100,
            'teto_producao_pct' => EfeitosDaEndurance::tetoBps(EfeitosDaEndurance::PRODUCAO_BONUS) / 100,
            'teto_veiculo_pct' => EfeitosDaEndurance::tetoBps(EfeitosDaEndurance::VELOCIDADE_VEICULO) / 100,
            'teto_drone_pct' => EfeitosDaEndurance::tetoBps(EfeitosDaEndurance::DRONE_RAIO) / 100,
        ]);
    }

    public function comprar(Request $request, string $item, ComprarItem $comprar): JsonResponse
    {
        $posse = $comprar->handle($this->colonia($request), $item);

        return response()->json(['item_key' => $item, 'quantidade' => $posse->quantidade], 201);
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
