<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BuildingController;
use App\Http\Controllers\Api\ColonyController;
use App\Http\Controllers\Api\MarketController;
use App\Http\Controllers\Api\VehicleController;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    // Revoga o token que fez a chamada. Sem isto, "sair" só apaga o token do navegador.
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::get('/colony', [ColonyController::class, 'show']);
    Route::post('/colony', [ColonyController::class, 'store']);

    Route::get('/buildings', [BuildingController::class, 'specs']);
    Route::post('/buildings/{building}/upgrade', [BuildingController::class, 'upgrade']);
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
});
