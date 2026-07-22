<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma estrutura erguida num slot da zona neutra (docs/decisoes.md D-144) — o espelho de
 * `App\Models\Building` para a zona: uma linha por construção, não mais uma coluna por tipo.
 *
 * `Domain\Zona\Estruturas::REPETIVEIS` decide quais tipos podem ter mais de uma linha por zona
 * (cada cópia num slot, com nível próprio) — o mesmo padrão de `Building::REPETIVEIS` (D-59).
 */
class ZoneStructure extends Model
{
    protected $fillable = ['neutral_zone_id', 'slot', 'type', 'level'];

    protected $casts = [
        'slot' => 'integer',
        'level' => 'integer',
    ];

    public function zone(): BelongsTo
    {
        return $this->belongsTo(NeutralZone::class, 'neutral_zone_id');
    }
}
