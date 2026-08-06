<?php

namespace App\Domain\Building;

use App\Domain\Colony\TetoDoEstoque;
use App\Domain\Colony\TetoDoTanque;
use App\Domain\Production\ColonyTick;
use App\Domain\Production\Siderurgica;
use App\Models\Building;
use App\Models\Colony;

/**
 * Em que pé está esta construção, para a colmeia poder desenhar (A2.V3).
 *
 * ## Por que isto existe
 *
 * A cena da colônia sabia distinguir três coisas: slot vazio, obra nova e construção erguida. **Só
 * três**, para uma tela que é o jogo inteiro. Medido contra a produção antes de escolher o que
 * desenhar — o método do D-210 —, dois estados já eram **verdade no servidor e não apareciam em
 * lugar nenhum**:
 *
 * - **em upgrade**: `upgrade_finish_at` já vinha no payload desde sempre, mas a cena só olhava
 *   `level > 0`. Uma construção subindo do 3 para o 4 ficava **idêntica** a uma parada no 3. O
 *   relógio existia no dado e não existia na tela.
 * - **travada pelo teto** (§14): 5 recursos estavam no teto em 2 das colônias no dia da medição —
 *   Gerador, Captação, Fazenda e Reator rodando e **rendendo zero**, sem nada na tela dizendo isso.
 *   O §14 promete que *"o jogador perde oportunidade, nunca estoque"*; perder oportunidade sem
 *   saber é perder as duas.
 *
 * ## O que este estado NÃO inventa
 *
 * ⚠️ O roadmap da A2.V3 também lista *"falta de energia"* e *"operadores"*:
 *
 * - **energia** não trava a **operação** de construção nenhuma. O saldo pode ficar negativo e o
 *   estoque trava em zero (D-20); o GDD nunca definiu prédio desligado por falta de energia.
 *
 *   ⚠️ **MAS ela trava as RECEITAS, e eu afirmei o contrário aqui — estava errado (D-219).** A
 *   Destilaria pede 3 de energia por lote, a Refinaria 6, e as três receitas da Oficina 10/14/20.
 *   `ColonyTick::converter()` não converte sem insumo. Medido em produção: **58 das 66 fábricas de
 *   conversão do mundo não produzem nada**, 53 delas por energia, e todas apareciam nesta tela como
 *   `produzindo`. É o que o estado `SEM_INSUMO` passou a dizer.
 * - **operadores** são de **zona neutra**, não da colmeia (D-184). O lugar deles é a A2.V4.
 *
 * Quando não dá para afirmar, este serviço devolve `estado => null` e a cena desenha o que sempre
 * desenhou. **Ausência de afirmação, nunca afirmação errada.**
 */
class EstadoDaConstrucao
{
    /** Obra nova: o slot está ocupado e a construção ainda não subiu (nível 0). */
    public const ERGUENDO = 'erguendo';

    /** Já erguida e subindo de nível. Continua produzindo no nível atual enquanto sobe. */
    public const MELHORANDO = 'melhorando';

    /** Produz, e TUDO o que ela produz está no teto: o tick não credita mais nada (§14). */
    public const TRAVADA = 'travada';

    /**
     * Converte, e falta insumo: `ColonyTick::converter()` não converte nada (D-219).
     *
     * Medido em produção no dia em que este estado nasceu: **58 das 66 fábricas de conversão do
     * mundo** estavam assim, 53 delas por **energia** — e a tela chamava todas de `produzindo`. Treze
     * Refinarias Químicas tinham sido erguidas e custeadas sem jamais converter um lote.
     */
    public const SEM_INSUMO = 'sem_insumo';

    /** Produz e há espaço para o que ela rende. */
    public const PRODUZINDO = 'produzindo';

    public function __construct(
        private TetoDoEstoque $tetoDoEstoque,
        private TetoDoTanque $tetoDoTanque,
        private ColonyTick $tick,
    ) {
    }

    /**
     * @param  array<string,int>|null  $producaoHora  o que ela rende por hora no nível ATUAL, ou
     *                                                `null` quando a construção não declara produção
     *                                                (Quartel, Mercado Local, Torre de Defesa…).
     *                                                ⚠️ A Indústria Siderúrgica declara em
     *                                                `producao_hora_json` o que **consome**, não o
     *                                                que produz; quem chama já resolve isso e passa
     *                                                `null` — ver `BuildingController::specs`.
     * @param  array<string,int>  $estoque  quanto a colônia tem de cada recurso. Vem de fora para
     *                                      não fazer N+1 numa tela que pede 22 slots de uma vez.
     * @return array{estado: string|null, recursos_no_teto: list<string>}
     */
    public function de(Colony $colonia, Building $b, ?array $producaoHora, array $estoque): array
    {
        $faltando = $this->insumosEmFalta($b, $estoque);

        /*
         * O teto é calculado mesmo quando o relógio está rodando, e não é desperdício: uma
         * construção que sobe do 3 para o 4 **continua produzindo no 3** enquanto sobe (é o que
         * `ColonyTick::taxasNominais()` faz), então ela pode estar melhorando E travada ao mesmo
         * tempo. O estado principal é o relógio, porque é o que muda sozinho e tem hora para
         * acabar; a lista de recursos no teto vai junto para a tela não perder o outro fato.
         */
        $noTeto = $this->recursosNoTeto($colonia, $producaoHora, $estoque);

        if ($b->upgrade_finish_at !== null) {
            return $this->resposta($b->level > 0 ? self::MELHORANDO : self::ERGUENDO, $noTeto, $faltando);
        }

        // Nível 0 sem relógio: a linha existe e a obra não começou (está atrás de outra na fila).
        // Continua sendo obra nova aos olhos de quem olha a colmeia.
        if ($b->level <= 0) {
            return $this->resposta(self::ERGUENDO, $noTeto, $faltando);
        }

        /*
         * ⚠️ Falta de insumo vem ANTES do teto de saída, e a ordem é escolha.
         *
         * As duas podem ser verdade juntas, e nenhuma sozinha explica tudo. Mas a boca fechada é
         * **a montante**: uma fábrica que não consegue consumir também não está enchendo nada, e o
         * teto de saída dela é consequência, não causa.
         *
         * E é a menos descobrível das duas: o depósito cheio o jogador vê no Depósito Local, com
         * número e barra; a energia que falta para a receita não aparece em tela nenhuma — foi
         * exatamente por isso que 58 fábricas ficaram paradas sem ninguém notar.
         */
        if ($faltando !== []) {
            return $this->resposta(self::SEM_INSUMO, $noTeto, $faltando);
        }

        if ($producaoHora === null || $producaoHora === []) {
            return $this->resposta(null, [], []);
        }

        return $this->resposta(
            count($noTeto) === count($producaoHora) ? self::TRAVADA : self::PRODUZINDO,
            $noTeto,
            $faltando,
        );
    }

    /**
     * @param  list<string>  $noTeto
     * @param  list<string>  $faltando
     * @return array{estado: string|null, recursos_no_teto: list<string>, insumos_em_falta: list<string>}
     */
    private function resposta(?string $estado, array $noTeto, array $faltando): array
    {
        return ['estado' => $estado, 'recursos_no_teto' => $noTeto, 'insumos_em_falta' => $faltando];
    }

    /**
     * Quais insumos da receita desta construção não dão nem para um lote.
     *
     * ## Por que `< $porUnidade`, e não `<= 0`
     *
     * É o que `ColonyTick::converter()` faz na prática: ele calcula quantos lotes cabem no insumo
     * disponível e, faltando para um, não converte nada. Perguntar só por zero deixaria passar a
     * Refinaria com 3 de energia numa receita que pede 6 — parada do mesmo jeito, e dizendo à tela
     * que está produzindo.
     *
     * ## As quatro fábricas, e de onde sai a receita de cada uma
     *
     * Destilaria e Refinaria têm receita fixa no `ColonyTick`. A Oficina tem **três** e escolhe uma
     * (§24.5) — é por isso que a receita dela sai de `component_recipes` pelo `recipe` da própria
     * construção, e não do tipo. A Indústria Siderúrgica processa um insumo só (`Siderurgica::INSUMO`)
     * e é a razão de este método existir separado do `producao_hora`: ela é a única cujo
     * `producao_hora_json` descreve o que **entra**, não o que sai (D-82).
     *
     * @param  array<string,int>  $estoque
     * @return list<string>
     */
    private function insumosEmFalta(Building $b, array $estoque): array
    {
        if ($b->level < 1) {
            return [];
        }

        $receita = match ($b->type) {
            'destilaria' => ColonyTick::RECEITA_DESTILARIA,
            'refinaria_quimica' => ColonyTick::RECEITA_COMPOSTOS,
            'industria_siderurgica' => [Siderurgica::INSUMO => 1],
            'oficina' => $this->tick->receita($b->recipe ?? ColonyTick::RECEITA_PADRAO),
            default => [],
        };

        $falta = [];

        foreach ($receita as $insumo => $porUnidade) {
            if ((int) ($estoque[$insumo] ?? 0) < $porUnidade) {
                $falta[] = $insumo;
            }
        }

        return $falta;
    }

    /**
     * Quais dos recursos que ela produz não têm mais para onde ir.
     *
     * Devolvida **sempre**, e não só quando trava tudo: uma construção que rende dois recursos e tem
     * um deles cheio está produzindo pela metade, e isso é um fato que a tela pode querer contar sem
     * chamar a construção inteira de travada.
     *
     * @param  array<string,int>|null  $producaoHora
     * @param  array<string,int>  $estoque
     * @return list<string>
     */
    private function recursosNoTeto(Colony $colonia, ?array $producaoHora, array $estoque): array
    {
        if ($producaoHora === null) {
            return [];
        }

        $cheios = [];

        foreach (array_keys($producaoHora) as $recurso) {
            if ($this->semEspaco($colonia, $recurso, (int) ($estoque[$recurso] ?? 0))) {
                $cheios[] = $recurso;
            }
        }

        return $cheios;
    }

    /**
     * ⚠️ O Biocombustível não passa pelo teto geral — ele tem prédio próprio.
     *
     * `TetoDoEstoque::SEM_TETO_GERAL` o exclui de propósito (§21.9/D-131: quem o limita é o Tanque de
     * Combustível, com curva própria). Perguntar só ao teto geral devolveria "tem espaço" para uma
     * Destilaria com o tanque cheio — que é justamente o caso que o D-131 criou e que a tela mais
     * precisa mostrar.
     */
    private function semEspaco(Colony $colonia, string $recurso, int $jaTem): bool
    {
        if ($recurso === 'biocombustivel') {
            return $jaTem >= $this->tetoDoTanque->capacidade($colonia);
        }

        return $this->tetoDoEstoque->espacoLivre($colonia, $recurso, $jaTem) === 0;
    }
}
