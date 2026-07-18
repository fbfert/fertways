<?php

namespace App\Domain\Colony;

use App\Models\Colony;
use Illuminate\Support\Facades\DB;

/**
 * O Silo é o Depósito Local (D-105/106) estendido: além de mostrar os recursos, define quanto de
 * cada um cabe protegido na colônia, por nível — docs/decisoes.md D-107.
 *
 * Molde de `App\Domain\Guerra\Protegido` (D-66), que resolve o mesmo problema pra Zona Neutra —
 * "protegido = min(estoque, capacidade)", "exposto = estoque − capacidade" — só que aqui por
 * RECURSO, não agregado: a zona tem um Depósito único somando tudo; a colônia já é uma linha por
 * recurso em `resources`, então "por recurso" é o formato natural.
 *
 * Calculado sob demanda, não gravado em `resources.storage_cap` (que fecha o D-14, mas continua
 * NULL de propósito): gravar exigiria recalcular e resincronizar toda vez que o Depósito Local
 * evolui — sob demanda é sempre certo, sem mais um gatilho pra manter.
 *
 * ⚠️ Isto é só a regra e o dado (pedido explícito do usuário). Não há, ainda, nenhum saque de
 * colônia — a guerra hoje (`App\Domain\Guerra`) só mira Zona Neutra. Conectar "exposto" a alguma
 * consequência de jogo é uma entrega futura, deliberadamente fora desta.
 */
class Silo
{
    /** O nível do Depósito Local da colônia — sempre existe (D-105/106, nasce no nível 1). */
    public function nivel(Colony $colonia): int
    {
        return (int) $colonia->buildings()->where('type', 'deposito_local')->value('level');
    }

    /** Quanto de um recurso cabe protegido, no nível atual do Depósito Local. */
    public function capacidade(Colony $colonia, string $recurso): int
    {
        return (int) DB::table('silo_capacidades')
            ->where(['resource_type' => $recurso, 'level' => $this->nivel($colonia)])
            ->value('capacidade');
    }

    /** Quanto de uma quantidade está a salvo — o que não excede a capacidade do Silo. */
    public function protegido(Colony $colonia, string $recurso, int $quantidade): int
    {
        return min($quantidade, $this->capacidade($colonia, $recurso));
    }

    /** Quanto excede a capacidade do Silo — o que ficaria exposto a saque, quando o saque existir. */
    public function exposto(Colony $colonia, string $recurso, int $quantidade): int
    {
        return max(0, $quantidade - $this->capacidade($colonia, $recurso));
    }
}
