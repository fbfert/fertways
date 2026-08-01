<?php

namespace App\Domain\Pesquisa;

use App\Domain\Endurance\EfeitosDaEndurance;

/**
 * Os efeitos que só a pesquisa tem (A2.3), somados ao vocabulário compartilhado.
 *
 * ## Por que precisou existir
 *
 * A primeira rodada do simulador (D-169) mostrou que `tec_ciencia_1` e `tec_defesa_1` davam
 * `producao_bonus` ao **Laboratório** e à **Torre de Defesa** — prédios que **não produzem recurso
 * nenhum**. O bônus era matematicamente zero: duas trilhas inteiras inertes por construção.
 *
 * O vocabulário do `EfeitosDaEndurance` não tinha como expressar o que essas trilhas deveriam fazer.
 * Estas duas chaves preenchem essa lacuna, e ficam aqui — e não lá — porque são da pesquisa: a
 * Endurance não tem por que saber de duração de pesquisa.
 */
final class Efeitos
{
    /**
     * Encurta a duração das pesquisas seguintes. É o efeito natural da trilha de Ciência: ela não
     * produz recurso, ela produz **conhecimento mais rápido**.
     */
    public const DURACAO_PESQUISA = 'duracao_pesquisa';

    /**
     * ⚠️ **Declarado e SEM CONSUMIDOR.**
     *
     * A trilha de Defesa deveria fortalecer a Torre de Defesa, e isso vive no motor de combate
     * (§27), que é superfície grande e não pertence a esta fase. Então o efeito existe, é cadastrável
     * e é somável — e não faz nada ainda.
     *
     * É deliberado e tem precedente na casa: o D-67 e o D-79 ergueram seis estruturas de zona
     * **inertes de propósito** — "erguem-se, custam, não fazem nada até o sistema de que dependem
     * existir". A alternativa seria dar à Defesa um efeito que ela não tem só para o número não ficar
     * feio, e isso é pior: mentira com aparência de funcionalidade.
     *
     * O simulador reporta esta trilha como "sem consumidor", distinguindo-a de "sem volume
     * modelado" — são ausências diferentes e não devem ser confundidas.
     */
    public const DEFESA_BONUS = 'defesa_bonus';

    public const TIPOS = [self::DURACAO_PESQUISA, self::DEFESA_BONUS];

    /**
     * Tetos agregados, no mesmo espírito dos da Endurance.
     *
     * A duração tem teto BAIXO de propósito: pesquisa instantânea destruiria o custo de oportunidade
     * que a fase inteira existe para criar. 4000 bps = no máximo 40% mais rápido.
     */
    public const TETO_BPS = [
        self::DURACAO_PESQUISA => 4000,
        self::DEFESA_BONUS => 5000,
    ];

    /** O teto de um tipo, venha ele deste vocabulário ou do da Endurance. */
    public static function tetoBps(string $tipo): int
    {
        return self::TETO_BPS[$tipo] ?? EfeitosDaEndurance::tetoBps($tipo);
    }

    public static function conhecido(string $tipo): bool
    {
        return in_array($tipo, self::TIPOS, true)
            || in_array($tipo, EfeitosDaEndurance::TIPOS, true);
    }
}
