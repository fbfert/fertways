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
         * Isto é uma API por token — menos o painel da equipe em `/admin`, que é Blade por sessão.
         *
         * Para a API, devolver null: sem isso, o `Authenticate` tenta redirecionar o convidado à
         * rota nomeada `login` (inexistente) e o erro vira 500 antes de qualquer renderizador de
         * exceção; com null, sai um 401 limpo. Para o painel, o convidado é levado ao login do admin.
         */
        $middleware->redirectGuestsTo(
            fn (Request $request) => $request->is('admin', 'admin/*') ? route('admin.login') : null,
        );

        // Os dois papéis do painel (D-61). A checagem é no servidor: esconder o botão sem barrar a
        // rota faria da divisão de papéis uma sugestão.
        $middleware->alias(['dono' => \App\Http\Middleware\ExigirDono::class]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        /*
         * Violação de regra de jogo NÃO vai para o log. Isto é o jogo funcionando: "falta energia",
         * "não cabe no depósito", "a zona não é sua" são respostas da regra a um pedido inválido,
         * não defeitos do servidor — e viram 422, com código estável, para o front. O Laravel trata
         * a `ValidationException` do mesmo jeito e pela mesma razão.
         *
         * Gravá-las em nível ERROR mentia duas vezes. Sobre a gravidade: um 422 esperado ficava com
         * a mesma cara de um 500. E sobre o volume: em produção rendia ~79 linhas por dia de um
         * único ator repetindo o mesmo despacho impossível a cada 20 minutos, com o `laravel.log`
         * já em 90 MB — ruído que esconde o erro de verdade no meio dele (medido em 2026-08-05).
         *
         * ⚠️ Isto aqui, e não um `report()` na classe. `report()` devolvendo `false` significa
         * "siga com o tratamento padrão", ou seja, **loga assim mesmo** — o oposto do que o nome
         * sugere. Escrito daquele jeito primeiro, e foi o teste que desmentiu.
         *
         * Quem precisa contar tentativa fracassada é a telemetria (D-163), que deriva do ledger e
         * sabe de quem é a tentativa. Log de aplicação não é lugar de métrica de gameplay.
         */
        $exceptions->dontReport(\App\Exceptions\DomainRuleException::class);

        /*
         * Esta é uma API. Sem isto, um GET a /colony sem `Accept: application/json` faz o
         * Laravel tentar redirecionar para a rota `login`, que não existe aqui, e devolver
         * 500 em vez de 401. O front sempre manda o header, mas curl, monitores de uptime e
         * o navegador na barra de endereços não mandam.
         *
         * Tudo é JSON menos `/`, `/up` e o painel `/admin` (Blade), que respondem HTML.
         */
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->expectsJson() || ! $request->is('/', 'up', 'admin', 'admin/*'),
        );
    })->create();
