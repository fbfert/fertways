<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Ledger append-only (GDD, seção 0, "Arquitetura de dados": "ledger append-only").
 *
 * Sem updated_at e sem deleted_at no schema. Aqui bloqueamos update e delete no nível do
 * Model, para que a invariante não dependa de disciplina de quem escreve o código.
 * Estorno é um lançamento novo de sinal contrário, tipo `estorno`.
 */
class Ledger extends Model
{
    protected $table = 'ledger';

    public $timestamps = false;

    protected $fillable = ['colony_id', 'type', 'amount', 'resource_type', 'ref', 'created_at'];

    protected $casts = ['amount' => 'integer', 'created_at' => 'datetime'];

    public const TIPOS = [
        'producao',
        'custo_construcao',
        'subsidio_governo',   // §24.7: 100% das cinco essenciais até o nível 3
        'tributo',
        'venda_mercado',
        'compra_mercado',
        'transferencia',
        'saldo_inicial',      // 50 Fert$ de onboarding
        'kit_inicial',        // raros concedidos na fundação — decisão de design, D-17
        'energia_viagem',     // §21.1: consumo do veículo por distância percorrida
        'deposito_mercado',   // §25.8: carga entregue entra na conta do colono no Mercado
        'retirada_mercado',   // §25.8: saldo reservado no Mercado para um veículo vir buscar
        'escrow_mercado',     // §07: recurso ou Fert$ reservado ao abrir uma ordem no livro
        'salario_conciliador', // §26.7: 50 F$/dia, emitidos pelo Governo (D-50)
        'bonus_conciliador',   // §26.7: +3 F$ por decisão que sobrevive à apelação
        'custo_ocupacao',      // §07: Posto de Comando + Robôs Mineradores para ocupar zona (D-52)
        'estorno',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $l) {
            if (! in_array($l->type, self::TIPOS, true)) {
                throw new RuntimeException("Tipo de lançamento inválido: {$l->type}");
            }
        });

        static::updating(fn () => throw new RuntimeException('ledger é append-only: use um lançamento de estorno'));
        static::deleting(fn () => throw new RuntimeException('ledger é append-only: não se apaga lançamento'));
    }
}
