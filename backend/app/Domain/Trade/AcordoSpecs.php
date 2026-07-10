<?php

namespace App\Domain\Trade;

use App\Domain\Logistics\VeiculoSpecs;
use App\Models\Colony;

/**
 * Os números do Acordo de Troca e da Confiança Comercial.
 *
 * **Nenhum deles sai do GDD.** O §26.5 exige um prazo e não publica limites; o §26.2 diz que
 * Confiança Comercial "baixa" bloqueia o Mercado Central e não publica nem o valor inicial, nem o
 * limiar, nem quanto vale cumprir um acordo. Todos foram arbitrados pelo usuário em 2026-07-09.
 * Ver docs/decisoes.md D-42 e D-43. Não os mude sem perguntar.
 */
final class AcordoSpecs
{
    /** Escala do §26.2: cada índice de reputação vai de 0 a 1000. */
    public const REPUTACAO_MIN = 0;

    public const REPUTACAO_MAX = 1000;

    /** D-43: o colono nasce no meio da escala, neutro. Nem confiável, nem suspeito. */
    public const CONFIANCA_INICIAL = 500;

    /** D-43: abaixo disto, o §26.2 fecha o Mercado Central para o colono. */
    public const LIMIAR_MERCADO = 200;

    /** D-43: cumprir rende pouco; calotear custa cinco vezes mais. */
    public const GANHO_CUMPRIDO = 10;

    public const PERDA_QUEBRADO = 50;

    /**
     * D-43: o piso anti-farming do §26.3, estendido ao Acordo — 500 F$ de valor de mercado somando
     * os dois lados. Abaixo disto o acordo registra histórico, mas não move o índice.
     */
    public const PISO_REPUTACAO_MICRO = 500 * Colony::MICRO_POR_FERT;

    /** D-42: folga somada ao tempo de viagem para formar o prazo mínimo propunível. */
    public const FOLGA_PRAZO_SEGUNDOS = 12 * 3600;

    /**
     * O veículo mais lento do catálogo (§21.3: Caminhão de Carga, 1,5 slot/min).
     *
     * O prazo mínimo deriva dele, não do mais rápido: o devedor pode não ter um Furgão, e um prazo
     * que só um Furgão alcança seria calote fabricado por quem propôs.
     */
    public const VEICULO_MAIS_LENTO = 'caminhao_de_carga';

    /** Prazo mínimo, em segundos, para um acordo entre estas duas colônias (D-42). */
    public static function prazoMinimoSegundos(int $distanciaSlots): int
    {
        return VeiculoSpecs::segundosDoTrecho(self::VEICULO_MAIS_LENTO, $distanciaSlots)
            + self::FOLGA_PRAZO_SEGUNDOS;
    }

    /** Mantém o índice dentro da escala do §26.2. */
    public static function limitar(int $valor): int
    {
        return max(self::REPUTACAO_MIN, min(self::REPUTACAO_MAX, $valor));
    }

    /**
     * Quanto é preciso **despachar** para que `$liquido` chegue, dado o tributo do recurso (D-41).
     *
     * `ConcluirTrechos` entrega `g - intdiv(g * bps, 10_000)`. O `intdiv` trunca, então a inversa
     * não tem forma fechada: a estimativa por regra de três **erra nos dois sentidos**. Para
     * líquido 1 e alíquota de 3% ela devolve 2, quando 1 já chega inteiro (o tributo trunca a
     * zero). Corrigimos descendo enquanto sobrar, e subindo enquanto faltar.
     *
     * Errar para menos faria a UI prometer uma carga que chega curta — a armadilha que o D-41
     * manda evitar. Errar para mais cobraria tributo a mais de quem cumpre.
     */
    public static function brutoParaLiquido(int $liquido, int $taxBps): int
    {
        if ($liquido <= 0) {
            return 0;
        }

        $chega = fn (int $bruto): int => $bruto - intdiv($bruto * $taxBps, 10_000);

        $bruto = max($liquido, (int) ceil($liquido * 10_000 / (10_000 - $taxBps)));

        while ($bruto > $liquido && $chega($bruto - 1) >= $liquido) {
            $bruto--;
        }

        while ($chega($bruto) < $liquido) {
            $bruto++;
        }

        return $bruto;
    }
}
