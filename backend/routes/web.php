<?php

use App\Http\Controllers\Admin\AcoesController;
use App\Http\Controllers\Admin\AuthController as AdminAuth;
use App\Http\Controllers\Admin\PainelController;
use Illuminate\Routing\Route as Rota;
use Illuminate\Support\Facades\Route;

/*
 * Painel de administração da equipe (§14.4, §28.3; D-56). Blade por sessão, no guard `admin`,
 * isolado da API de colono. Em produção vive em https://fertways.tars.art.br/central/admin.
 * As rotas ficam fora do índice JSON da API (o filtro `$interna` abaixo as exclui).
 */
Route::prefix('admin')->group(function () {
    Route::get('/login', [AdminAuth::class, 'showLogin'])->name('admin.login');
    Route::post('/login', [AdminAuth::class, 'login']);

    Route::middleware('auth:admin')->group(function () {
        Route::get('/', [PainelController::class, 'dashboard'])->name('admin.dashboard');
        Route::post('/logout', [AdminAuth::class, 'logout'])->name('admin.logout');

        // Ministério
        Route::post('/reports/{report}/julgar', [AcoesController::class, 'julgar'])->name('admin.julgar');
        Route::post('/reports/{report}/apelacao', [AcoesController::class, 'apelacao'])->name('admin.apelacao');
        // Conciliadores
        Route::post('/conciliadores/nomear', [AcoesController::class, 'conciliadorNomear'])->name('admin.conciliador.nomear');
        Route::post('/conciliadores/{user}/gerir', [AcoesController::class, 'conciliadorGerir'])->name('admin.conciliador.gerir');
        // Finanças
        Route::post('/intervencoes', [AcoesController::class, 'intervencao'])->name('admin.intervencao');
        Route::post('/intervencoes/revogar', [AcoesController::class, 'intervencaoRevogar'])->name('admin.intervencao.revogar');
        // Notícias
        Route::post('/noticias', [AcoesController::class, 'noticiaPublicar'])->name('admin.noticia');
        Route::post('/noticias/{news}/remover', [AcoesController::class, 'noticiaRemover'])->name('admin.noticia.remover');
        // Ministério do Tesouro (D-57)
        Route::post('/tesouro/distribuir', [AcoesController::class, 'distribuir'])->name('admin.tesouro.distribuir');

        // O Painel do Ministério dos Transportes (§16, D-60): os quatro números que o GDD manda o
        // operador configurar e nunca publica.
        Route::post('/transporte', [AcoesController::class, 'transporte'])->name('admin.transporte');
        // Operação
        Route::post('/tick', [AcoesController::class, 'tick'])->name('admin.tick');
        Route::post('/realocar', [AcoesController::class, 'realocar'])->name('admin.realocar');
    });
});

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
        || $uri === 'admin'
        || str_starts_with($uri, 'admin/')
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
