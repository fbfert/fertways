<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Um evento discreto de telemetria (A2.0.1).
 *
 * **Append-only, pela mesma razão do `Ledger`**: medida que se corrige depois de registrada não é
 * medida, é opinião. Se um evento foi escrito errado, o conserto é um evento novo — nunca a
 * reescrita do antigo. A trava fica aqui no modelo, e não na disciplina de quem chama.
 *
 * A lista de tipos é fechada de propósito. Um `type` livre viraria, em três meses, quinze grafias
 * do mesmo acontecimento (`login`, `Login`, `user_login`), e nenhuma consulta somaria certo.
 */
class TelemetryEvent extends Model
{
    protected $table = 'telemetry_events';

    /** Só `created_at`, escrito pelo banco. Evento não tem "atualizado em" — ele não muda. */
    public $timestamps = false;

    protected $fillable = ['user_id', 'colony_id', 'type', 'origin', 'payload', 'created_at'];

    protected $casts = ['payload' => 'array', 'created_at' => 'datetime'];

    /**
     * O que o **ledger não enxerga**, e por isso precisa ser instrumentado à mão.
     *
     * Compra, venda, tributo, subsídio, produção e saque de guerra **não estão aqui** — o ledger já
     * os registra com mais rigor do que esta tabela teria, e duplicar criaria duas respostas para a
     * mesma pergunta. Ver o docblock da migration `2026_07_31_100000_telemetria`.
     */
    public const TIPOS = [
        // Sessão. Nada disto existia: o jogo não tem conceito de sessão, e a tabela do framework
        // é apagada no logout. Duração e intervalo entre sessões DERIVAM destes dois.
        'login',
        'logout',

        // Onboarding e progressão
        'colonia_fundada',
        'onboarding_abandonado',
        'construcao_iniciada',
        'construcao_concluida',
        'upgrade_iniciado',
        'upgrade_concluido',

        /*
         * As duas paredes em que o jogador bate. O ledger não as vê por definição: ele registra o
         * que ACONTECEU, e estas são o registro do que NÃO aconteceu — a produção que não rodou por
         * falta de insumo, a viagem que não saiu por falta de energia. É a métrica mais valiosa da
         * fase, porque é onde o jogo trava sem avisar ninguém.
         */
        'falta_de_insumo',
        'falta_de_energia',

        // Logística
        'transporte_iniciado',
        'transporte_concluido',

        // Social e território
        'federacao_entrou',
        'federacao_saiu',
        'zona_ocupada',
        'zona_perdida',
        'ataque_enviado',
        'ataque_recebido',

        // Mundo e narrativa
        'evento_global',
        'endurance_interacao',

        /*
         * O fechamento do "Desde sua última visita" (A2.0.3). É o próprio ato de ver que move a
         * janela do §5.1, então ele precisa ser um fato registrado, e não só uma coluna sobrescrita.
         */
        'resumo_visto',

        /*
         * Pesquisa (A2.3) e população (A2.2) entram com as suas fases, não antes. A regra da casa é
         * não inventar o que ainda não existe — um tipo declarado sem emissor é uma promessa que a
         * consulta do painel vai ler como zero e ninguém saberá se é zero ou se é ausência.
         */
    ];

    /** Quem produziu o evento. Bot não entra: é externo e joga em staging (GDD ALPHA 2 §14). */
    public const ORIGENS = ['humano', 'sistema'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function colony(): BelongsTo
    {
        return $this->belongsTo(Colony::class);
    }

    protected static function booted(): void
    {
        static::creating(function (self $e) {
            if (! in_array($e->type, self::TIPOS, true)) {
                throw new RuntimeException("Tipo de evento de telemetria inválido: {$e->type}");
            }

            if (! in_array($e->origin ?? 'humano', self::ORIGENS, true)) {
                throw new RuntimeException("Origem de telemetria inválida: {$e->origin}");
            }
        });

        static::updating(fn () => throw new RuntimeException('telemetria é append-only: evento não se corrige, se substitui por outro'));

        /*
         * Bloqueia o apagamento AVULSO — alguém somindo com um evento inconveniente.
         *
         * ⚠️ E não bloqueia a varredura de retenção, que apaga 90 dias de uma vez: `->delete()` no
         * query builder **não dispara evento de modelo**. Isso é limitação do Eloquent, não desenho
         * meu, e vale saber para não confiar demais nesta trava — ela guarda a porta da frente, e
         * quem escrever `TelemetryEvent::where(...)->delete()` passa por cima dela sem esbarrar.
         *
         * A trava ainda vale a pena: torna o apagamento avulso um ato deliberado, que exige
         * contornar código escrito para impedi-lo, em vez de um `$evento->delete()` distraído.
         */
        static::deleting(fn () => throw new RuntimeException('telemetria é append-only: use a varredura de retenção, que apaga por idade'));
    }
}
