<?php

namespace App\Domain\Guerra;

use App\Models\NeutralZone;
use App\Models\ZoneMaterial;

/**
 * O que é "estoque protegido" (docs/decisoes.md D-66).
 *
 * A expressão aparece **seis vezes** no GDD — o saque de 50% da Invasão Direta (§27.8), os 30% do
 * Cerco (§28.10), o alvo do Predador — e o mecanismo que protege **nunca é descrito em lugar
 * nenhum**. Era a lacuna 4 do D-52.
 *
 * Arbitrado: **está protegido o que couber na capacidade do Depósito de Zona Neutra** no nível
 * construído. O §19.6 publica essa capacidade (`500 … 19.222`), então não se inventa número
 * nenhum — e subir o Depósito passa a ter um sentido econômico que não tinha.
 *
 * O que EXCEDE a capacidade está exposto: o Depósito é o cofre, e o que transborda é butim.
 *
 * ⚠️ **Isto custou uma revisão da Fatia 1, e vale saber por quê.** A extração parava no teto do
 * Depósito (`min($unidades, $espaco)`), então o `deposit_amount` nunca o excedia — nada jamais
 * estaria exposto, e **o saque de 50% seria sempre zero**. A guerra não teria espólio nenhum. O
 * usuário decidiu (2026-07-12) que a extração **deixa de parar no teto**: o excedente empilha ao
 * relento na zona. Contraria o §19.6, que chama aqueles números de "capacidade", e é deliberado.
 *
 * O efeito no jogo é o que se queria: deixar mineral rendendo na zona vira risco de verdade,
 * retirá-lo vira hábito, e subir o Depósito vale a pena porque protege mais.
 */
class Protegido
{
    /**
     * Quanto do estoque da zona está a salvo de saque.
     *
     * Conta o **total** — o minério bruto e o que a Refinaria de Campo já converteu (D-67). Os dois
     * ocupam o mesmo Depósito. **Refinar não esconde recurso do inimigo**, só o torna mais valioso:
     * seria estranho que passar o minério por uma refinaria o tirasse do alcance de quem invade.
     */
    public function protegido(NeutralZone $zona): int
    {
        return min($zona->estoqueTotal(), $zona->capacidadeDeposito());
    }

    /** Quanto está exposto — é sobre isto que incidem os 50% e os 30%. */
    public function exposto(NeutralZone $zona): int
    {
        return max(0, $zona->estoqueTotal() - $zona->capacidadeDeposito());
    }

    /**
     * O butim de um saque, repartido entre o minério bruto, o refinado e os minerais da Indústria
     * Siderúrgica (D-82) — todos no mesmo Depósito desde o D-82.
     *
     * A repartição é **proporcional ao que há de cada um**: quem invade uma zona metade refinada leva
     * metade de cada. O contrário — levar primeiro o refinado, que vale mais — premiaria o atacante
     * por uma escolha do defensor, e ninguém decidiu isso.
     *
     * O canteiro (`zone_materials`, D-67) entra numa conta À PARTE, 100% exposta — revisão de
     * 2026-07-19. Ele nunca foi o que o Depósito protege (não é minério extraído, é material
     * importado à espera de virar construção), então não compete pela capacidade do Depósito nem
     * pelo `estoqueTotal()`: toda unidade dele perde a MESMA fração `$bps` do resto do saque,
     * sempre — não existe "canteiro protegido".
     *
     * @param  int  $bps  5000 na Invasão Direta (§27.8), 3000 no Cerco (§28.10).
     * @return array{bruto: int, refinado: int, minerais: array<string,int>, canteiro: array<string,int>, total: int}
     */
    public function saqueDetalhado(NeutralZone $zona, int $bps): array
    {
        $vazio = ['bruto' => 0, 'refinado' => 0, 'minerais' => [], 'canteiro' => [], 'total' => 0];

        $total = intdiv($this->exposto($zona) * $bps, 10000);
        $estoque = $zona->estoqueTotal();

        if ($total > 0 && $estoque > 0) {
            // Proporcional a cada pote, do mais valioso ao menos — cada um absorve o arredondamento
            // do que vem depois, e o bruto (o menos valioso) absorve o resto de todos. O atacante
            // não deve ganhar uma unidade a mais do que vale mais por um resto de divisão.
            $restante = $total;
            $minerais = [];

            foreach ($zona->minerais as $m) {
                $levado = min($m->amount, intdiv($total * $m->amount, $estoque));
                if ($levado > 0) {
                    $minerais[$m->resource_type] = $levado;
                }
                $restante -= $levado;
            }

            $refinado = min($zona->refined_amount, intdiv($total * $zona->refined_amount, $estoque));
            $restante -= $refinado;
            $bruto = min($zona->deposit_amount, $restante);
        } else {
            $bruto = 0;
            $refinado = 0;
            $minerais = [];
        }

        $canteiro = [];
        foreach (ZoneMaterial::where('zone_id', $zona->id)->get() as $material) {
            $levado = intdiv($material->amount * $bps, 10_000);
            if ($levado > 0) {
                $canteiro[$material->resource_type] = $levado;
            }
        }

        return [
            'bruto' => $bruto,
            'refinado' => $refinado,
            'minerais' => $minerais,
            'canteiro' => $canteiro,
            'total' => $bruto + $refinado + array_sum($minerais) + array_sum($canteiro),
        ];
    }

    /** O butim total, em unidades. Atalho de `saqueDetalhado()`. */
    public function saque(NeutralZone $zona, int $bps): int
    {
        return $this->saqueDetalhado($zona, $bps)['total'];
    }
}
