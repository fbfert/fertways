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

    // --- Upgrade de zona e manutenção territorial (D-84, GDD §27.12). O `level` da Fatia 1
    //     (D-52) estava preso em 1 "para uma fatia posterior" — é esta.

    /** Teto de zonas por jogador — arbitrado no D-84. O GDD nunca publica um teto de posse. */
    public const TETO_ZONAS_POR_COLONIA = 5;

    public const NIVEL_MAXIMO = 5;

    /**
     * Custo e guarnição do upgrade seguem a curva 1,65× do catálogo (Muralha/Torre/Bastião/Drone,
     * D-66/D-74) a partir da base do Posto de Comando — não a 1,5× do §19.1, que é para produção
     * e capacidade, não para custo. O tempo segue a curva 1,5× que o próprio catálogo usa para
     * `build_time` (Bastião etc., proporção observada, não publicada em §19.1). Ver D-84.
     */
    public const UPGRADE_CURVA_CUSTO = 1.65;

    public const UPGRADE_CURVA_TEMPO = 1.5;

    /**
     * Guarnição-alvo por nível, arbitrada no D-84 sobre a âncora do §16.1: "quantidade necessária
     * varia por nível da zona (20 a 150+)". `round(20 × 1,65^(N−1))` dá 20/33/54/90/148 — a mesma
     * curva do custo, e a ponta (148) cai dentro do "150+" do GDD sem forçar a mão.
     */
    public static function guarnicaoAlvo(int $nivel): int
    {
        return (int) round(self::GUARNICAO_INICIAL * self::UPGRADE_CURVA_CUSTO ** ($nivel - 1));
    }

    /** Metal Bruto e Fert$ para alcançar `$nivel` (2 a 5) a partir do nível anterior. */
    public static function custoDeUpgrade(int $nivel): array
    {
        return [
            'metal_bruto' => (int) round(self::POSTO_METAL_BRUTO * self::UPGRADE_CURVA_CUSTO ** ($nivel - 1)),
            'fert' => (int) round(self::POSTO_FERT * self::UPGRADE_CURVA_CUSTO ** ($nivel - 1)),
        ];
    }

    /** Horas de obra para alcançar `$nivel` (2 a 5). */
    public static function horasDeUpgrade(int $nivel): int
    {
        return (int) round(self::POSTO_HORAS * self::UPGRADE_CURVA_TEMPO ** ($nivel - 1));
    }

    /**
     * A manutenção territorial do §27.12, por nível — nunca implementada antes do D-84, nem para
     * zona de nível 1. Custo publicado verbatim (a tabela do GDD, não arbitragem); o decaimento e
     * o abandono automático seguem a correção já feita no D-52 (Parte I corrige a Parte II: 5% por
     * DIA de inadimplência, não por hora; abandono em 72 h, não 48 h).
     *
     * @return array<string,int>
     */
    public function custoDeManutencao(): array
    {
        return match (true) {
            $this->level <= 1 => ['biomassa' => 50, 'energia' => 30],
            $this->level <= 3 => ['biomassa' => 100, 'energia' => 60, 'ligas_metalicas' => 20],
            default => ['biomassa' => 200, 'energia' => 120, 'ligas_metalicas' => 50, 'componentes_eletronicos' => 10],
        };
    }

    /** Grau de atraso, em basis points de Pontos de Defesa perdidos (§27.12, corrigido no D-52). */
    public const MANUTENCAO_CARENCIA_HORAS = 24;

    public const MANUTENCAO_DECAIMENTO_BPS_POR_DIA = 500;   // 5%/dia

    public const MANUTENCAO_ABANDONO_HORAS = 72;

    /** 0% durante a carência de 24 h; 5%/dia de atraso depois disso, até o abandono em 72 h. */
    public function penalidadeManutencaoBps(): int
    {
        if ($this->maintenance_unpaid_since === null) {
            return 0;
        }

        $atraso = $this->maintenance_unpaid_since->diffInHours(now());

        if ($atraso < self::MANUTENCAO_CARENCIA_HORAS) {
            return 0;
        }

        $dias = intdiv($atraso - self::MANUTENCAO_CARENCIA_HORAS, 24) + 1;

        return min(10_000, $dias * self::MANUTENCAO_DECAIMENTO_BPS_POR_DIA);
    }

    public function deveSerAbandonada(): bool
    {
        return $this->maintenance_unpaid_since !== null
            && $this->maintenance_unpaid_since->diffInHours(now()) >= self::MANUTENCAO_ABANDONO_HORAS;
    }

    protected $fillable = [
        'x', 'y', 'name', 'district', 'mineral', 'level', 'level_target', 'level_upgrade_finishes_at',
        'owner_colony_id', 'status', 'occupied_at', 'protected_until',
        'command_post_level', 'productive_at',
        'deposit_level', 'deposit_amount', 'last_extraction_at',
        'maintenance_next_due_at', 'maintenance_unpaid_since',
        'wall_level', 'watchtower_level', 'bastion_level', 'shelter_level',
        'refinery_level', 'parking_level', 'cemetery_level',
        'extraction_level', 'communication_level', 'landing_pad_level',
        'refined_amount', 'last_refine_at',
        'industry_level', 'last_industry_at',
        'sieged_at', 'modules_offline', 'modules_offline_expira_em', 'structures_saboted',
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
        'industry_level' => 0,
    ];

    protected $casts = [
        'x' => 'integer',
        'y' => 'integer',
        'level' => 'integer',
        'level_target' => 'integer',
        'level_upgrade_finishes_at' => 'datetime',
        'maintenance_next_due_at' => 'datetime',
        'maintenance_unpaid_since' => 'datetime',
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
        'industry_level' => 'integer',
        'last_industry_at' => 'datetime',
        'sieged_at' => 'datetime',
        'modules_offline' => 'array',
        'modules_offline_expira_em' => 'array',
        'structures_saboted' => 'array',
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

    /**
     * Os cinco minerais eletrônicos que a Indústria Siderúrgica produz (D-82). Ligas Metálicas
     * NÃO está aqui — vai para `refined_amount`, o mesmo pote da Refinaria de Campo.
     */
    public function minerais(): HasMany
    {
        return $this->hasMany(ZoneMineral::class, 'zone_id');
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
    /**
     * O bruto, o refinado (Refinaria de Campo) e os cinco minerais da Indústria Siderúrgica
     * (D-82) — todos no MESMO Depósito, mesma capacidade, mesmo saque. `zone_minerals` é onde os
     * minerais moram; sem eles, `sum()` de uma coleção vazia já dá 0 (não precisa de `?? 0`).
     */
    public function estoqueTotal(): int
    {
        return $this->deposit_amount + $this->refined_amount + $this->minerais->sum('amount');
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

    /**
     * O nível máximo de uma unidade de combate (Infiltrador incluído) — mesma curva de 5 níveis do
     * §27.1 (`Unit::DEFESA`). A Sabotagem escala a perda de capacidade por ele, não por um teto novo.
     */
    public const NIVEL_MAXIMO_UNIDADE = 5;

    /**
     * Quanto de uma estrutura da zona está de pé AGORA, em bps (10.000 = cheia, 0 = totalmente
     * fora). O "Módulo Operacional" (D-66, revisto no D-118) tem duas formas de degradar, e não se
     * somam — uma estrutura não pode estar apreendida E sabotada ao mesmo tempo, porque a
     * Apreensão já a zera:
     *
     * - **Apreensão (Predador)**: `modules_offline` — binário, 0 bps, até `RepararModulo` (resgate
     *   antecipado) ou o tick de `ExpirarApreensoes` (24h, `modules_offline_expira_em`).
     * - **Sabotagem (Infiltrador)**: `structures_saboted` — proporcional ao nível de quem sabotou,
     *   "perde capacidade proporcional ao nível do Infiltrador" (§28.10, verbatim). Nível 5 de 5
     *   equivale a 0 bps (tão inerte quanto uma Apreensão); nível 1, só 20% a menos. Só sai por
     *   reparo ativo do dono — o GDD não dá prazo automático para a Sabotagem como dá para a
     *   Apreensão.
     */
    public function fracaoEfetiva(string $chave): int
    {
        if (in_array($chave, $this->modules_offline ?? [], true)) {
            return 0;
        }

        $nivel = ($this->structures_saboted ?? [])[$chave] ?? null;

        if ($nivel === null) {
            return 10_000;
        }

        return max(0, 10_000 - intdiv(10_000, self::NIVEL_MAXIMO_UNIDADE) * $nivel);
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

    /**
     * Capacidade do Depósito, pela curva do §19.1 a partir da base do §19.6 — reduzida se o
     * Depósito estiver apreendido ou sabotado (D-118): menos protegido, mais exposto ao saque.
     */
    public function capacidadeDeposito(): int
    {
        $cheia = (int) round(self::DEPOSITO_BASE * self::CURVA ** ($this->deposit_level - 1));

        return intdiv($cheia * $this->fracaoEfetiva('deposito_de_zona_neutra'), 10_000);
    }

    /** Extração por hora, pela curva do §19.1 a partir da base arbitrada. */
    public function extracaoPorHora(): int
    {
        return (int) round(self::EXTRACAO_BASE_HORA * self::CURVA ** ($this->level - 1));
    }
}
