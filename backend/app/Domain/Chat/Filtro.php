<?php

namespace App\Domain\Chat;

use App\Models\ChatSetting;

/**
 * O filtro automático de termos (§10.2; D-77).
 *
 * A arbitragem do usuário: o filtro **bloqueia o envio e avisa** — nada de asteriscos (mensagem
 * censurada pela metade ainda comunica) e nada de publicar-e-sinalizar (filtro que não barra é
 * termômetro, não barreira). E ele NÃO cala ninguém sozinho: silêncio é pena, com autor humano.
 *
 * O §03 usa este mesmo filtro para o nickname — é por isso que ele é uma classe, não um if.
 */
final class Filtro
{
    /** @return string|null o termo que barrou, ou null se o texto passa */
    public static function barra(string $texto): ?string
    {
        $minusculo = mb_strtolower($texto);

        foreach (ChatSetting::singleton()->termos() as $termo) {
            if ($termo !== '' && mb_strpos($minusculo, $termo) !== false) {
                return $termo;
            }
        }

        return null;
    }
}
