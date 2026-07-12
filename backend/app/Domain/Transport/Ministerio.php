<?php

namespace App\Domain\Transport;

use App\Models\Colony;

/**
 * O Ministério dos Transportes (D-60) — os números.
 *
 * **Leia esta divisão antes de mexer em qualquer valor daqui.** As constantes deste arquivo são de
 * duas naturezas diferentes, e confundi-las é como o projeto erra:
 *
 *  - **`CUSTO_FABRICACAO` sai do GDD** (§21.3, Caminhão de Carga, nível 1). Não se ajusta para
 *    balancear: se o GDD mudar, muda aqui; se não, não.
 *  - **Todo o resto é arbitragem do usuário** (2026-07-12), e é onde se mexe para balancear.
 *
 * ---
 *
 * **A errata que este arquivo desvia, e não resolve.** O GDD tem DUAS tabelas de custo para o
 * Caminhão, e elas divergem a partir do nível 2:
 *
 *      §21.3  Ligas  90 135 202 304 456   (curva 1,50×)
 *      §20    Ligas  90 149 245 404 667   (curva 1,65×)
 *
 * É a mesma armadilha do D-37. **O nível 1 é idêntico nas duas** — e o D-60 decidiu que só o nível
 * 1 é vendido, então a divergência não nos toca. Se um dia os níveis 2+ entrarem, é aqui que a
 * briga começa: não copie uma das duas tabelas sem antes reabrir o D-37.
 */
final class Ministerio
{
    /** O Ministério fabrica só isto. O Furgão continua vindo só no kit inicial (D-60, item 9). */
    public const TIPO = 'caminhao_de_carga';

    /**
     * GDD §21.3, Caminhão de Carga, nível 1. **Do documento, não do balanceamento.**
     *
     * Sai do caixa do Tesouro (D-57) a cada caminhão fabricado: o governo constrói com o que
     * arrecadou. Se o Tesouro não tiver isto, não há caminhão — e a redistribuição do §2.1 passa a
     * ter consequência.
     *
     * A tabela mora em `VeiculoCustos`, não aqui: a **manutenção** (D-60) custa uma fração dela, e
     * duas cópias do mesmo número do GDD acabariam divergindo.
     *
     * @return array<string,int>
     */
    public static function custoFabricacao(): array
    {
        return VeiculoCustos::nivel1(self::TIPO);
    }

    /**
     * 300 Fert$ — **arbitragem do usuário**, não do GDD.
     *
     * A preço de referência, os recursos de um Caminhão valem ~33,60 Fert$: o preço é ~9× isso, e
     * 6× o kit inicial de um colono (50 Fert$). A margem é gorda de propósito — é ela o dreno de
     * Fert$ que dá serventia ao caixa do Tesouro, e é ela que faz do caminhão um objetivo de médio
     * prazo em vez de uma compra de estreia.
     */
    public const PRECO_MICRO = 300 * Colony::MICRO_POR_FERT;

    /** 1 hora por caminhão. Arbitragem do usuário. */
    public const MINUTOS_FABRICACAO = 60;

    /**
     * A prateleira: quantos caminhões o Ministério mantém prontos. Arbitragem do usuário.
     *
     * Ele repõe sozinho no tick, até este alvo. Quem compra da prateleira leva na hora; quem a
     * esvaziou espera a fila de 1 h. Com 5 colônias em produção, 5 é um por colônia.
     */
    public const ESTOQUE_ALVO = 5;

    public static function precoFert(): float
    {
        return self::PRECO_MICRO / Colony::MICRO_POR_FERT;
    }
}
