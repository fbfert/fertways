<?php

namespace App\Domain\Pesquisa;

use App\Models\Colony;
use Illuminate\Support\Facades\DB;

/**
 * Quantas pesquisas simultâneas uma colônia aguenta (A2.3).
 *
 * ## Nasce extensível, e o roadmap pede isso por escrito
 *
 * Hoje há **uma única fonte de vagas**: o nível do Laboratório. O Observatório, que no desenho
 * original daria paralelismo, **não existe no jogo** — criá-lo exige decisão de slot, arte e
 * especificação próprias, e não cabe na fase que introduz a árvore inteira (GDD ALPHA 2 §7.2).
 *
 * Mas o mecanismo é escrito como **soma de contribuições**, e não como uma conta que sabe do
 * Laboratório. Acrescentar o Observatório depois é acrescentar um método a `fontes()` — não
 * refazer o modelo. É a diferença entre adiar uma peça e se pintar num canto.
 *
 * ## O teto existe, e não é decoração
 *
 * Sem teto, uma colônia com Laboratório muito alto pesquisaria tudo em paralelo e a árvore deixaria
 * de ser escolha — viraria uma lista de espera. O ponto da fase é obrigar a escolher; vaga infinita
 * mata isso mais depressa do que qualquer número errado de custo.
 */
class Vagas
{
    /**
     * Quantas vagas o Observatório SOMA, por nível (D-256).
     *
     * A ficha do v35 ("11. Observatório") publica as trilhas simultâneas com ele: 2/2/3/3/4. Lá ele
     * substituía o Laboratório; aqui ele soma ao Laboratório, que já dá a vaga base — então a
     * contribuição é a ficha menos essa base. Com o Laboratório no nível que o Observatório exige
     * (3, que dá só a base), o total é exatamente o do v35.
     *
     * ⚠️ Era `intdiv(nível, vagas_por_niveis_de_laboratorio)` (D-252): com o divisor padrão, 5, os
     * níveis 1 a 4 não davam vaga nenhuma, e o jogador pagava quatro níveis por nada.
     */
    public const OBSERVATORIO_POR_NIVEL = [1 => 1, 2 => 1, 3 => 2, 4 => 2, 5 => 3];

    /** O Laboratório que o Observatório exige — a matriz de especializações do v35. */
    public const LABORATORIO_PARA_O_OBSERVATORIO = 3;

    /**
     * @return array<string,int> quanto cada fonte contribui, para a tela poder explicar o número
     */
    public function fontes(Colony $colonia): array
    {
        $p = DB::table('research_settings')->find(1);

        $nivelLab = (int) ($colonia->buildings->firstWhere('type', 'laboratorio')?->level ?? 0);

        /*
         * Sem Laboratório erguido não há pesquisa nenhuma — nem a vaga base. A base existe para
         * dizer "quem tem Laboratório já pesquisa uma coisa", não para dar pesquisa a quem não o
         * construiu.
         */
        if ($nivelLab < 1) {
            return ['laboratorio' => 0];
        }

        $porNivel = max(1, (int) $p->vagas_por_niveis_de_laboratorio);

        $nivelObs = (int) ($colonia->buildings->firstWhere('type', 'observatorio')?->level ?? 0);

        return [
            'laboratorio' => (int) $p->vagas_base + intdiv($nivelLab, $porNivel),
            /*
             * D-252: o Observatório, com a linha que este método prometia desde a A2.3. Sem a base,
             * que é do Laboratório — quem abre a árvore. Desde o D-256, pela ficha do v35
             * (`OBSERVATORIO_POR_NIVEL`), e não mais pela razão do Laboratório. O teto
             * (`vagas_teto`) vale para a soma, em `total()` — duas fontes não furam o teto.
             */
            'observatorio' => self::OBSERVATORIO_POR_NIVEL[$nivelObs] ?? 0,
        ];
    }

    public function total(Colony $colonia): int
    {
        $p = DB::table('research_settings')->find(1);

        return min((int) $p->vagas_teto, array_sum($this->fontes($colonia)));
    }

    /** Quantas pesquisas estão correndo agora. */
    public function ocupadas(Colony $colonia): int
    {
        return DB::table('colony_technologies')
            ->where('colony_id', $colonia->id)
            ->where('status', 'pesquisando')
            ->count();
    }

    public function livres(Colony $colonia): int
    {
        return max(0, $this->total($colonia) - $this->ocupadas($colonia));
    }
}
