<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
        'visibilidade', 'escopo', 'colony_id', 'federation_id', 'gatilho', 'sucede_event_id', 'condicoes',
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
        'condicoes' => 'array',
        'segredo' => 'boolean',
        'versao' => 'integer',
    ];

    public function entregas(): HasMany
    {
        return $this->hasMany(GameEventEntrega::class);
    }

    /**
     * A colônia do evento de escopo `colonia` — o ensaio em escala de um. Nula no escopo `mundo`.
     *
     * ⚠️ Faltava, e a aba do painel caiu com 500 em produção por causa disso (D-233). O
     * `with('colony')` estava lá desde o D-232 e **o teste não pegou**: o Laravel só resolve o eager
     * loading quando a consulta devolve linhas, e o teste abria a aba com a tabela vazia. Uma página
     * que passa vazia e quebra cheia é o pior tipo de verde.
     */
    public function colony(): BelongsTo
    {
        return $this->belongsTo(Colony::class);
    }

    /** D-253: a federação do evento de escopo `federacao`. */
    public function federation(): BelongsTo
    {
        return $this->belongsTo(Federation::class);
    }

    /** D-253: o evento que este rascunho sucede — ele se ativa quando aquele terminar. */
    public function predecessor(): BelongsTo
    {
        return $this->belongsTo(self::class, 'sucede_event_id');
    }

    /**
     * Os eventos que ALCANÇAM esta colônia — o escopo, num lugar só (D-253).
     *
     * ⚠️ Esta regra estava copiada em seis lugares (motor, faixa, avisos, resumo, missões, cesta), e
     * o escopo `federacao` teria de entrar em cada cópia. É assim que uma delas fica para trás e um
     * evento passa a valer no motor e a não aparecer na faixa. Agora as seis perguntam aqui.
     *
     * `$colonia` nulo pergunta só pelos eventos de mundo — é o que o motor faz para o Governo.
     */
    public function scopeAlcanca(Builder $q, ?Colony $colonia): Builder
    {
        return $q->where(function (Builder $q) use ($colonia) {
            $q->where('escopo', 'mundo');

            if ($colonia === null) {
                return;
            }

            $q->orWhere(fn (Builder $c) => $c->where('escopo', 'colonia')->where('colony_id', $colonia->id));

            if ($colonia->federation_id !== null) {
                $q->orWhere(fn (Builder $f) => $f->where('escopo', 'federacao')
                    ->where('federation_id', $colonia->federation_id));
            }
        });
    }

    /** Este evento entrega alguma coisa, ou só mexe numa taxa? */
    public function temCesta(): bool
    {
        return collect($this->recompensas ?? [])->filter(fn ($q) => (int) $q > 0)->isNotEmpty();
    }

    /** D-250: este evento traz missões especiais? */
    public function temMissoes(): bool
    {
        return collect($this->missoes ?? [])->isNotEmpty();
    }

    /**
     * Este evento é um GANHO para o jogador — e por isso o fim da janela dele é uma porta a fechar.
     *
     * ⚠️ Existe para o aviso de última chamada (A2.V6) não virar ruído. "O evento X termina em 12 h"
     * só é acionável quando há algo a aproveitar: uma seca que acaba amanhã é boa notícia e não pede
     * ação nenhuma, e um aviso que não se pode atender ensina a ignorar a faixa inteira — a mesma
     * razão que cortou "população no teto" no D-211.
     *
     * A leitura vem do SINAL, como em toda parte desde o D-164: nos modificadores de barreira o bps
     * negativo abaixa o que o jogo cobra; nos de torneira o positivo aumenta o que o jogo dá.
     */
    public function favoreceOJogador(): bool
    {
        // D-250: missão com prazo é porta a fechar — o aviso de última chamada vale para ela.
        if ($this->temCesta() || $this->temMissoes()) {
            return true;
        }

        if ($this->modificador === null || $this->efeito_bps === null) {
            return false;
        }

        return match ($this->modificador) {
            // Barreiras: o que o jogo exige para deixar fazer alguma coisa.
            'ocupacao_marco', 'ocupacao_populacao', 'guerra_custo', 'consumo',
            // D-248: alíquota, prazos e custo do território também são barreiras.
            'taxa', 'logistica', 'construcao', 'pesquisa', 'territorio' => $this->efeito_bps < 0,
            // Torneiras: o que o jogo entrega por hora.
            'producao', 'populacao' => $this->efeito_bps > 0,
            /*
             * A trégua imposta não é ganho de ninguém em particular — ela impede um ato, e o fim
             * dela não devolve nada que o jogador possa correr para pegar.
             */
            'guerra_declaracao' => false,
            // D-253: o campo de batalha não favorece "o jogador" — favorece um dos dois lados.
            'combate_defesa' => false,
            default => false,
        };
    }

    /** Está valendo agora? `cancelado` continua valendo para trás, nunca para a frente. */
    public function vigenteEm(CarbonInterface $quando): bool
    {
        // D-253: só `ativo` e `cancelado` valem. Era `status === 'rascunho'`, e o estado novo
        // (`armado`, que ainda não se ativou) teria passado por evento valendo.
        if (! in_array($this->status, ['ativo', 'cancelado'], true)) {
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
