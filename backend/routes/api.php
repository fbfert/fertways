<?php

use App\Http\Controllers\Api\AuctionController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BuildingController;
use App\Http\Controllers\Api\CapitalController;
use App\Http\Controllers\Api\CargosController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\ColonyController;
use App\Http\Controllers\Api\DroneController;
use App\Http\Controllers\Api\EnduranceController;
use App\Http\Controllers\Api\EstatisticasController;
use App\Http\Controllers\Api\FederationController;
use App\Http\Controllers\Api\FeedbackController;
use App\Http\Controllers\Api\ImagesController;
use App\Http\Controllers\Api\MarketController;
use App\Http\Controllers\Api\MissoesController;
use App\Http\Controllers\Api\MinistryController;
use App\Http\Controllers\Api\NeutralZoneController;
use App\Http\Controllers\Api\PlayerController;
use App\Http\Controllers\Api\TradeController;
use App\Http\Controllers\Api\TransportController;
use App\Http\Controllers\Api\VehicleController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\PerfilDaColoniaController;
use App\Http\Controllers\Api\ResumoController;
use App\Http\Controllers\Api\WarController;
use App\Http\Controllers\Api\ZoneController;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// A landing page (pedido do usuário, 2026-07-17): números reais, sem exigir conta.
Route::get('/estatisticas', [EstatisticasController::class, 'index']);

Route::middleware('auth:sanctum')->group(function () {
    // Revoga o token que fez a chamada. Sem isto, "sair" só apaga o token do navegador.
    Route::post('/logout', [AuthController::class, 'logout']);

    // O perfil do colono (D-69). Ele podia guerrear e não podia trocar a própria senha.
    Route::get('/profile', [ProfileController::class, 'show']);
    Route::get('/profile/extrato', [ProfileController::class, 'extrato']);
    Route::patch('/profile', [ProfileController::class, 'update']);
    Route::post('/profile/password', [ProfileController::class, 'password']);

    Route::get('/colony', [ColonyController::class, 'show']);

    /*
     * "Desde sua última visita" (A2.0.3). Dois verbos de propósito: o GET monta e NÃO move o
     * marcador; o POST move, e só é chamado quando o jogador FECHA o resumo (GDD ALPHA 2 §5.1).
     * Se o GET movesse, abrir a tela sem ler já consumiria a janela.
     */
    Route::get('/resumo', [ResumoController::class, 'show']);
    Route::post('/resumo/visto', [ResumoController::class, 'visto']);

    /*
     * O perfil derivado da colônia (A2.4). **Só GET, e nunca haverá POST**: o §8.1 proíbe escolha
     * declarada de perfil — o colono se especializa pelo que pesquisou e construiu, e o jogo
     * calcula e exibe. Um endpoint de escrita seria a segunda camada que aquela regra impede.
     */
    Route::get('/perfil-da-colonia', [PerfilDaColoniaController::class, 'show']);
    Route::post('/colony', [ColonyController::class, 'store']);

    // Diretório de destinos possíveis para um despacho. Sem ele, `destination_type = colonia`
    // é inalcançável pela UI: o jogador não tem como descobrir o `id` de ninguém.
    Route::get('/colonies', [ColonyController::class, 'index']);

    // O card "quem é esse colono" (D-81): do Chat privado e do diretório de colônias.
    Route::get('/players/{user}/info', [PlayerController::class, 'info']);

    // O mapa para o seletor de fundação (D-51): geometria + slots de founder + células ocupadas.
    // Não exige colônia — é o que o colono vê para escolher onde fundar.
    Route::get('/map', [ColonyController::class, 'map']);

    // Zonas neutras (§07, §24.4; D-52). Listar e ocupar; extração é no tick, retirada é logística.
    Route::get('/zones', [NeutralZoneController::class, 'index']);
    Route::post('/zones/{zone}/occupy', [NeutralZoneController::class, 'occupy']);
    Route::post('/zones/{zone}/upgrade', [NeutralZoneController::class, 'upgrade']);
    Route::post('/zones/{zone}/withdraw', [NeutralZoneController::class, 'withdraw']);

    // A zona como LUGAR (§17.4, D-67): a ficha dela, as obras, e a entrega física do material.
    //
    // ⚠️ `/zones/minhas` vem ANTES de `/zones/{zone}`, ou o Laravel tentaria achar uma zona de id
    // "minhas". É a mesma armadilha do `/buildings/catalogo`, e ela já mordeu uma vez.
    Route::get('/zones/minhas', [ZoneController::class, 'minhas']);
    Route::get('/zones/{zone}', [ZoneController::class, 'show']);
    Route::get('/zones/{zone}/historico', [ZoneController::class, 'historico']);
    Route::post('/zones/{zone}/build', [ZoneController::class, 'construir']);
    Route::delete('/zones/{zone}/build/{slot}', [ZoneController::class, 'demolir']);
    Route::post('/zones/{zone}/material', [ZoneController::class, 'entregarMaterial']);
    Route::patch('/zones/{zone}/name', [ZoneController::class, 'renomear']);
    Route::post('/zones/{zone}/reparar', [ZoneController::class, 'reparar']);

    // A guerra (§27, §28.10; D-66). O exército, a fábrica do Quartel, o Nióbio do governo — sem o
    // qual a Sentinela é inalcançável —, os quatro ataques, e as batalhas em curso.
    Route::get('/war', [WarController::class, 'index']);
    Route::get('/war/combats', [WarController::class, 'combates']);
    Route::get('/war/ranking', [WarController::class, 'ranking']);
    Route::post('/war/units', [WarController::class, 'fabricar']);
    Route::post('/war/niobio', [WarController::class, 'niobio']);
    Route::post('/war/attack', [WarController::class, 'atacar']);

    // O DEFENSOR enfim tem o que fazer (D-70): reforçar a zona, e romper um cerco. A tela já
    // prometia o primeiro e o §28.10 manda o segundo — nenhum dos dois existia.
    Route::post('/war/reinforce', [WarController::class, 'reforcar']);
    Route::post('/war/break-siege', [WarController::class, 'romper']);

    // O Drone (D-74): fabricado na Oficina, guardado no Quartel, e o único olho que atravessa a
    // névoa do interior das zonas alheias. A missão mira uma zona; o raio revela as vizinhas.
    Route::post('/drones', [DroneController::class, 'fabricar']);
    Route::post('/drones/{vehicle}/mission', [DroneController::class, 'enviar']);

    // A arte das construções (D-68). Só o que TEM imagem vem aqui; o resto cai no hexágono.
    Route::get('/images', [ImagesController::class, 'index']);

    Route::get('/buildings', [BuildingController::class, 'specs']);
    // O catálogo do slot vazio vem antes da rota de recurso: `/buildings/catalogo` casaria com
    // `/buildings/{building}` e o Laravel tentaria achar uma construção de id "catalogo".
    Route::get('/buildings/catalogo', [BuildingController::class, 'catalogo']);
    Route::post('/buildings', [BuildingController::class, 'construir']);
    Route::delete('/buildings/{building}', [BuildingController::class, 'demolir']);
    Route::post('/buildings/{building}/upgrade', [BuildingController::class, 'upgrade']);
    Route::get('/recipes', [BuildingController::class, 'recipes']);
    Route::patch('/buildings/{building}/recipe', [BuildingController::class, 'recipe']);
    Route::get('/queue', [BuildingController::class, 'queue']);

    Route::get('/vehicles', [VehicleController::class, 'index']);
    Route::get('/vehicles/route', [VehicleController::class, 'rota']);
    Route::post('/vehicles/{vehicle}/dispatch', [VehicleController::class, 'despachar']);
    Route::patch('/vehicles/{vehicle}/nickname', [VehicleController::class, 'renomear']);

    Route::get('/market/account', [MarketController::class, 'conta']);
    // O serviço logístico público do §07 (D-76): o governo busca na doca e leva até a colônia.
    Route::post('/market/freight', [MarketController::class, 'frete']);

    // O Sistema de Mensagens (§10, D-77): polling, 4 canais vivos (federação espera federações).
    Route::get('/chat', [ChatController::class, 'canais']);
    Route::get('/chat/pendencias', [ChatController::class, 'pendencias']);

    // Bugs/Melhorias (D-95): o jogador manda, o admin responde pelo painel — a resposta chega
    // pelo rádio (D-91).
    Route::post('/feedback', [FeedbackController::class, 'store']);

    // As Missões do §06 (D-78): a mão do dia nasce no primeiro pedido; 1 rejeição diária.
    Route::get('/missions', [MissoesController::class, 'index']);
    Route::post('/missions/{assignment}/reject', [MissoesController::class, 'rejeitar']);
    Route::get('/chat/conversas', [ChatController::class, 'conversas']);
    Route::get('/chat/privada/{user}', [ChatController::class, 'privada']);
    Route::post('/chat/privada/{user}', [ChatController::class, 'falarPrivado']);
    Route::post('/chat/bloquear/{user}', [ChatController::class, 'bloquear']);
    Route::delete('/chat/bloquear/{user}', [ChatController::class, 'desbloquear']);
    Route::get('/chat/{canal}', [ChatController::class, 'ler']);
    Route::post('/chat/{canal}', [ChatController::class, 'falar']);
    Route::post('/vehicles/{vehicle}/withdraw', [MarketController::class, 'retirar']);

    // Ofertas Globais: vitrine, não livro de casamento (D-58). A oferta repousa até que alguém a
    // execute — e é a execução que move recurso e Fert$, de depósito a depósito, sem veículo.
    Route::get('/market/orders', [MarketController::class, 'livro']);
    Route::post('/market/orders', [MarketController::class, 'ordenar']);
    Route::post('/market/orders/{order}/execute', [MarketController::class, 'executar']);
    Route::delete('/market/orders/{order}', [MarketController::class, 'cancelar']);

    // Ministério dos Transportes (§16), slot 8 da Capital. Fabricar caminhão é privativo dele
    // desde o D-60 — a Central de Transportes do colono virou só a vaga.
    Route::get('/transport', [TransportController::class, 'index']);
    Route::post('/transport/buy', [TransportController::class, 'comprar']);

    // A frota envelhece (§16.4, D-60 fatia 2): manutenção na Central de Transportes do colono, e
    // sucata só por vontade do dono — nada some da frota sem ele mandar.
    Route::post('/transport/vehicles/{vehicle}/maintain', [TransportController::class, 'reparar']);
    Route::delete('/transport/vehicles/{vehicle}', [TransportController::class, 'sucatear']);

    // Mercado de usados (D-60 fatia 3), com escrow do Ministério: o comprador paga, o veículo
    // dirige-se até ele, e o vendedor só recebe na chegada.
    Route::get('/transport/listings', [TransportController::class, 'anuncios']);
    Route::post('/transport/listings', [TransportController::class, 'anunciar']);
    Route::post('/transport/listings/{listing}/buy', [TransportController::class, 'comprarUsado']);
    Route::delete('/transport/listings/{listing}', [TransportController::class, 'cancelarAnuncio']);

    // Acordo de Troca (§26.5). Sem escrow: registram promessas, não movem recursos. Quem move é
    // o despacho, que aponta o acordo pelo `trade_agreement_id`. Ver D-40 e D-41.
    Route::get('/trade/agreements', [TradeController::class, 'index']);
    Route::get('/trade/deadline', [TradeController::class, 'prazoMinimo']);
    Route::post('/trade/agreements', [TradeController::class, 'store']);
    Route::post('/trade/agreements/{agreement}/confirm', [TradeController::class, 'confirm']);
    Route::delete('/trade/agreements/{agreement}', [TradeController::class, 'destroy']);

    // O mural do D-58: ofertas sem contraparte, abertas a quem quiser. Quem aceita primeiro leva —
    // e o D-42 (prazo × distância) só pode ser cobrado aí, quando enfim existe um par de verdade.
    Route::get('/trade/board', [TradeController::class, 'mural']);
    Route::post('/trade/agreements/{agreement}/accept', [TradeController::class, 'aceitar']);

    // Leilões (D-129). Sem seção no GDD — só citado como alvo de uma punição inerte. O mecanismo
    // (lote único, lance em escrow, fechamento por prazo no tick) é desenho nosso sobre o Mercado
    // Central.
    Route::get('/auctions', [AuctionController::class, 'index']);
    Route::post('/auctions', [AuctionController::class, 'store']);
    Route::post('/auctions/{auction}/bid', [AuctionController::class, 'lance']);
    Route::delete('/auctions/{auction}', [AuctionController::class, 'destroy']);

    // Cargos Públicos (§14.2, D-130). Nomeação continua só por artisan (`fertways:cargo-civico`) —
    // estes dois são os únicos atos que o próprio jogador faz.
    Route::post('/cargos/materia', [CargosController::class, 'publicarMateria']);
    Route::post('/cargos/sinalizar', [CargosController::class, 'sinalizar']);

    // A Loja de Peças da Endurance (§05, D-135) — catálogo dinâmico, uma loja por seção do casco.
    Route::get('/endurance/efeitos', [EnduranceController::class, 'meusEfeitos']);
    Route::get('/endurance/meus-itens-vendaveis', [EnduranceController::class, 'meusItensVendaveis']);
    Route::get('/endurance/secoes/{secao}', [EnduranceController::class, 'secao']);
    Route::post('/endurance/itens/{item}/comprar', [EnduranceController::class, 'comprar']);

    // Capital — instituições do governo (§02). Só leitura: os atos do governo (intervenção de preço,
    // publicar comunicado) são artisan, não rota, porque o Governo é "operado pela equipe" (D-44).
    Route::get('/treasury', [CapitalController::class, 'treasury']); // Central de Tributos / Tesouro (2)
    Route::get('/finance', [CapitalController::class, 'finance']);   // Secretaria de Finanças (4)
    Route::get('/news', [CapitalController::class, 'news']);         // Pesquisas e Notícias (3)

    // Ministério das Reputações (§9.1-9.4, §26.6-26.8). A "equipe" do §9.2 não tem rota: ela é o
    // operador do jogo e julga por `artisan fertways:equipe` (D-44).
    Route::get('/ministry/me', [MinistryController::class, 'eu']);
    Route::get('/ministry/reports', [MinistryController::class, 'index']);
    Route::post('/ministry/reports', [MinistryController::class, 'store']);
    Route::post('/ministry/reports/{report}/decide', [MinistryController::class, 'decidir']);
    Route::post('/ministry/reports/{report}/appeal', [MinistryController::class, 'apelar']);

    // Federação (§04/§07; D-114), Fatia 1 — Capital slot 9, o Quartel de Alianças.
    Route::get('/federation', [FederationController::class, 'show']);
    Route::get('/federations', [FederationController::class, 'index']);
    Route::post('/federations', [FederationController::class, 'store']);
    Route::post('/federations/{federation}/invite', [FederationController::class, 'convidar']);
    Route::post('/federations/{federation}/apply', [FederationController::class, 'pedir']);
    Route::post('/federation/invites/{invite}/accept', [FederationController::class, 'aceitar']);
    Route::post('/federation/invites/{invite}/reject', [FederationController::class, 'recusar']);
    Route::delete('/federation/invites/{invite}', [FederationController::class, 'cancelarConvite']);
    Route::post('/federation/leave', [FederationController::class, 'sair']);
    Route::post('/federation/transfer-leadership', [FederationController::class, 'transferirLideranca']);
    Route::post('/federation/members/{colony}/kick', [FederationController::class, 'expulsar']);
    Route::patch('/federation/members/{colony}/role', [FederationController::class, 'alterarCargo']);
    Route::post('/federation/withdraw', [FederationController::class, 'sacar']);
});
