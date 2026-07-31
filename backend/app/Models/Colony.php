<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Colony extends Model
{
    use HasFactory;

    /**
     * 100 Fert$ de saldo inicial, em micro-Fert$. Era 50 (GDD, "todo colono recebe 50 Fert$ ao
     * chegar em Fertways"); o D-85 (2026-07-15) dobrou o valor como parte do kit inicial novo —
     * decisão do usuário, não do GDD. Ver `Domain\Colony\KitInicial`.
     */
    public const SALDO_INICIAL_MICRO = 100_000_000;

    /** 1 Fert$ = 1.000.000 µF$. Preços do GDD têm 4 casas (Energia = 0,0033). */
    public const MICRO_POR_FERT = 1_000_000;

    protected $fillable = [
        'user_id', 'name', 'x', 'y', 'founded_at', 'milestone', 'fert_micro', 'last_tick_at',
        'siderurgica_lote_remainder', 'federation_id', 'federation_role',
            'populacao',
            'populacao_resto_milli',
    ];

    /**
     * ⚠️ O default tem de estar AQUI também, não só no banco — `create()` não relê o que o banco
     * aplicou, e um `Colony::create()` devolveria `siderurgica_lote_remainder` nulo. É a mesma
     * pegadinha que já mordeu `Vehicle`, `WarSetting` e `NeutralZone` (ver o comentário de lá).
     */
    protected $attributes = [
        'siderurgica_lote_remainder' => 0,
    ];

    protected $casts = [
        'x' => 'integer',
        'y' => 'integer',
        'founded_at' => 'datetime',
        'last_tick_at' => 'datetime',
        'fert_micro' => 'integer',
        'siderurgica_lote_remainder' => 'integer',
            // A2.2: o total. Alocada em construções e em zonas são DERIVADAS — ver Domain\Populacao.
        'populacao' => 'integer',
        'populacao_resto_milli' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function buildings(): HasMany
    {
        return $this->hasMany(Building::class);
    }

    public function resources(): HasMany
    {
        return $this->hasMany(Resource::class);
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }

    /** A federação da colônia (D-114) — null se não estiver em nenhuma. */
    public function federation(): BelongsTo
    {
        return $this->belongsTo(Federation::class);
    }

    public function naFederacao(): bool
    {
        return $this->federation_id !== null;
    }

    public function liderDaFederacao(): bool
    {
        return $this->federation_role === Federation::LIDER;
    }

    /** Líder ou Diplomata: quem convida colônia de fora e aceita pedido de entrada (D-114). */
    public function podeConvidarParaFederacao(): bool
    {
        return in_array($this->federation_role, [Federation::LIDER, Federation::DIPLOMATA], true);
    }

    /** Líder ou Intendente: quem saca do fundo da federação (D-114). */
    public function podeSacarDoFundo(): bool
    {
        return in_array($this->federation_role, [Federation::LIDER, Federation::INTENDENTE], true);
    }
}
