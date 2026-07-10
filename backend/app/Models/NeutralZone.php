<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma zona neutra (GDD §07, §24.4; docs/decisoes.md D-51 e D-52).
 *
 * Mora numa célula do mapa do D-51 (`x,y` com sinal), num dos 4 distritos dos cantos. A geometria e
 * o mineral são fixos (semeados de `App\Domain\Logistics\ZonasNeutras`); o que muda é dono, ocupação,
 * guarnição e o Depósito. A capacidade do Depósito e a taxa de extração saem dos níveis pela curva do
 * §19.1 (`Base × 1,5^(N−1)`), com as bases arbitradas no D-52.
 *
 * O `status` e a proteção são o mecanismo de guerra (Fatia 2): "protegida por 8 dias completos a
 * partir da primeira ocupação; ao término, vulnerável" (precedência da seção 0). A extração da
 * Fatia 1 não depende deles.
 */
class NeutralZone extends Model
{
    /** GDD (precedência, seção 0): "protegida por 8 dias completos a partir da primeira ocupação". */
    public const DIAS_DE_PROTECAO = 8;

    /** Extração-base, por hora, no nível 1 da zona (§24.4 "produção alta"; arbitrado no D-52). */
    public const EXTRACAO_BASE_HORA = 100;

    /** Capacidade-base do Depósito de Zona Neutra no nível 1 (§19.6: 500 … 19.222). */
    public const DEPOSITO_BASE = 500;

    /** A curva de tudo no GDD (§19.1). */
    public const CURVA = 1.5;

    // --- Ocupação (§07, D-52, Fatia 1). O que é publicado (custo/defesa do Robô, curva) vem do
    //     GDD; o que é *inventado* está marcado. Ver docs/decisoes.md D-52.

    /** Posto de Comando nível 1 — custo *inventado* (lacuna 7): Metal Bruto + Fert$, e o tempo. */
    public const POSTO_METAL_BRUTO = 800;

    public const POSTO_FERT = 300;

    public const POSTO_HORAS = 8;

    /** Guarnição inicial: 20 Robôs Mineradores (âncora "20 a 150+", ponta baixa do nível 1). */
    public const GUARNICAO_INICIAL = 20;

    /** Tempo de ocupação antes de a zona extrair — *inventado* (lacuna 3). */
    public const OCUPACAO_HORAS = 12;

    protected $fillable = [
        'x', 'y', 'district', 'mineral', 'level',
        'owner_colony_id', 'status', 'occupied_at', 'protected_until',
        'command_post_level', 'garrison', 'productive_at',
        'deposit_level', 'deposit_amount', 'last_extraction_at',
    ];

    protected $casts = [
        'x' => 'integer',
        'y' => 'integer',
        'level' => 'integer',
        'command_post_level' => 'integer',
        'garrison' => 'integer',
        'deposit_level' => 'integer',
        'deposit_amount' => 'integer',
        'occupied_at' => 'datetime',
        'protected_until' => 'datetime',
        'productive_at' => 'datetime',
        'last_extraction_at' => 'datetime',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Colony::class, 'owner_colony_id');
    }

    /** Tem dono? (Livre = sem dono.) */
    public function estaOcupada(): bool
    {
        return $this->owner_colony_id !== null;
    }

    /** Ocupada e já estabelecida — extrai a partir daqui (D-52). */
    public function estaProdutiva($agora = null): bool
    {
        if (! $this->estaOcupada() || $this->productive_at === null) {
            return false;
        }

        return ($agora ?? now())->greaterThanOrEqualTo($this->productive_at);
    }

    /** Capacidade do Depósito, pela curva do §19.1 a partir da base do §19.6. */
    public function capacidadeDeposito(): int
    {
        return (int) round(self::DEPOSITO_BASE * self::CURVA ** ($this->deposit_level - 1));
    }

    /** Extração por hora, pela curva do §19.1 a partir da base arbitrada. */
    public function extracaoPorHora(): int
    {
        return (int) round(self::EXTRACAO_BASE_HORA * self::CURVA ** ($this->level - 1));
    }
}
