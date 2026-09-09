<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A habitação passa a dobrar por nível: 1,65× → 2,00× (D-239, trilha A2.S rodada 8).
 *
 * ## O defeito, medido no campo
 *
 * A métrica-chave do §7.3 — percentual de população comprometida — nunca tinha sido medida **no
 * campo**, só no simulador. Medida em 2026-09-09, nas 30 colônias de produção:
 *
 * | | |
 * |---|---|
 * | mediana | **134%** |
 * | média | 166% |
 * | acima de 100% ("nem opera o que construiu") | **23 de 30** |
 * | na faixa 40–70% ("decisão estratégica") | **4 de 30** |
 *
 * A rodada 5 da A2.S escolheu a configuração de referência prevendo **52%**. O campo deu 134%, e a
 * razão da diferença é estrutural, não de calibragem:
 *
 * ⚠️ **A demanda de operadores cresce com a SOMA dos níveis da colônia; a oferta cresce com o nível
 * de UM prédio só.** A colônia mais avançada do campo tem 70 níveis construídos e uma Estrutura de
 * Sobrevivência nível 4 — 46 operadores exigidos contra 44 de teto. A colônia da rodada 5 tinha 17
 * níveis. As duas curvas não se acompanham, e nenhuma calibragem da base conserta isso: quem muda a
 * inclinação é o FATOR.
 *
 * ## Por que o fator, e não a base
 *
 * A rodada 8 varreu as duas contra três perfis reais (recém-fundada, intermediária, campo):
 *
 * | configuração | nova | intermediária | campo |
 * |---|---|---|---|
 * | 10 · 1,65× (hoje) | 50% | 59% | **105%** |
 * | 20 · 1,65× | **25%** | 30% | 52% |
 * | **10 · 2,00×** | **50%** | 40% | **58%** |
 *
 * Subir a base resolve o campo e **esvazia o começo**: 25% é o que a própria rodada 5 rotulou de
 * *"população quase irrelevante"*, e é justamente onde o jogo ensina o mecanismo. O fator não toca o
 * nível 1 — a recém-fundada continua exatamente onde estava — e alivia só quem cresceu. A pressão
 * passa a **chegar com o crescimento**, em vez de estar invertida como está hoje.
 *
 * ⚠️ **E 1,65× não era uma escolha: era cópia.** É a curva de CUSTO do jogo (D-01, aditivo v3.4 §4).
 * Habitação não é preço — usar a mesma razão faz o teto subir no ritmo em que tudo encarece,
 * enquanto a demanda sobe no ritmo em que se constrói. 2,00× é legível pelo que é: **cada nível da
 * Estrutura dobra a habitação**.
 *
 * ## Efeito medido no campo (as 30 colônias, projetado)
 *
 * | | hoje | com 2,00× |
 * |---|---|---|
 * | mediana da §7.3 | 134% | **80%** |
 * | acima de 100% | 23/30 | **11/30** |
 * | na faixa 40–70% | 4/30 | **14/30** |
 * | conseguem pagar os 2 colonos de uma ocupação | 5/30 | **18/30** |
 *
 * ⚠️ Nada é tirado de ninguém: o teto só sobe, e a restrição do §7.1 (a população concedida no
 * grandfathering precisa CABER no teto) continua satisfeita por construção.
 *
 * ## Só dado, nenhum DDL
 *
 * A coluna e o default de 1650 são de `2026_07_31_300000_populacao` e ficam onde estão — migration
 * que rodou não se reescreve. Esta só corrige a linha 1, que é o que o jogo lê. Sem `change()`,
 * sem risco de DDL divergir entre o SQLite dos testes e o MariaDB da produção (D-59).
 */
return new class extends Migration
{
    private const ANTES = 1650;

    private const DEPOIS = 2000;

    public function up(): void
    {
        DB::table('population_settings')
            ->where('id', 1)
            ->where('capacidade_fator_milesimos', self::ANTES)
            ->update(['capacidade_fator_milesimos' => self::DEPOIS, 'updated_at' => now()]);
    }

    /**
     * Volta ao 1,65×, e **só se ninguém tiver mexido depois**.
     *
     * O `where` do valor é a diferença entre desfazer esta decisão e atropelar a próxima: se alguém
     * balancear a habitação para 2.200 amanhã, um rollback cego devolveria 1650 e apagaria a
     * arbitragem dele sem deixar rastro.
     */
    public function down(): void
    {
        DB::table('population_settings')
            ->where('id', 1)
            ->where('capacidade_fator_milesimos', self::DEPOIS)
            ->update(['capacidade_fator_milesimos' => self::ANTES, 'updated_at' => now()]);
    }
};
