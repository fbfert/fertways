<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Quantos operadores cada construção exige, por nível (A2.2.4).
 *
 * ## A regra, e por que ela é essa
 *
 * **Um operador por nível, e só para quem produz.** Uma Fazenda nível 3 pede 3 colonos; uma Antena,
 * que não produz recurso nenhum, não pede ninguém.
 *
 * É a regra mais simples que respeita o princípio do §7.4 — *"poucos humanos operam muitos robôs"* —
 * e ela foi **escolhida com evidência**, não por gosto. A rodada 5 da trilha A2.S comparou seis
 * configurações e mediu a métrica-chave do §7.3, o percentual de população comprometida:
 *
 * | operadores/nível | capacidade base | §7.3 |
 * |---|---|---|
 * | **1** | **10** | **52% — decisão estratégica** |
 * | 1 | 20 | 26% — população quase irrelevante |
 * | 2 | 10 | 104% — déficit: nem opera o que construiu |
 * | 2 | 20 | 52% — decisão estratégica |
 * | 3 | 10 | 156% — frustração |
 * | 3 | 20 | 78% — apertada |
 *
 * Duas caem na faixa certa, e são a mesma razão em escalas diferentes. A escolha entre elas foi de
 * **legibilidade**: "uma Fazenda nível 3 pede 3 operadores" é uma frase que se entende; "pede 6", já
 * é planilha.
 *
 * ## ⚠️ Continua HIPÓTESE, e a chave continua desligada
 *
 * Uma rodada de simulação é evidência, não campo. `population_settings.ativo` segue `false`, e nada
 * disto toca o jogo até alguém decidir virar a chave. O que mudou é que agora há **um número com
 * uma razão escrita atrás dele**, em vez de um balde vazio.
 *
 * ## Esparso de propósito
 *
 * Só entram construções com `producao_hora_json`. O que não está aqui não exige ninguém — o
 * requisito se afirma, nunca se herda de um default que ligaria mão de obra em coisas que o desenho
 * nunca pensou em ligar.
 *
 * Idempotente: `upsert` pela chave (tipo, nível).
 */
class BuildingOperatorRequirementSeeder extends Seeder
{
    /** Operadores por nível de construção produtora. Ver o docblock: saiu da rodada 5 da A2.S. */
    private const POR_NIVEL = 1;

    public function run(): void
    {
        $agora = now();
        $linhas = [];

        foreach (DB::table('building_specs')->whereNotNull('producao_hora_json')->get() as $spec) {
            /*
             * ⚠️ A Indústria Siderúrgica entra como qualquer produtora, apesar de o
             * `producao_hora_json` dela significar CONSUMO (D-172). Aqui isso não importa: o que se
             * usa é a presença do campo, que marca "esta construção opera uma cadeia", e ela opera.
             */
            $linhas[] = [
                'building_type' => $spec->building_type,
                'level' => $spec->level,
                'operadores' => self::POR_NIVEL * (int) $spec->level,
                'created_at' => $agora,
                'updated_at' => $agora,
            ];
        }

        foreach (array_chunk($linhas, 500) as $lote) {
            DB::table('building_operator_requirements')->upsert(
                $lote, ['building_type', 'level'], ['operadores', 'updated_at'],
            );
        }
    }
}
