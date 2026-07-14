<?php

namespace App\Domain\Production;

/**
 * A Indústria Siderúrgica — construção nova, pedida pelo usuário (docs/decisoes.md D-82).
 *
 * **Não está no GDD.** Processa Metal Bruto em Ligas Metálicas e nos cinco minerais eletrônicos
 * que, na Temporada 1, só o governo extrai (§4.3: "jogadores não extraem esses minerais"). É
 * arbitragem consciente do usuário — a mesma família do tributo (D-32) e do Ministério dos
 * Transportes (D-60): contraria o texto de propósito, e não se "conserta" sem perguntar.
 *
 * Existe em dois lugares — a colônia (`ColonyTick`) e a zona neutra de Metal Bruto
 * (`ProcessarSiderurgicaNaZona`) —, e os dois usam a MESMA receita e a MESMA tabela de custo
 * (`building_specs`, tipo `industria_siderurgica`): a taxa de processamento no nível 1 é a da Mina
 * Local nível 1 (15 Metal Bruto/h), e sobe espelhando a Mina nível a nível; o custo é o da Mina
 * vezes 1,25 (half-up), e o tempo de construção também.
 */
class Siderurgica
{
    public const INSUMO = 'metal_bruto';

    /** A cada BASE unidades de Metal Bruto processado, produz as SAIDAS abaixo. */
    public const BASE = 1000;

    /** @var array<string,int> recurso => quantidade por BASE de Metal Bruto */
    public const SAIDAS = [
        'ligas_metalicas' => 350,
        'aluminio' => 35,
        'cobre' => 30,
        'estanho' => 20,
        'ouro' => 4,
        'tungstenio' => 1,
    ];

    /**
     * Só os minerais eletrônicos vão para o depósito multi-recurso da zona (`zone_minerals`).
     * Ligas Metálicas segue para `refined_amount` — é o mesmo recurso que a Refinaria de Campo já
     * produz ali, e as duas construções somam no mesmo total.
     */
    public const SAIDAS_MINERAIS = ['aluminio', 'cobre', 'estanho', 'ouro', 'tungstenio'];
}
