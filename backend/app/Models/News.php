<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Um comunicado do mural da Central de Pesquisas e Notícias (slot 3). Ver as migrations.
 *
 * Uma notícia tem **três estados**, e a distinção é do usuário (2026-07-13):
 *
 *  - **no mural** — publicada, e o colono a vê.
 *  - **oculta** (`hidden_at`) — administrativo e **reversível**: saiu do mural agora (erro de redação,
 *    publicada cedo demais) e volta a qualquer momento.
 *  - **inativa** (`inactive_at`) — **fim de vida**: deixou de ser verdadeira. Sai do mural e fica
 *    arquivada, marcada. É o que preserva o histórico em vez de apagá-lo.
 *
 * Oculta e inativa **saem do mural do mesmo jeito** — a diferença é o que elas dizem a quem opera, e
 * se voltam. Uma notícia oculta espera; uma notícia inativa acabou.
 */
class News extends Model
{
    /*
     * O `created_at` é gravado à mão desde sempre, e o `updated_at` passou a existir com a edição.
     * Deixar o Eloquent cuidar dos dois faria o `updated_at` mexer-se sozinho ao ocultar/inativar —
     * e "quando o texto foi reescrito" é justamente o que ele tem de responder.
     */
    public $timestamps = false;

    protected $table = 'news';

    protected $fillable = [
        'title', 'body', 'kind', 'author', 'published_at',
        'hidden_at', 'inactive_at', 'updated_at',
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'hidden_at' => 'datetime',
        'inactive_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /** O que o colono vê no mural: nem oculta, nem inativa, e já publicada. */
    public function scopeNoMural(Builder $q): Builder
    {
        return $q->whereNull('hidden_at')
            ->whereNull('inactive_at')
            ->where('published_at', '<=', now());
    }

    public function oculta(): bool
    {
        return $this->hidden_at !== null;
    }

    public function inativa(): bool
    {
        return $this->inactive_at !== null;
    }

    /** Como o painel a rotula. Inativa vence oculta: fim de vida é mais forte que "espere um pouco". */
    public function estado(): string
    {
        if ($this->inativa()) {
            return 'inativa';
        }

        if ($this->oculta()) {
            return 'oculta';
        }

        return $this->published_at !== null && $this->published_at->isFuture()
            ? 'agendada'
            : 'no mural';
    }
}
