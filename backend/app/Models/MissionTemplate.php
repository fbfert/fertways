<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Um molde de missão do catálogo (§06; D-78). O baralho de onde as diárias são sorteadas. */
class MissionTemplate extends Model
{
    /**
     * As categorias do baralho — centralizadas aqui (D-96) porque viviam soltas em três lugares (a
     * tela do admin, a validação do `AcoesController`, e nenhum enum no banco): editar uma sem a
     * outra deixava a tela e a validação discordarem sobre o que é válido.
     *
     * "Eventuais" (D-96) é para o que não tem ciclo — evento sazonal, comemoração, missão de
     * lançamento — coisa que não é tutoria, não se repete todo dia, nem toda semana.
     *
     * "Federação" (D-116, Fatia 3): cooperativa, 2 por semana (§06). Cada colônia-membro ganha a
     * própria linha em `mission_assignments`, todas marcadas com o mesmo `federation_id` — ver
     * `Atribuir::garantirFederacao()` e `Progresso::registrar()`.
     *
     * "Narrativa" (D-140): a categoria que o D-78 deixou de fora de propósito. Sem ciclo (não
     * sorteia, não expira) — encadeada por `requer_template_id`: um capítulo só chega à mão da
     * colônia quando o anterior está concluído (`Atribuir::garantirNarrativa()`).
     */
    public const CATEGORIAS = [
        'tutoria' => 'Tutoria',
        'diaria' => 'Diária',
        'semanal' => 'Semanal',
        'federacao' => 'Federação',
        'eventuais' => 'Eventuais',
        'narrativa' => 'Narrativa',
    ];

    protected $fillable = [
        'chave', 'categoria', 'obrigatoria', 'requer_template_id', 'titulo', 'descricao', 'acao',
        'meta', 'recompensa_fert_micro', 'recompensa_xp', 'recompensa_recursos', 'recompensa_federacao', 'ativa',
    ];

    protected $casts = [
        'meta' => 'integer',
        'recompensa_fert_micro' => 'integer',
        'recompensa_xp' => 'integer',
        'recompensa_recursos' => 'array',
        // A2.5: o que vai ao FUNDO da federação, e não a quem cumpriu. Nulo na maioria — objetivo
        // federativo é a exceção, não o padrão.
        'recompensa_federacao' => 'array',
        'ativa' => 'boolean',
        // A2.1: a etapa que o colono não pode pular. Ver o docblock da migration
        // `2026_07_31_200000_onboarding_obrigatorio`.
        'obrigatoria' => 'boolean',
    ];

    /** Quantas vezes já foi sorteada — é o que decide se o painel deixa apagar ou só desativar. */
    public function assignments(): HasMany
    {
        return $this->hasMany(MissionAssignment::class, 'template_id');
    }

    /** O capítulo anterior da cadeia narrativa (D-140) — nulo fora de uma cadeia, ou no 1º capítulo. */
    public function requer(): BelongsTo
    {
        return $this->belongsTo(self::class, 'requer_template_id');
    }
}
