<?php

namespace App\Domain\Trade;

/**
 * Os números da avaliação de comércio (D-287).
 *
 * O GDD dá as regras e nenhum número além do piso:
 * - §8.4: 0 a 5 estrelas, voluntária, pública, no perfil; a média "afeta a reputação";
 * - §26.3: só acima do piso de valor (o mesmo do D-43/D-117, `AcordoSpecs::PISO_REPUTACAO_MICRO`);
 * - §26 "frequência": uma avaliação ponderada por par de contas a cada 24 h;
 * - §26.4: contas possivelmente vinculadas ficam suspeitas; o vínculo confirmado zera o histórico
 *   mútuo, retroativamente;
 * - "presentes não geram estrelas": só Acordo de Troca se avalia.
 *
 * Os números abaixo são meus, ancorados no que o Acordo já move (D-43: +10 cumprido, −50 quebrado).
 * Cinco estrelas valem pouco mais que meio acordo cumprido; a nota zero, um quarto de calote. A
 * avaliação nunca pesa mais que o fato: quem levou calote já tirou 50 do outro pelo D-43.
 */
final class AvaliacaoSpecs
{
    /** Quanto cada nota move a Confiança Comercial do avaliado. */
    public const DELTA = [0 => -12, 1 => -8, 2 => -4, 3 => 0, 4 => 3, 5 => 6];

    /** Depois que o acordo fecha, quantos dias para avaliar. "Após a experiência" (§25.7), não para sempre. */
    public const JANELA_DIAS = 7;

    /** §26: "uma avaliação ponderada por par de contas a cada 24 horas". */
    public const FREQUENCIA_HORAS = 24;

    /** Até quantos dias para trás um IP em comum marca o par como possivelmente vinculado. */
    public const VINCULO_DIAS = 30;

    public const COMENTARIO_MAX = 280;

    /** Status de acordo que encerram "a experiência". */
    public const FECHADOS = ['executado', 'quebrado'];
}
