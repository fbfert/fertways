<?php

namespace App\Domain\Building;

use App\Domain\Colony\TetoDoEstoque;
use App\Domain\Colony\TetoDoTanque;
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
 * ⚠️ O roadmap da A2.V3 também lista *"falta de energia"* e *"operadores"*. **Nenhum dos dois existe
 * como estado de construção**, e não são inventados aqui:
 *
 * - **energia** não trava construção nenhuma. O saldo pode ficar negativo e o estoque simplesmente
 *   trava em zero (D-20) — o GDD nunca definiu prédio parado por falta de energia, e desenhar um
 *   seria publicar uma regra que ninguém decidiu.
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

    /** Produz e há espaço para o que ela rende. */
    public const PRODUZINDO = 'produzindo';

    public function __construct(
        private TetoDoEstoque $tetoDoEstoque,
        private TetoDoTanque $tetoDoTanque,
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
        /*
         * O teto é calculado mesmo quando o relógio está rodando, e não é desperdício: uma
         * construção que sobe do 3 para o 4 **continua produzindo no 3** enquanto sobe (é o que
         * `ColonyTick::taxasNominais()` faz), então ela pode estar melhorando E travada ao mesmo
         * tempo. O estado principal é o relógio, porque é o que muda sozinho e tem hora para
         * acabar; a lista de recursos no teto vai junto para a tela não perder o outro fato.
         */
        $noTeto = $this->recursosNoTeto($colonia, $producaoHora, $estoque);

        if ($b->upgrade_finish_at !== null) {
            return [
                'estado' => $b->level > 0 ? self::MELHORANDO : self::ERGUENDO,
                'recursos_no_teto' => $noTeto,
            ];
        }

        // Nível 0 sem relógio: a linha existe e a obra não começou (está atrás de outra na fila).
        // Continua sendo obra nova aos olhos de quem olha a colmeia.
        if ($b->level <= 0) {
            return ['estado' => self::ERGUENDO, 'recursos_no_teto' => $noTeto];
        }

        if ($producaoHora === null || $producaoHora === []) {
            return ['estado' => null, 'recursos_no_teto' => []];
        }

        return [
            'estado' => count($noTeto) === count($producaoHora) ? self::TRAVADA : self::PRODUZINDO,
            'recursos_no_teto' => $noTeto,
        ];
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
