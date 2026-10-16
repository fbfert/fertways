<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O teto global de requisições da API (A2.12; D-277).
 *
 * O D-186 deixou de propósito sem teto: o cliente consulta em laço, e um número chutado derrubaria
 * jogador legítimo no meio da partida. O D-277 mediu o ritmo de verdade, tela a tela: o pior caso
 * parado é o Mercado, 164 requisições num minuto; a navegação mais rápida que um humano consegue,
 * 167. O teto é 600 por minuto por jogador — três abas no Mercado ao mesmo tempo ainda cabem.
 */
class TetoGlobalDeRequisicoesTest extends TestCase
{
    use RefreshDatabase;

    public function test_o_teto_de_fabrica_e_o_medido(): void
    {
        $this->assertSame(600, config('fertways.teto_requisicoes_por_minuto'));
    }

    /**
     * Na produção o cache padrão é o banco (`CACHE_STORE=database`), e o limitador ali custava DEZ
     * consultas por requisição — mais que o `/queue` inteiro. Ele conta em arquivo (`cache.limiter`);
     * o resto do cache continua onde está.
     */
    public function test_contar_o_teto_nao_custa_consultas_ao_banco(): void
    {
        // A produção: cache padrão no banco, limitador no padrão do `config/cache.php`. (Nos testes
        // o `phpunit.xml` põe o limitador em `array`, para a contagem não vazar de um teste a outro.)
        $this->assertStringContainsString(
            "'limiter' => env('CACHE_LIMITER', 'file')",
            file_get_contents(config_path('cache.php')),
        );
        config(['cache.default' => 'database', 'cache.limiter' => 'file']);
        \Illuminate\Support\Facades\Cache::store('file')->flush();
        $this->app->forgetInstance(\Illuminate\Cache\RateLimiter::class);
        \Illuminate\Support\Facades\RateLimiter::clearResolvedInstances();
        $this->app->register(\App\Providers\AppServiceProvider::class, force: true);
        $tok = User::factory()->create()->createToken('t')->plainTextToken;

        \Illuminate\Support\Facades\DB::enableQueryLog();
        $this->withToken($tok)->getJson('/profile')->assertOk()->assertHeader('X-RateLimit-Limit', '600');
        $doCache = collect(\Illuminate\Support\Facades\DB::getQueryLog())
            ->filter(fn ($q) => str_contains($q['query'], 'cache'))->count();

        $this->assertSame(0, $doCache, "o teto fez {$doCache} consultas à tabela de cache");
    }

    public function test_passado_o_teto_a_api_responde_429_em_portugues(): void
    {
        config(['fertways.teto_requisicoes_por_minuto' => 3]);
        $u = User::factory()->create();
        $tok = $u->createToken('t')->plainTextToken;

        for ($i = 0; $i < 3; $i++) {
            $this->withToken($tok)->getJson('/profile')->assertOk();
        }

        $r = $this->withToken($tok)->getJson('/profile')->assertStatus(429);
        $r->assertJsonPath('code', 'muitas_requisicoes');
        $this->assertStringContainsString('requisições', $r->json('message'));
        $this->assertNotNull($r->headers->get('Retry-After'));
    }

    /** A chave é o JOGADOR: quem divide a internet (uma casa, uma escola) não paga pelo vizinho. */
    public function test_o_teto_e_por_jogador_e_nao_por_ip(): void
    {
        config(['fertways.teto_requisicoes_por_minuto' => 2]);
        $a = User::factory()->create()->createToken('a')->plainTextToken;
        $b = User::factory()->create()->createToken('b')->plainTextToken;

        $this->withToken($a)->getJson('/profile')->assertOk();
        $this->withToken($a)->getJson('/profile')->assertOk();
        $this->withToken($a)->getJson('/profile')->assertStatus(429);

        // Num servidor, cada pedido nasce sem usuário; no teste a aplicação é a mesma, e o guard
        // do Sanctum lembraria o jogador A.
        $this->app['auth']->forgetGuards();
        $this->withToken($b)->getJson('/profile')->assertOk();
    }

    /**
     * Sem login, a chave é o IP — na rota pública da landing. (Numa rota fechada, o `auth:sanctum`
     * tem prioridade no Laravel e recusa o pedido sem token antes de o teto contar: o 401 sai antes.)
     */
    public function test_sem_login_conta_por_ip(): void
    {
        config(['fertways.teto_requisicoes_por_minuto' => 2]);

        $this->getJson('/estatisticas')->assertOk();
        $this->getJson('/estatisticas')->assertOk();
        $this->getJson('/estatisticas')->assertStatus(429);
    }
}
