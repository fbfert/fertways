<?php

use Illuminate\Support\Facades\Route;

/*
 * Raiz da API. Em produção este ponto é https://fertways.tars.art.br/central/.
 *
 * O jogo em si é servido como build estático na raiz do domínio; aqui só vive a API. A rota
 * existe para que a base não devolva 404 a quem a abre no navegador, e para documentar onde
 * ficam os endpoints. Substitui a página de boas-vindas do Laravel, que não dizia nada e
 * revelava a versão do framework.
 */
Route::get('/', fn () => response()->json([
    'service' => 'FERTWAYS API',
    'endpoints' => [
        'POST /register',
        'POST /login',
        'GET|POST /colony',
        'GET /buildings',
        'POST /buildings/{building}/upgrade',
        'PATCH /buildings/{building}/recipe',
        'GET /queue',
        'GET /up (health check)',
    ],
]));
