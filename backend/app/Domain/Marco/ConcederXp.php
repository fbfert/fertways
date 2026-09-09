<?php

namespace App\Domain\Marco;

use App\Domain\Missoes\Janela;
use App\Models\Colony;
use App\Models\MilestoneSetting;
use App\Models\XpEntry;

/**
 * Concede XP por um ato — a ÚNICA porta de entrada do ledger de XP (D-75).
 *
 * Cada ato registrado vale o que o painel do operador declara (`milestone_settings`), e deixa uma
 * linha em `xp_entries`: XP não nasce sem história, pela mesma regra do ledger de recursos. O
 * `colonies.xp` é só o cache do somatório, incrementado na mesma escrita.
 *
 * As ações e onde elas disparam:
 *
 *   obra_concluida       ColonyTick::concluir (por nível) e CreateColony (as 5 essenciais, ×5)
 *   zona_ocupada         OcuparZonaNeutra
 *   combate_vencido      ResolverCombates — conquista (atacante), defesa que segura (defensor),
 *                        cerco rompido (o sitiado que saiu a campo e venceu)
 *   acordo_executado     Reputacao::fechar — os DOIS lados, e só acima do piso do D-43: o XP herda
 *                        o anti-farm da reputação (uma unidade de minério mil vezes não sobe marco)
 *   mercado_executado    ExecutarOrdem — os dois lados
 *
 * Quando as missões do §06 existirem, pagam XP por AQUI — é o gancho que o GDD pediu.
 */
class ConcederXp
{
    private const CAMPO = [
        'obra_concluida' => 'xp_obra_por_nivel',
        'zona_ocupada' => 'xp_zona_ocupada',
        'combate_vencido' => 'xp_combate_vencido',
        'acordo_executado' => 'xp_acordo_executado',
        'mercado_executado' => 'xp_mercado_executado',
    ];

    /**
     * Atos que rendem XP no máximo N vezes por DIA DE MISSÃO, e onde N é declarado (D-241).
     *
     * ⚠️ **Teto, e não piso de valor.** O Mercado usava o piso anti-farm da reputação (5 Fert$,
     * D-43/D-117) e ele falhava nas duas pontas: medido em produção, **100,0% das 13.551 execuções**
     * ficavam abaixo dele — a regra disparou 3 vezes em 1.507 ordens —, e mesmo assim ele não
     * detinha a fraude, porque num mercado **o preço é das partes**: dois cúmplices anunciam uma
     * unidade por 100 Fert$ e passam do piso quando quiserem.
     *
     * Um teto por dia não depende de valor, e por isso não se contorna com preço: farmar rende no
     * máximo o teto, faça-se uma troca ou mil.
     *
     * @var array<string,string>
     */
    private const TETO_DIARIO = [
        'mercado_executado' => 'xp_mercado_teto_diario',
    ];

    public function handle(int $colonyId, string $acao, ?string $ref = null, int $vezes = 1): void
    {
        $config = MilestoneSetting::singleton();

        if ($this->batidoOTetoDoDia($colonyId, $acao, $config)) {
            return;
        }

        $porVez = (int) $config->{self::CAMPO[$acao]};

        $this->direto($colonyId, $acao, $porVez * max(1, $vezes), $ref);
    }

    /**
     * A colônia já recebeu por este ato o que o dia permite?
     *
     * O dia é o **dia de missão** do §06 (07h→07h, `Janela`), e não a meia-noite: é a régua que o
     * jogo já usa para dizer o que se pode fazer hoje, e ter duas seria ter duas respostas para a
     * mesma pergunta.
     *
     * ⚠️ Zero desliga o TETO, não a fonte — a fonte se desliga zerando o XP do ato, que é a
     * convenção do `direto()` desde o D-75.
     */
    private function batidoOTetoDoDia(int $colonyId, string $acao, MilestoneSetting $config): bool
    {
        $campo = self::TETO_DIARIO[$acao] ?? null;

        if ($campo === null) {
            return false;
        }

        $teto = (int) $config->{$campo};

        if ($teto <= 0) {
            return false;
        }

        return XpEntry::where('colony_id', $colonyId)
            ->where('acao', $acao)
            ->where('created_at', '>=', Janela::diaAtual())
            ->count() >= $teto;
    }

    /**
     * XP com valor EXPLÍCITO — a porta das missões (D-78): elas pagam o que o catálogo diz, não o
     * que a tabela de atos do D-75 diz. Continua sendo a única forma de XP nascer: com linha.
     */
    public function direto(int $colonyId, string $acao, int $xp, ?string $ref = null): void
    {
        // Zerar um valor DESLIGA a fonte — sem linhas vazias sujando o ledger.
        if ($xp <= 0) {
            return;
        }

        XpEntry::create([
            'colony_id' => $colonyId,
            'acao' => $acao,
            'xp' => $xp,
            'ref' => $ref !== null ? mb_substr($ref, 0, 80) : null,
            'created_at' => now(),
        ]);

        Colony::whereKey($colonyId)->increment('xp', $xp);
    }
}
