<?php

namespace App\Providers;

use App\Listeners\AuditarLoginDoAdmin;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        /*
         * Singleton porque ele carrega um BUFFER de eventos adiados (ver `RegistrarEvento`): dois
         * objetos diferentes teriam dois buffers, e o que fosse descarregado não seria o que foi
         * enfileirado. Instância única por aplicação — e o contêiner morre a cada teste, o que faz
         * o buffer morrer junto.
         */
        $this->app->singleton(\App\Domain\Telemetria\RegistrarEvento::class);

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

        /*
         * A auditoria da porta do painel (D-71).
         *
         * ⚠️ **Registrado À MÃO, e não pela descoberta automática de `app/Listeners`.** O Laravel
         * acharia estas duas sozinho, mas um ouvinte que deixa de se registrar **não dá erro: ele
         * simplesmente não grava nada** — e o buraco que estamos fechando aqui é exatamente esse, um
         * log que ficou meses vazio parecendo dizer "ninguém entrou". A auditoria não pode depender
         * de mágica silenciosa.
         */
        Event::listen(Login::class, [AuditarLoginDoAdmin::class, 'entrou']);
        Event::listen(Failed::class, [AuditarLoginDoAdmin::class, 'falhou']);
    }
}
