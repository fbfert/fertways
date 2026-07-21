<?php

namespace App\Domain\Missoes;

/**
 * As ações que os ganchos do jogo DE FATO disparam (D-78).
 *
 * É a lista que o painel oferece ao criar um molde — e a trava contra a missão impossível: um
 * molde com ação que nenhum gancho conhece nunca progrediria, a tela diria "0/3" para sempre, e
 * ninguém saberia por quê (a mesma classe de silêncio do vínculo com chave errada, D-72).
 *
 * ⚠️ Quem cria um gancho novo REGISTRA aqui — o teste que percorre o catálogo contra esta lista
 * é o que impede o seeder e o painel de divergirem dos ganchos.
 */
final class Acoes
{
    public const TODAS = [
        'obra_concluida' => 'Concluir nível de construção',
        'zona_ocupada' => 'Ocupar zona neutra',
        'combate_vencido' => 'Vencer combate',
        'acordo_executado' => 'Acordo de Troca executado (piso do D-43)',
        'mercado_executado' => 'Negócio no Mercado Central (piso do D-43)',
        'ordem_colocada' => 'Colocar ordem no Mercado Central',
        'despacho' => 'Despachar veículo',
        'fabricar_unidade' => 'Fabricar unidade no Quartel',
        'missao_drone' => 'Enviar Drone em missão',
        'niobio_comprado' => 'Comprar Nióbio do governo',
        'chat_mensagem' => 'Falar num canal público',
        'frete_publico' => 'Usar o frete público',
        'manutencao' => 'Fazer manutenção de veículo',
        // Pedido do usuário (2026-07-16).
        'compra_governo_mercado' => 'Comprar recursos do Governo no Mercado Central',
        'compra_veiculo_novo' => 'Compre um veículo Novo',
        'compra_veiculo_usado' => 'Compre um veículo Usado',
        'venda_veiculo_usado' => 'Venda seu primeiro veículo',
        // D-140: a narrativa da Endurance começa com o primeiro achado nos destroços.
        'comprar_item_endurance' => 'Comprar item da Loja de Peças da Endurance',
    ];
}
