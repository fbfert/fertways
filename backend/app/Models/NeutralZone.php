<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        'x', 'y', 'name', 'district', 'mineral', 'level',
        'owner_colony_id', 'status', 'occupied_at', 'protected_until',
        'command_post_level', 'productive_at',
        'deposit_level', 'deposit_amount', 'last_extraction_at',
        'wall_level', 'watchtower_level', 'bastion_level', 'shelter_level',
        'refinery_level', 'parking_level', 'cemetery_level',
        'extraction_level', 'communication_level', 'landing_pad_level',
        'refined_amount', 'last_refine_at',
        'sieged_at', 'modules_offline',
    ];

    /**
     * ⚠️ **Os defaults têm de estar AQUI, e não só no banco.**
     *
     * O Eloquent insere a linha e **não relê os defaults que o banco aplicou**: um
     * `NeutralZone::create()` devolve um modelo cujo `wall_level` é `null`, não `0`. O `Forcas`
     * então soma `bonus × null` e o bônus some — e nada reclama, porque `null` em contexto numérico
     * vira zero em silêncio.
     *
     * É a **terceira vez** que esta pegadinha aparece: o `Vehicle` já tinha aprendido (o
     * `conservacao_bps` nascia nulo), e o `WarSetting` a repetiu (a primeira leitura dos parâmetros
     * da guerra vinha zerada). Quando um default vive no banco, ele precisa viver no model também.
     */
    protected $attributes = [
        'command_post_level' => 0,
        'deposit_level' => 0,
        'deposit_amount' => 0,
        'wall_level' => 0,
        'watchtower_level' => 0,
        'bastion_level' => 0,
        'shelter_level' => 0,
        'refinery_level' => 0,
        'parking_level' => 0,
        'cemetery_level' => 0,
        'extraction_level' => 0,
        'communication_level' => 0,
        'landing_pad_level' => 0,
        'refined_amount' => 0,
    ];

    protected $casts = [
        'x' => 'integer',
        'y' => 'integer',
        'level' => 'integer',
        'command_post_level' => 'integer',
        'deposit_level' => 'integer',
        'deposit_amount' => 'integer',
        'wall_level' => 'integer',
        'watchtower_level' => 'integer',
        'bastion_level' => 'integer',
        'shelter_level' => 'integer',
        'occupied_at' => 'datetime',
        'protected_until' => 'datetime',
        'productive_at' => 'datetime',
        'last_extraction_at' => 'datetime',
        'refinery_level' => 'integer',
        'parking_level' => 'integer',
        'cemetery_level' => 'integer',
        'extraction_level' => 'integer',
        'communication_level' => 'integer',
        'landing_pad_level' => 'integer',
        'refined_amount' => 'integer',
        'last_refine_at' => 'datetime',
        'sieged_at' => 'datetime',
        'modules_offline' => 'array',
    ];

    /**
     * As unidades guarnecendo a zona. Robôs Mineradores e Sentinelas.
     *
     * Até o D-66 a guarnição era um `int` chamado `garrison`. Um inteiro não sabe quem está
     * ferido, e o §27.6 manda os sobreviventes voltarem com HP reduzido e os de HP zero
     * morrerem de vez — então ele morreu e virou linhas de `units`.
     */
    public function units(): HasMany
    {
        return $this->hasMany(Unit::class, 'zone_id');
    }

    /**
     * Quantos Robôs Mineradores guarnecem a zona hoje.
     *
     * A API continua publicando isto como `garrison`: o contrato com o frontend não mudou, só
     * a origem do número.
     */
    public function guarnicao(): int
    {
        return $this->units()
            ->where('type', 'robo_minerador')
            ->where('hp_bps', '>', 0)
            ->count();
    }

    /** O canteiro de obras: o material que chegou de veículo e ainda não virou construção (D-67). */
    public function materiais(): HasMany
    {
        return $this->hasMany(ZoneMaterial::class, 'zone_id');
    }

    /** A obra em curso. Uma por vez — a zona não tem fila, tem canteiro (D-67). */
    public function obras(): HasMany
    {
        return $this->hasMany(ZoneBuild::class, 'zone_id');
    }

    public function obraEmCurso(): bool
    {
        return $this->obras()->exists();
    }

    /**
     * Quanto a Refinaria de Campo processa por hora, no nível construído.
     *
     * Zero sem Refinaria. A curva é a do §19.1 (`Base × 1,5^(N−1)`), como tudo o mais; a base é
     * **metade da extração da zona** (D-67) — não um número novo, uma fração de um que já existe.
     */
    public function refinoPorHora(): int
    {
        if ($this->refinery_level < 1) {
            return 0;
        }

        return (int) round(
            \App\Domain\Zona\Estruturas::REFINO_BASE_HORA * self::CURVA ** ($this->refinery_level - 1),
        );
    }

    /** Em que o minério desta zona se transforma, se houver Refinaria. Nulo se o mineral não refina. */
    public function recursoRefinado(): ?string
    {
        return \App\Domain\Zona\Estruturas::REFINA[$this->mineral] ?? null;
    }

    /**
     * O que o Depósito guarda, somando o minério bruto e o já refinado.
     *
     * Os dois ocupam o **mesmo** Depósito, e é sobre este total que a capacidade decide o que está
     * protegido do saque (D-66). Refinar não esconde recurso do inimigo — só o torna mais valioso.
     */
    public function estoqueTotal(): int
    {
        return $this->deposit_amount + $this->refined_amount;
    }

    /** A zona está cercada? O cerco fecha a entrada e a saída de carga (§28.10, D-66). */
    public function cercada(): bool
    {
        return $this->sieged_at !== null;
    }

    /**
     * O depósito para de aceitar 30 minutos depois de o cerco começar — as 3 rodadas do §28.10.
     * A extração continua correndo e se perde: "não há onde armazenar".
     */
    public function depositoBloqueado(): bool
    {
        return $this->sieged_at !== null
            && $this->sieged_at->copy()->addMinutes(30)->isPast();
    }

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
