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

    // O nome não é enfeite: é por ele que o `AuditarLoginDoAdmin` distingue quem **digitou a senha**
    // de quem **voltou pelo cookie** do "lembrar de mim" (D-71). O evento do `Auth` é o mesmo nos
    // dois casos; só o pedido sabe a diferença.
    Route::post('/login', [AdminAuth::class, 'login'])->name('admin.login.enviar');

    Route::middleware('auth:admin')->group(function () {
        Route::post('/logout', [AdminAuth::class, 'logout'])->name('admin.logout');

        // ── As seções (D-61). Eram uma página só; o CRUD e a auditoria não caberiam nela. ──
        Route::get('/', [PainelController::class, 'dashboard'])->name('admin.dashboard');
        Route::get('/jogadores', [PainelController::class, 'jogadores'])->name('admin.jogadores');
        Route::get('/jogadores/{user}', [PainelController::class, 'jogador'])->name('admin.jogador');
        Route::get('/ministerio', [PainelController::class, 'ministerio'])->name('admin.ministerio');
        Route::get('/economia', [PainelController::class, 'economia'])->name('admin.economia');
        // As notícias saíram de Economia e viraram aba própria (2026-07-13): elas não são economia.
        Route::get('/noticias', [PainelController::class, 'noticias'])->name('admin.noticias');

        // Gestão de imagens (D-68). Os ARQUIVOS moram fora da árvore de deploy (/home/fertways/media),
        // servidos por symlink — o deploy.sh aborta com arquivo não rastreado na árvore.
        Route::get('/imagens', [PainelController::class, 'imagens'])->name('admin.imagens');
        Route::post('/imagens', [AcoesController::class, 'imagemEnviar'])->name('admin.imagem.enviar');
        Route::post('/imagens/vincular', [AcoesController::class, 'imagemVincular'])->name('admin.imagem.vincular');
        Route::post('/imagens/{media}/apagar', [AcoesController::class, 'imagemApagar'])->name('admin.imagem.apagar');
        // A guerra (D-70): dez parâmetros que o GDD manda o operador declarar (§27.3 "valores
        // configuráveis", §28.10 chances sem conta publicada) e que até aqui só se mudavam por SQL.
        Route::get('/guerra', [PainelController::class, 'guerra'])->name('admin.guerra');
        Route::post('/guerra', [AcoesController::class, 'guerra'])->name('admin.guerra.parametros');
        Route::get('/transportes', [PainelController::class, 'transportes'])->name('admin.transportes');
        Route::get('/auditoria', [PainelController::class, 'auditoria'])->name('admin.auditoria');
        Route::get('/operacao', [PainelController::class, 'operacao'])->name('admin.operacao');

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
        // Editar reescreve; OCULTAR é reversível e administrativo; INATIVAR é fim de vida. Os três
        // vão ao `audit_log`, como tudo o que o painel faz.
        Route::post('/noticias/{news}/editar', [AcoesController::class, 'noticiaEditar'])->name('admin.noticia.editar');
        Route::post('/noticias/{news}/ocultar', [AcoesController::class, 'noticiaOcultar'])->name('admin.noticia.ocultar');
        Route::post('/noticias/{news}/inativar', [AcoesController::class, 'noticiaInativar'])->name('admin.noticia.inativar');
        // Ministério do Tesouro (D-57)
        Route::post('/tesouro/distribuir', [AcoesController::class, 'distribuir'])->name('admin.tesouro.distribuir');

        // O Painel do Ministério dos Transportes (§16, D-60): os quatro números que o GDD manda o
        // operador configurar e nunca publica.
        Route::post('/transporte', [AcoesController::class, 'transporte'])->name('admin.transporte');

        // ── Jogadores (D-61). O operador vê, suspende e corrige; realocar é só do dono. ──
        Route::post('/jogadores/{user}/suspender', [AcoesController::class, 'suspender'])->name('admin.jogador.suspender');
        Route::post('/jogadores/{user}/reintegrar', [AcoesController::class, 'reintegrar'])->name('admin.jogador.reintegrar');
        Route::post('/jogadores/{user}/corrigir', [AcoesController::class, 'corrigir'])->name('admin.jogador.corrigir');
        Route::post('/jogadores/{user}/senha', [AcoesController::class, 'redefinirSenha'])->name('admin.jogador.senha');
        Route::post('/jogadores/{user}/dados', [AcoesController::class, 'editarJogador'])->name('admin.jogador.dados');

        // Operação
        Route::post('/tick', [AcoesController::class, 'tick'])->name('admin.tick');

        /*
         * ── Só o dono (D-61) ──
         *
         * A linha é **quem altera o estado do jogo de forma difícil de desfazer**: gerir admins (que
         * pode trancar o painel) e realocar uma colônia (que muda a distância, o eixo de toda a
         * logística, e afeta o mundo de outros jogadores).
         *
         * A guarda é no servidor. O menu esconde a aba, mas esconder o botão sem barrar a rota faria
         * da divisão de papéis uma sugestão.
         */
        Route::middleware('dono')->group(function () {
            Route::get('/admins', [PainelController::class, 'admins'])->name('admin.admins');
            Route::post('/admins', [AcoesController::class, 'adminCriar'])->name('admin.admin.criar');
            Route::post('/admins/{admin}', [AcoesController::class, 'adminEditar'])->name('admin.admin.editar');
            Route::post('/admins/{admin}/desativar', [AcoesController::class, 'adminDesativar'])->name('admin.admin.desativar');
            Route::post('/admins/{admin}/reativar', [AcoesController::class, 'adminReativar'])->name('admin.admin.reativar');

            Route::post('/jogadores/{user}/realocar', [AcoesController::class, 'realocarColonia'])->name('admin.jogador.realocar');

            /*
             * ⚠️ **Não há realocação em massa pelo painel, e isso é decisão do usuário (2026-07-13).**
             *
             * Existiu um botão "Realocar founders" que movia **todas as colônias do jogo de uma vez**.
             * Ele foi retirado: realocar é ato pontual, sobre um jogador escolhido, e um botão que
             * remaneja o planeta inteiro é perigoso demais para viver ao lado do "Disparar tick".
             *
             * O comando `artisan fertways:realocar-founders` continua existindo — ele foi a ferramenta
             * de UMA migração histórica (D-51), roda com `--force` explícito e simula por omissão.
             * Fora do painel, e é aí que ele deve ficar.
             */
            Route::post('/realocar-manual', [AcoesController::class, 'realocarManual'])->name('admin.realocar.manual');
        });
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
