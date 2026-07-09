<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ColonyController;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/colony', [ColonyController::class, 'show']);
    Route::post('/colony', [ColonyController::class, 'store']);
});
