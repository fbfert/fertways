<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * O depósito dos cinco minerais eletrônicos que a Indústria Siderúrgica extrai do Metal Bruto
 * (docs/decisoes.md D-82) — Alumínio, Cobre, Estanho, Ouro, Tungstênio.
 *
 * O depósito da zona só tinha lugar para DOIS recursos (`deposit_amount` e `refined_amount`); esta
 * tabela é o que dá lugar aos outros cinco, sem inventar mais uma coluna por mineral. Ligas
 * Metálicas NÃO mora aqui — vai para `refined_amount`, o mesmo pote da Refinaria de Campo.
 *
 * Conta no MESMO teto de capacidade de tudo o mais na zona, e fica exposto ao mesmo saque
 * (decisão do usuário) — `Protegido` soma esta tabela junto com o resto.
 */
class ZoneMineral extends Model
{
    protected $table = 'zone_minerals';

    protected $fillable = ['zone_id', 'resource_type', 'amount'];

    protected $casts = ['amount' => 'integer'];

    public function zone(): BelongsTo
    {
        return $this->belongsTo(NeutralZone::class, 'zone_id');
    }
}
