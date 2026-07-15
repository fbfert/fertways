<?php

namespace App\Domain\Colony;

/**
 * O kit inicial de toda colônia nova (D-85, 2026-07-15) — 100 Fert$ e um valor fixo por recurso,
 * para os 26 do catálogo. Decisão de balanceamento do usuário, **não vem do GDD**.
 *
 * **Substitui de vez duas fontes antigas**, que existiam separadas: os raros calculados a partir
 * do custo de cada construção de progressão (D-17, "muro de progressão") e o kit fixo de cinco
 * recursos primários/secundários (D-57, `KitInicialDeRecursos`, morto neste commit). A tabela
 * abaixo é a ÚNICA fonte, para os 26 recursos de uma vez — quem já tem colônia não é tocado (só
 * vale para quem funda depois deste commit).
 *
 * **O "muro de progressão" do D-17 quebra de propósito para duas frentes**: 0 Nióbio Alienígena
 * (Torre de Defesa + Quartel exigem 5 juntas) e 2 Quartzo Piezoelétrico (Refinaria Química +
 * Antena de Comunicação exigem 3 juntas) — nenhum dos dois é produzível no jogo, só o governo
 * vende. Confirmado com o usuário: é decisão, não lacuna — defesa militar e uma das duas
 * construções de comunicação ficam trancadas até o colono comprar do Mercado. Não "conserte" sem
 * perguntar.
 */
final class KitInicial
{
    /** @var array<string,int> */
    public const RECURSOS = [
        'oxigenio' => 500,
        'agua' => 500,
        'biomassa' => 500,
        'energia' => 500,
        'metal_bruto' => 500,
        'ligas_metalicas' => 250,
        'compostos_quimicos' => 100,
        'biocombustivel' => 100,
        'componentes_eletronicos' => 100,
        'aluminio' => 25,
        'cobre' => 25,
        'estanho' => 25,
        'litio' => 25,
        'ouro' => 25,
        'silicio' => 10,
        'tantalo' => 10,
        'tungstenio' => 10,
        'bioenergia_curativa' => 5,
        'cristal_de_helio_3' => 5,
        'ferro_vermelho' => 5,
        'fungo_bioluminescente' => 0,
        'gelo_de_metano' => 15,
        'niobio_alienigena' => 0,
        'plasma_fossilizado' => 2,
        'quartzo_piezoeletrico' => 2,
        'resina_organica' => 5,
    ];
}
