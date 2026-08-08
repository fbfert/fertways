<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Um evento de mundo (A2.8) — a linha de tabela que substitui um `if` no tick.
 *
 * ⚠️ **O MODIFICADOR nunca escreve no ledger.** Ele altera a taxa; quem credita continua sendo o
 * tick. Ver o docblock da migration `motor_de_eventos`.
 *
 * ⚠️ **A RECOMPENSA sempre escreve** (D-232). São coisas diferentes: mudar a taxa não é um fato
 * econômico, entregar 20.000 de energia é — e sem lançamento o jogador veria o estoque saltar sem
 * explicação. Ver o docblock da migration `cesta_de_presente_e_portoes_de_ocupacao`.
 */
class GameEvent extends Model
{
    protected $fillable = [
        'slug', 'nome', 'descricao', 'mensagem_publica', 'notas_internas',
        'comeca_em', 'termina_em', 'status', 'cancelado_em',
        'visibilidade', 'escopo', 'colony_id', 'gatilho',
        'modificador', 'efeito_bps', 'resource_type',
        'recompensas', 'missoes', 'segredo', 'versao', 'criado_por',
    ];

    protected $casts = [
        'comeca_em' => 'datetime',
        'termina_em' => 'datetime',
        'cancelado_em' => 'datetime',
        // Assinado: a direção vem do SINAL, e foi a lição do D-164.
        'efeito_bps' => 'integer',
        'recompensas' => 'array',
        'missoes' => 'array',
        'segredo' => 'boolean',
        'versao' => 'integer',
    ];

    public function entregas(): HasMany
    {
        return $this->hasMany(GameEventEntrega::class);
    }

    /** Este evento entrega alguma coisa, ou só mexe numa taxa? */
    public function temCesta(): bool
    {
        return collect($this->recompensas ?? [])->filter(fn ($q) => (int) $q > 0)->isNotEmpty();
    }

    /** Está valendo agora? `cancelado` continua valendo para trás, nunca para a frente. */
    public function vigenteEm(CarbonInterface $quando): bool
    {
        if ($this->status === 'rascunho') {
            return false;
        }

        if ($this->cancelado_em !== null && $quando->getTimestamp() >= $this->cancelado_em->getTimestamp()) {
            return false;
        }

        return $quando->between($this->comeca_em, $this->termina_em);
    }

    /**
     * O que o jogador pode ver deste evento.
     *
     * ⚠️ `segredo` e `visibilidade = secreto` são afirmações SEPARADAS de propósito: quem quiser
     * segredo tem de dizê-lo duas vezes. Uma trava só seria fácil demais de desligar por acidente.
     */
    public function visivelAoJogador(): bool
    {
        return ! $this->segredo && $this->visibilidade !== 'secreto';
    }
}
