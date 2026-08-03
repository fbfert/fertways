<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A curva de proteção do Depósito Local (A2.10 / D-198).
 *
 * ## ⚠️ O número estava em branco, e uma decisão de desenho se apoiava nele
 *
 * `silo_capacidades` era **10.000 em todos os dez níveis, para os 26 recursos** — o nível do prédio
 * não alterava proteção nenhuma. O D-181 já tinha registrado a tabela como plana; o D-198 mediu o que
 * isso significava na prática:
 *
 * | | |
 * |---|---|
 * | estoque do mundo | 8.233.416 |
 * | **exposto ao saque** | **7.004.832 — 85%** |
 *
 * A decisão 2 do D-193 tornou a colônia alvo *"limitada ao excedente do Depósito"*, entendendo que o
 * Depósito protege e a sobra fica em risco. Ele protegia **15%**: "excedente" queria dizer, na
 * prática, "quase tudo".
 *
 * ## A base é a alavanca, e não o fator
 *
 * ⚠️ **25 das 29 colônias estão no Depósito Local nível 1** — o mesmo retrato que a Estrutura de
 * Sobrevivência tinha antes da população. Mexer no fator por nível não move nada hoje: 1,25× protegia
 * 15%, e 1,75× protegia 16%. Quem está no nível 1 recebe a base, e ponto.
 *
 * **Base 50.000** (decisão do Dono, D-198): protege 59% do mundo e expõe 41%. Um saque de 50% do
 * exposto (§27.8) leva ~20% do que a colônia tem — dói de verdade e não arrasa.
 *
 * **+25% por nível**, a mesma forma do teto de estoque que já está no ar. Não muda nada hoje, e passa
 * a valer conforme o mundo cresce: é o que dá ao prédio uma segunda razão para subir, além da
 * capacidade.
 *
 * ## Proteção e capacidade continuam sendo duas perguntas
 *
 * O `Silo` responde *"quanto está a salvo de saque"*; o `TetoDoEstoque`, *"quanto cabe"* (D-181). Elas
 * partilham o prédio e a forma da curva, e nada mais — os números se movem em separado, e é por isso
 * que moram em tabelas diferentes.
 */
return new class extends Migration
{
    private const BASE = 50_000;

    private const FATOR_MILESIMOS = 1_250;

    public function up(): void
    {
        $this->aplicar(self::BASE, self::FATOR_MILESIMOS);
    }

    public function down(): void
    {
        // O valor plano de antes: 10.000 em todos os níveis.
        $this->aplicar(10_000, 1_000);
    }

    private function aplicar(int $base, int $fator): void
    {
        /*
         * Percorre o que EXISTE na tabela em vez de gerar as combinações: se algum recurso tiver sido
         * acrescentado ou removido do catálogo, esta migration segue o banco em vez de discordar dele.
         */
        foreach (DB::table('silo_capacidades')->distinct()->pluck('level') as $nivel) {
            $capacidade = $base;

            for ($i = 1; $i < (int) $nivel; $i++) {
                $capacidade = intdiv($capacidade * $fator, 1_000);
            }

            DB::table('silo_capacidades')->where('level', $nivel)->update(['capacidade' => $capacidade]);
        }
    }
};
