<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BuildingController;
use App\Http\Controllers\Api\ColonyController;
use App\Http\Controllers\Api\MarketController;
use App\Http\Controllers\Api\MinistryController;
use App\Http\Controllers\Api\NeutralZoneController;
use App\Http\Controllers\Api\TradeController;
use App\Http\Controllers\Api\VehicleController;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    // Revoga o token que fez a chamada. Sem isto, "sair" só apaga o token do navegador.
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::get('/colony', [ColonyController::class, 'show']);
    Route::post('/colony', [ColonyController::class, 'store']);

    // Diretório de destinos possíveis para um despacho. Sem ele, `destination_type = colonia`
    // é inalcançável pela UI: o jogador não tem como descobrir o `id` de ninguém.
    Route::get('/colonies', [ColonyController::class, 'index']);

    // O mapa para o seletor de fundação (D-51): geometria + slots de founder + células ocupadas.
    // Não exige colônia — é o que o colono vê para escolher onde fundar.
    Route::get('/map', [ColonyController::class, 'map']);

    // Zonas neutras (§07, §24.4; D-52). Listar e ocupar; extração é no tick, retirada é logística.
    Route::get('/zones', [NeutralZoneController::class, 'index']);
    Route::post('/zones/{zone}/occupy', [NeutralZoneController::class, 'occupy']);
    Route::post('/zones/{zone}/withdraw', [NeutralZoneController::class, 'withdraw']);

    Route::get('/buildings', [BuildingController::class, 'specs']);
    Route::post('/buildings/{building}/upgrade', [BuildingController::class, 'upgrade']);
    Route::get('/recipes', [BuildingController::class, 'recipes']);
    Route::patch('/buildings/{building}/recipe', [BuildingController::class, 'recipe']);
    Route::get('/queue', [BuildingController::class, 'queue']);

    Route::get('/vehicles', [VehicleController::class, 'index']);
    Route::get('/vehicles/route', [VehicleController::class, 'rota']);
    Route::post('/vehicles/{vehicle}/dispatch', [VehicleController::class, 'despachar']);

    Route::get('/market/account', [MarketController::class, 'conta']);
    Route::post('/vehicles/{vehicle}/withdraw', [MarketController::class, 'retirar']);

    Route::get('/market/orders', [MarketController::class, 'livro']);
    Route::post('/market/orders', [MarketController::class, 'ordenar']);
    Route::delete('/market/orders/{order}', [MarketController::class, 'cancelar']);

    // Acordo de Troca (§26.5). Sem escrow: registram promessas, não movem recursos. Quem move é
    // o despacho, que aponta o acordo pelo `trade_agreement_id`. Ver D-40 e D-41.
    Route::get('/trade/agreements', [TradeController::class, 'index']);
    Route::get('/trade/deadline', [TradeController::class, 'prazoMinimo']);
    Route::post('/trade/agreements', [TradeController::class, 'store']);
    Route::post('/trade/agreements/{agreement}/confirm', [TradeController::class, 'confirm']);
    Route::delete('/trade/agreements/{agreement}', [TradeController::class, 'destroy']);

    // Ministério das Reputações (§9.1-9.4, §26.6-26.8). A "equipe" do §9.2 não tem rota: ela é o
    // operador do jogo e julga por `artisan fertways:equipe` (D-44).
    Route::get('/ministry/me', [MinistryController::class, 'eu']);
    Route::get('/ministry/reports', [MinistryController::class, 'index']);
    Route::post('/ministry/reports', [MinistryController::class, 'store']);
    Route::post('/ministry/reports/{report}/decide', [MinistryController::class, 'decidir']);
    Route::post('/ministry/reports/{report}/appeal', [MinistryController::class, 'apelar']);
});
