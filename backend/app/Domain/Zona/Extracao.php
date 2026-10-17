<?php

namespace App\Domain\Zona;

use App\Models\NeutralZone;
use App\Models\ZoneStructure;

/**
 * A Estrutura de Extração (GDD §17.4; D-290).
 *
 * O GDD diz só isto: "Varia conforme o tipo de recurso da zona: perfuratriz para minerais,
 * escavadeira para cristais". Não diz o que ela faz. Até aqui ela era inerte, de propósito (D-79):
 * a zona extrai sem ela desde a Fatia 1 (D-52), e travar a extração a uma ferramenta puniria as
 * zonas que já rendem.
 *
 * ## O que ela faz agora
 *
 * **Acelera a extração da zona, sem ser exigida.** Sem ela, a zona rende exatamente o que rendia;
 * com ela, rende mais. Ninguém perde nada, e erguer passa a valer a pena.
 *
 * - **+10% no nível 1**, crescendo pela curva do §19.1 (`× 1,5` por nível, como tudo o mais):
 *   10%, 15%, 22,5%, 33,75%, 50,6%.
 * - **É repetível** (D-144): cada cópia soma o bônus dela.
 * - **Teto de +100%**: a extração no máximo dobra. Sem teto, uma zona coberta de perfuratrizes
 *   renderia o que quisesse, e o slot de defesa deixaria de ter concorrência.
 *
 * Os números são meus. Eles só multiplicam a base arbitrada no D-52 (100/h); o depósito da zona é
 * infinito, então render mais não esgota nada antes da hora.
 *
 * ## O nome
 *
 * O §17.4 fala em perfuratriz e escavadeira, e o jogo não tem zona de cristal. Cada mineral ganha a
 * ferramenta que faz sentido para ele: perfuratriz no Metal Bruto, bomba de poço na Água,
 * condensador no Oxigênio, escavadeira na Biomassa.
 */
class Extracao
{
    /** O bônus de uma cópia no nível 1, em bps sobre a extração (1.000 = +10%). */
    public const BONUS_BASE_BPS = 1_000;

    /** O teto da soma das cópias (10.000 = a extração dobra). */
    public const TETO_BPS = 10_000;

    public const FERRAMENTA = [
        'metal_bruto' => 'Perfuratriz',
        'agua' => 'Bomba de Poço',
        'oxigenio' => 'Condensador Atmosférico',
        'biomassa' => 'Escavadeira',
    ];

    /** O bônus de uma cópia neste nível, em bps. */
    public static function bonusDoNivel(int $nivel): int
    {
        if ($nivel <= 0) {
            return 0;
        }

        return (int) round(self::BONUS_BASE_BPS * NeutralZone::CURVA ** ($nivel - 1));
    }

    /** O bônus somado das cópias desta zona, já com o teto. */
    public static function bonusBps(NeutralZone $zona): int
    {
        $soma = (int) $zona->zoneStructures
            ->where('type', 'estrutura_de_extracao')
            ->sum(fn (ZoneStructure $s) => self::bonusDoNivel((int) $s->level));

        return min(self::TETO_BPS, $soma);
    }

    /** O nome da ferramenta no mineral desta zona. */
    public static function ferramenta(?string $mineral): string
    {
        return self::FERRAMENTA[$mineral] ?? 'Perfuratriz';
    }
}
