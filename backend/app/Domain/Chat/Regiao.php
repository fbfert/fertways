<?php

namespace App\Domain\Chat;

use App\Domain\Logistics\MapaFertways;
use App\Models\Colony;

/**
 * As 5 regiões do chat regional (§10.1: "5 canais regionais" — o GDD publica o NÚMERO e cala o
 * mapa; D-77, arbitragem do usuário: 4 quadrantes + Núcleo).
 *
 * A geografia é a que o planeta JÁ TEM: os quatro distritos de zona neutra moram um em cada canto
 * (D-52), então cada QUADRANTE herda o nome do distrito que contém; e o Núcleo é o disco central
 * dos founders — raio 10 da Capital, a mesma vizinhança onde o jogo começa.
 *
 * A régua é a posição da COLÔNIA: a região muda se o dono for realocado, e é isso mesmo — o chat
 * regional é sobre onde você vive, não sobre onde nasceu.
 */
final class Regiao
{
    public const NOMES = [
        'nucleo' => 'Núcleo',
        'nordeste' => 'Nordeste',
        'sudeste' => 'Sudeste',
        'sudoeste' => 'Sudoeste',
        'noroeste' => 'Noroeste',
    ];

    public const RAIO_DO_NUCLEO = 10;

    public static function de(Colony $colony): string
    {
        if (MapaFertways::ateCapital($colony->x, $colony->y) <= self::RAIO_DO_NUCLEO) {
            return 'nucleo';
        }

        // Os eixos pertencem ao Leste/Norte por convenção (≥ 0): toda célula tem exatamente uma região.
        return match (true) {
            $colony->x >= 0 && $colony->y >= 0 => 'nordeste',
            $colony->x >= 0 => 'sudeste',
            $colony->y >= 0 => 'noroeste',
            default => 'sudoeste',
        };
    }
}
