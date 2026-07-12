<?php

namespace App\Providers;

use Illuminate\Pagination\Paginator;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        /*
         * O painel de admin é Blade com CSS próprio, **sem Tailwind** — e o template de paginação que
         * o Laravel usa por omissão é escrito para Tailwind. O `links()` da lista de jogadores existia
         * desde o D-61 e saía **sem estilo nenhum**: os controles estavam no HTML e ninguém os via, de
         * modo que a lista parecia terminar na última linha da primeira página.
         *
         * Uma linha, e a paginação passa a existir de fato — em Jogadores, em Notícias e na frota.
         */
        Paginator::defaultView('admin.paginacao');
        Paginator::defaultSimpleView('admin.paginacao');
    }
}
