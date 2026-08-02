<?php

namespace App\Providers;

use App\Domain\Telemetria\RegistrarEvento;
use App\Listeners\AuditarLoginDoAdmin;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
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
        $this->app->singleton(RegistrarEvento::class);

        //
    }

    /**
     * Bootstrap any application services.
     */
    /**
     * Limites de tentativa em quem autentica (A2.12, item "rate limits").
     *
     * ## ⚠️ Não havia nenhum, e o jogo está no ar
     *
     * `/login` e `/register` aceitavam tentativas sem limite. Num jogo persistente com contas reais,
     * isso é adivinhação de senha à vontade — e o custo de descobrir uma senha fraca era só tempo de
     * CPU alheia.
     *
     * ## A chave é e-mail + IP, e não só IP
     *
     * Só por IP puniria uma casa inteira — ou uma escola — porque um vizinho errou a senha três
     * vezes. Só por e-mail deixaria um atacante distribuir tentativas entre contas. As duas juntas
     * travam o ataque a uma conta específica sem derrubar quem divide a saída de internet.
     *
     * ## ⚠️ O resto da API fica DE FORA, e é decisão consciente
     *
     * O cliente do jogo consulta o servidor em laço — mapa, chat, fila, tick. Um teto global chutado
     * sem medir o ritmo real do cliente derrubaria jogador legítimo no meio da partida, e trocar um
     * risco teórico por uma quebra certa é mau negócio. Fica anotado como trabalho com medição antes.
     */
    private function limitesDeTentativa(): void
    {
        RateLimiter::for('login', fn (Request $r) => [
            // Dez por minuto na conta específica: erro honesto de digitação passa; força bruta, não.
            Limit::perMinute(10)->by(strtolower((string) $r->input('email')).'|'.$r->ip()),
            // E um teto por IP, mais frouxo, para o ataque não se espalhar por muitas contas.
            Limit::perMinute(30)->by($r->ip()),
        ]);

        // Cadastro é raro por natureza: cinco por hora por IP não incomoda ninguém de verdade.
        RateLimiter::for('registro', fn (Request $r) => Limit::perHour(5)->by($r->ip()));
    }

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

        $this->limitesDeTentativa();

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
