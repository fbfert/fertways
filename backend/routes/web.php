<?php

use Illuminate\Routing\Route as Rota;
use Illuminate\Support\Facades\Route;

/*
 * Raiz da API. Em produção este ponto é https://fertways.tars.art.br/central/.
 *
 * O jogo em si é servido como build estático na raiz do domínio; aqui só vive a API. A rota
 * existe para que a base não devolva 404 a quem a abre no navegador, e para documentar onde
 * ficam os endpoints. Substitui a página de boas-vindas do Laravel, que não dizia nada e
 * revelava a versão do framework.
 *
 * A lista é **derivada do roteador**, não digitada. A versão anterior era uma lista à mão, e
 * envelheceu no primeiro endpoint novo: quem abrisse `/central/` via um backend sem `/vehicles`
 * nem `/market/*` e concluía que a API estava velha ou quebrada.
 *
 * Enumerar em runtime é seguro aqui porque `route:cache` é proibido neste deploy (ver D-26).
 */
Route::get('/', function () {
    // `storage/{path}` é a rota do symlink de arquivos do Laravel, não da API do jogo.
    $interna = fn (string $uri) => $uri === '/'
        || $uri === 'up'
        || str_starts_with($uri, '_')
        || str_starts_with($uri, 'sanctum/')
        || str_starts_with($uri, 'storage/');

    $endpoints = collect(app('router')->getRoutes()->getRoutes())
        ->reject(fn (Rota $r) => $interna($r->uri()))
        ->map(function (Rota $r) {
            $metodos = implode('|', array_diff($r->methods(), ['HEAD', 'OPTIONS']));
            $protegida = in_array('auth:sanctum', $r->gatherMiddleware(), true);

            return "{$metodos} /{$r->uri()}".($protegida ? ' (autenticado)' : '');
        })
        ->unique()
        ->sort()
        ->values();

    return response()->json([
        'service' => 'FERTWAYS API',
        'endpoints' => $endpoints,
        'health' => 'GET /up',
    ]);
});
