<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Logout de verdade: o token do Sanctum não expira por padrão, então apagá-lo do `localStorage`
 * do navegador deixa uma credencial válida em circulação para sempre. Sair tem de revogá-la
 * no servidor.
 */
class LogoutTest extends TestCase
{
    use RefreshDatabase;

    private function token(string $email = 'sai@t.test'): string
    {
        User::factory()->create([
            'email' => $email,
            'nickname' => explode('@', $email)[0],
            'password' => bcrypt('segredo-forte-123'),
        ]);

        return $this->postJson('/login', ['email' => $email, 'password' => 'segredo-forte-123'])
            ->assertOk()
            ->json('token');
    }

    private function comToken(string $token): array
    {
        return ['Authorization' => "Bearer {$token}"];
    }

    /**
     * Em HTTP real cada requisição é um processo novo e o guard resolve o token do zero. No teste,
     * o container é o mesmo entre requisições e o `Auth` guarda o usuário já resolvido — um token
     * revogado continuaria "autenticando" e o teste passaria a mentir. Isto o força a reler.
     *
     * Sem esta chamada, `GET /colony` depois do logout devolvia 404 (rota encontrada, usuário
     * ainda em memória) em vez de 401. Verificado contra o servidor de produção: lá dá 401.
     */
    private function novaRequisicao(): void
    {
        $this->app['auth']->forgetGuards();
    }

    #[Test]
    public function o_logout_revoga_o_token_que_fez_a_chamada(): void
    {
        $token = $this->token();

        // Antes: o token abre uma rota protegida.
        $this->getJson('/colony', $this->comToken($token))->assertStatus(404);
        $this->assertSame(1, DB::table('personal_access_tokens')->count());

        $this->postJson('/logout', [], $this->comToken($token))->assertOk();

        // Depois: a linha sumiu do banco, e o mesmo token já não autentica.
        $this->assertSame(0, DB::table('personal_access_tokens')->count());
        $this->novaRequisicao();
        $this->getJson('/colony', $this->comToken($token))->assertUnauthorized();
    }

    #[Test]
    public function o_logout_exige_autenticacao(): void
    {
        $this->postJson('/logout')->assertUnauthorized();
    }

    #[Test]
    public function sair_num_dispositivo_nao_derruba_o_outro(): void
    {
        $primeiro = $this->token();

        // O mesmo colono entrando de novo — outro dispositivo, outro token.
        $segundo = $this->postJson('/login', ['email' => 'sai@t.test', 'password' => 'segredo-forte-123'])
            ->json('token');

        $this->assertSame(2, DB::table('personal_access_tokens')->count());

        $this->postJson('/logout', [], $this->comToken($primeiro))->assertOk();

        // Revoga só o token corrente: a outra sessão continua de pé.
        $this->novaRequisicao();
        $this->getJson('/colony', $this->comToken($primeiro))->assertUnauthorized();
        $this->novaRequisicao();
        $this->getJson('/colony', $this->comToken($segundo))->assertStatus(404);
        $this->assertSame(1, DB::table('personal_access_tokens')->count());
    }

    #[Test]
    public function o_indice_da_api_anuncia_o_logout(): void
    {
        $this->assertContains('POST /logout (autenticado)', $this->getJson('/')->json('endpoints'));
    }
}
