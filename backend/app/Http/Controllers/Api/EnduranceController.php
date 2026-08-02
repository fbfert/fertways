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
use App\Models\EnduranceItemInstance;
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

        /*
         * A2.9: a identidade e a biografia dos itens ÚNICOS desta seção.
         *
         * ⚠️ Sem isto, o §11.1 seria letra morta na tela: o item teria história no banco e o jogador
         * veria só mais uma peça. "Identidade persistente" que ninguém enxerga não é identidade.
         */
        $instancias = EnduranceItemInstance::whereIn('endurance_item_id', $itens->pluck('id'))
            ->with(['descobridor:id,name', 'dono:id,name', 'historico'])
            ->get()
            ->keyBy('endurance_item_id');

        $catalogo = $itens->map(function (EnduranceItem $item) use ($marco, $minhas, $instancias, $colony) {
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

                /*
                 * Nulo para comum e raro — eles são fungíveis, e não têm biografia nenhuma.
                 *
                 * O descobridor aparece MESMO quando o item já é de outro: é a origem que ninguém
                 * pode reescrever, e é ela que dá valor ao único.
                 */
                'unico' => ($i = $instancias->get($item->id)) === null ? null : [
                    'selo' => $i->selo,
                    'descobridor' => $i->descobridor?->name,
                    'descoberto_em' => $i->descoberto_em?->toIso8601String(),
                    'dono' => $i->dono?->name,
                    'e_meu' => (int) $i->colony_id === (int) $colony->id,
                    // Em escrow de leilão: saiu de uma mão e ainda não chegou na outra.
                    'em_leilao' => $i->colony_id === null,
                    'trocas' => max(0, $i->historico->count() - 1),
                ],
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

    /**
     * Os itens que esta colônia possui, DE QUALQUER SEÇÃO, e que podem ser anunciados em Leilão
     * (D-135, Fase 2) — alimenta o formulário de anunciar leilão do Mercado Central, que não tem
     * como saber sozinho quais das 8 seções o jogador já visitou.
     */
    public function meusItensVendaveis(Request $request): JsonResponse
    {
        $colony = $this->colonia($request);

        $itens = ColonyEnduranceItem::where('colony_id', $colony->id)
            ->where('quantidade', '>', 0)
            ->with('item')
            ->get()
            ->filter(fn (ColonyEnduranceItem $p) => $p->item !== null && $p->item->vendavel_em_leilao)
            ->map(fn (ColonyEnduranceItem $p) => [
                'item_key' => $p->item->item_key,
                'nome' => $p->item->nome,
                'secao' => $p->item->secao,
                'quantidade' => $p->quantidade,
            ])
            ->values();

        return response()->json(['itens' => $itens]);
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
