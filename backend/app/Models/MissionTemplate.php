<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Um molde de missão do catálogo (§06; D-78). O baralho de onde as diárias são sorteadas. */
class MissionTemplate extends Model
{
    /**
     * As quatro categorias do baralho — centralizadas aqui (D-96) porque viviam soltas em três
     * lugares (a tela do admin, a validação do `AcoesController`, e nenhum enum no banco): editar
     * uma sem a outra deixava a tela e a validação discordarem sobre o que é válido.
     *
     * "Eventuais" (D-96) é para o que não tem ciclo — evento sazonal, comemoração, missão de
     * lançamento — coisa que não é tutoria, não se repete todo dia, nem toda semana.
     */
    public const CATEGORIAS = [
        'tutoria' => 'Tutoria',
        'diaria' => 'Diária',
        'semanal' => 'Semanal',
        'eventuais' => 'Eventuais',
    ];

    protected $fillable = [
        'chave', 'categoria', 'titulo', 'descricao', 'acao', 'meta',
        'recompensa_fert_micro', 'recompensa_xp', 'recompensa_recursos', 'ativa',
    ];

    protected $casts = [
        'meta' => 'integer',
        'recompensa_fert_micro' => 'integer',
        'recompensa_xp' => 'integer',
        'recompensa_recursos' => 'array',
        'ativa' => 'boolean',
    ];

    /** Quantas vezes já foi sorteada — é o que decide se o painel deixa apagar ou só desativar. */
    public function assignments(): HasMany
    {
        return $this->hasMany(MissionAssignment::class, 'template_id');
    }
}
