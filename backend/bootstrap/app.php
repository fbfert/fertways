<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        /*
         * Sem prefixo. Em produção o Apache monta esta aplicação sob `/central` e já remove
         * esse trecho do caminho antes de entregá-lo ao PHP, então o Laravel enxerga
         * `/login`, não `/central/login`. Um prefixo aqui viraria `/central/central/login`.
         */
        apiPrefix: '',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        /*
         * Não existe tela de login no servidor: isto é uma API. Sem devolver null aqui, o
         * middleware `Authenticate` tenta redirecionar o convidado para a rota nomeada
         * `login` — que não existe — e o erro vira 500 antes de qualquer renderizador de
         * exceção. Com null, ele lança AuthenticationException e sai um 401 limpo.
         */
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        /*
         * Esta é uma API. Sem isto, um GET a /colony sem `Accept: application/json` faz o
         * Laravel tentar redirecionar para a rota `login`, que não existe aqui, e devolver
         * 500 em vez de 401. O front sempre manda o header, mas curl, monitores de uptime e
         * o navegador na barra de endereços não mandam.
         *
         * Tudo é JSON menos `/` e `/up`, as duas únicas rotas que respondem HTML.
         */
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->expectsJson() || ! $request->is('/', 'up'),
        );
    })->create();
