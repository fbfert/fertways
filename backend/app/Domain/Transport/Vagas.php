<?php

namespace App\Domain\Transport;

use App\Exceptions\DomainRuleException;
use App\Models\Colony;

/**
 * O teto de frota do colono — o novo (e único) papel da Central de Transportes (D-60).
 *
 * **O que a Central sempre foi, e o que o jogo nunca cobrou.** O §28.5 dizia que os caminhões do
 * nível "já estão incluídos no upgrade da Central — sem custo adicional", mas a tabela de
 * precedência da seção 0 revogou isso e o **D-28** registrou: "libera vagas de frota; veículo é
 * fabricado ou adquirido separadamente". A Central nunca deu caminhão. O que ela dá é **vaga** — e
 * até o D-60 esse limite não valia no jogo, porque não havia veículo a limitar: nada fabricava
 * caminhão. Agora o Ministério fabrica, e a vaga passa a morder.
 *
 * **teto = máximo(1, nível da Central).**
 *
 * O piso de 1 resolve um problema que o D-59 criou. Desde ele, construção não erguida não existe —
 * então **toda colônia nova tem zero Central de Transportes** —, e o kit inicial dá um Furgão. Sem
 * o piso, o Furgão do kit nasceria fora da lei, na primeira colônia fundada.
 *
 * E ele **preserva as duas tabelas do GDD**, o que nenhuma outra fórmula fazia:
 *
 *   - §19.5: a Central dá 1..10 vagas, uma por nível.
 *   - §17.3: o Terminal de Cargas "acrescenta duas vagas em cada nível" e publica 3..12 —
 *     que é exatamente 1..10 mais 2.
 *
 * O efeito colateral, aceito pelo usuário: **erguer a Central no nível 1 não dá vaga nova** (o
 * colono já tinha 1 pelo piso). Ela só começa a pagar no nível 2.
 *
 * O teto conta **todos os veículos**, não só os caminhões — decisão do usuário, contra a redação
 * do §19.5 ("caminhões base") e a favor da do §17.3 ("limite total de veículos"). O GDD diverge de
 * si mesmo aqui; escolheu-se a segunda.
 */
class Vagas
{
    public function teto(Colony $colony): int
    {
        $nivel = (int) $colony->buildings()
            ->where('type', 'central_de_transportes')
            ->max('level');

        return max(1, $nivel);
    }

    /** Quantos veículos o colono já tem. Inclui os que estão em rota — eles são dele. */
    public function ocupadas(Colony $colony): int
    {
        return $colony->vehicles()->count();
    }

    public function livres(Colony $colony): int
    {
        return max(0, $this->teto($colony) - $this->ocupadas($colony));
    }

    /**
     * Barra a compra que não caberia na frota.
     *
     * **Arbitragem do assistente (D-60):** o GDD não diz o que fazer com quem compra sem vaga. Mas
     * um teto que não impede nada é decoração — é a mesma lógica com que o D-58 fez o despacho
     * respeitar o teto do depósito. Barrar aqui, **antes** de o Fert$ sair, é o que impede o colono
     * de pagar 300 Fert$ por um caminhão que não pode ter.
     */
    public function exigirVagaLivre(Colony $colony): void
    {
        if ($this->livres($colony) < 1) {
            $teto = $this->teto($colony);

            throw new DomainRuleException(
                'frota_cheia',
                "A sua frota está no teto de {$teto} veículo(s). Suba a Central de Transportes para abrir vaga.",
            );
        }
    }
}
