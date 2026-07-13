<?php

namespace App\Domain\Frete;

use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Builder;

/**
 * A Garagem do Governo (D-76): a frota REAL do serviço logístico público do §07.
 *
 * Arbitragem do usuário: nada de caminhão fantasma — 10 caminhões de verdade, expansíveis pelo
 * painel. Um caminhão de garagem é um `vehicles` com:
 *
 *   colony_id   null      (é do governo — o mesmo null da prateleira de venda)
 *   status      ocioso    (⚠️ é ISTO que o separa da prateleira, que é `estoque`: os dois usos
 *                          não brigam, e vender um caminhão jamais esvazia a garagem)
 *   local       capital   (a Garagem fica na Capital, ao lado da doca que ela serve)
 *
 * O Pátio não lhe cobra diária (`Patio` filtra `whereNotNull(colony_id)`), o mercado de usados não
 * o vende (a checagem de dono barra), e a `Vagas` de ninguém o conta.
 */
final class Garagem
{
    /** Os caminhões da Garagem, em qualquer estado (livres + em frete). */
    public static function frota(): Builder
    {
        return Vehicle::whereNull('colony_id')
            ->where('type', 'caminhao_de_carga')
            // `whereIn`, e não `!= estoque`: a linha de montagem da PRATELEIRA usa `fabricando`
            // com o mesmo colony_id nulo, e um caminhão ainda não nascido não é frota de ninguém.
            ->whereIn('status', ['ocioso', 'em_rota']);
    }

    /** Os que estão livres para sair em frete agora. */
    public static function livres(): Builder
    {
        return self::frota()->where('status', 'ocioso');
    }
}
