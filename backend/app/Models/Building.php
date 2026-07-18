<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Building extends Model
{
    protected $fillable = ['colony_id', 'type', 'level', 'slot', 'recipe', 'upgrade_started_at', 'upgrade_finish_at'];

    protected $casts = [
        'level' => 'integer',
        'slot' => 'integer',
        'upgrade_started_at' => 'datetime',
        'upgrade_finish_at' => 'datetime',
    ];

    /**
     * As cinco essenciais (GDD §24.7, verbatim). Subsidiadas em 100% até o nível 3,
     * mediante conclusão da tutoria. A partir do nível 4, custo integral.
     */
    public const ESSENCIAIS = [
        'gerador_de_atmosfera',
        'estrutura_de_sobrevivencia',
        'fazenda',
        'reator_de_energia',
        'captacao_de_agua',
    ];

    /**
     * Construções de progressão do MVP. "Nunca são subsidiadas — exigem produção própria
     * desde o nível 1" (§24.7).
     *
     * Mina Local e Destilaria não constavam da lista original do MVP, mas o item 3 exige
     * a cadeia Metal Bruto -> Componentes e a cadeia do Biocombustível, e elas são as
     * únicas fontes desses recursos. Ver docs/decisoes.md D-06.
     */
    public const PROGRESSAO = [
        'oficina',
        'refinaria_quimica',
        'laboratorio',
        'antena_de_comunicacao',
        'torre_de_defesa',
        'mercado_local',
        'quartel',
        'plataforma_de_pouso',
        'central_de_transportes',
        'mina_local',
        'destilaria',
        // O GDD sempre listou DOZE construções de progressão (§04 e §28.6, idênticas), e o
        // Tanque de Combustível (§21.9, "armazena Gelo de Metano refinado") era a única que
        // ficara de fora do MVP, sem motivo registrado. Entra no D-59, agora que há slot para
        // ela. Custo e tempo já estavam semeados em `building_specs`.
        'tanque_de_combustivel',
        // Não está no GDD — construção nova, pedida pelo usuário (D-82).
        'industria_siderurgica',
    ];

    public const MVP = [...self::ESSENCIAIS, ...self::PROGRESSAO];

    /**
     * As que podem ocupar mais de um slot, cada cópia com o seu nível (D-59).
     *
     * São as produtoras de progressão: têm produção/h publicada no GDD e alimentam a cadeia
     * industrial, então repetir é estratégia econômica (especializar a colônia em metal, em
     * química) e não truque. A produção e o consumo de energia somam linearmente entre as
     * cópias — o freio é o Reator, que é o limite que o próprio GDD usa (§19.8).
     *
     * As 5 essenciais ficam **únicas** de propósito: fossem repetíveis, o subsídio do §24.7
     * (Governo paga até o nível 3) viraria torneira aberta — a enésima Fazenda sairia de graça.
     * As de função única (Antena, Laboratório, Quartel, Plataforma, Mercado Local, Torre,
     * Central de Transportes, Tanque) também são únicas: duas Antenas não querem dizer nada, e
     * o §28.5 amarra o teto de caminhões ao NÍVEL da Central de Transportes, não à contagem.
     */
    public const REPETIVEIS = [
        'mina_local',
        'oficina',
        'refinaria_quimica',
        'destilaria',
        // A Indústria Siderúrgica é da mesma família — produtora de progressão, com produção/h
        // publicada (aqui, arbitrada — D-82) e sem GDD que a torne única.
        'industria_siderurgica',
    ];

    public function podeRepetir(): bool
    {
        return in_array($this->type, self::REPETIVEIS, true);
    }

    public function colony(): BelongsTo
    {
        return $this->belongsTo(Colony::class);
    }

    public function ehEssencial(): bool
    {
        return in_array($this->type, self::ESSENCIAIS, true);
    }

    /**
     * Indemolível = as cinco essenciais + o Depósito Local (D-105). Ele nasce no slot 21, que
     * `Slots::exigirEscolhivel()` nunca deixa reconstruir — demoli-lo tiraria pra sempre o único
     * jeito de ver os recursos, e o slot ficaria vazio sem chance de voltar. Mesmo motivo das
     * essenciais (miolo sem chance de refazer), mas não é uma delas: não conta pro subsídio do
     * §24.7 nem pro selo "essencial" da tela, que é textual do GDD.
     */
    public function ehIndemolivel(): bool
    {
        return $this->ehEssencial() || $this->type === 'deposito_local';
    }

    /** Nível 0 = ainda não construída. As specs do GDD começam no nível 1. */
    public function construida(): bool
    {
        return $this->level > 0;
    }
}
