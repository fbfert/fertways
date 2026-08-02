<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Limite de tentativas em quem autentica (A2.12, item "rate limits").
 *
 * ⚠️ **Não havia nenhum, e o jogo está no ar.** `/login` e `/register` aceitavam tentativas sem
 * limite: adivinhar uma senha fraca custava só tempo de CPU alheia.
 */
class LimiteDeTentativasTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // O limitador guarda estado no cache, e um teste não pode herdar o contador do anterior.
        RateLimiter::clear('teste');
        app('cache')->flush();
    }

    private function colono(): User
    {
        return User::create([
            'name' => 'c', 'nickname' => 'lim',
            'email' => 'lim@t.test', 'password' => Hash::make('segredo-forte-123'),
        ]);
    }

    /** ⚠️ A força bruta trava. É o teste que justifica a fase inteira. */
    public function test_a_senha_errada_repetida_acaba_travando(): void
    {
        $this->colono();

        // Os dez primeiros erros passam como erro comum (422): digitar errado não é crime.
        for ($n = 0; $n < 10; $n++) {
            $this->postJson('/login', ['email' => 'lim@t.test', 'password' => 'errada'])
                ->assertStatus(422);
        }

        $this->postJson('/login', ['email' => 'lim@t.test', 'password' => 'errada'])
            ->assertStatus(429);
    }

    /**
     * ⚠️ E o acerto ZERA o contador.
     *
     * O middleware conta toda requisição, inclusive as boas. Sem zerar, quem entra e sai várias vezes
     * — trocando de aba, reconectando, ou o próprio e2e rodando dez suítes seguidas — bateria no teto
     * **por usar o jogo direito**.
     */
    public function test_o_acerto_zera_o_contador(): void
    {
        $this->colono();

        for ($n = 0; $n < 9; $n++) {
            $this->postJson('/login', ['email' => 'lim@t.test', 'password' => 'errada'])
                ->assertStatus(422);
        }

        $this->postJson('/login', ['email' => 'lim@t.test', 'password' => 'segredo-forte-123'])
            ->assertOk();

        // Se o acerto não zerasse, esta seria a 11ª e levaria 429.
        for ($n = 0; $n < 5; $n++) {
            $this->postJson('/login', ['email' => 'lim@t.test', 'password' => 'errada'])
                ->assertStatus(422);
        }
    }

    /**
     * A chave é e-mail + IP: travar uma conta não pode travar a outra.
     *
     * Só por IP puniria uma casa inteira porque um vizinho errou a senha. Só por e-mail deixaria o
     * atacante distribuir tentativas entre contas.
     */
    public function test_travar_uma_conta_nao_trava_a_outra(): void
    {
        $this->colono();
        User::create([
            'name' => 'o', 'nickname' => 'outro',
            'email' => 'outro@t.test', 'password' => Hash::make('segredo-forte-123'),
        ]);

        for ($n = 0; $n < 11; $n++) {
            $this->postJson('/login', ['email' => 'lim@t.test', 'password' => 'errada']);
        }

        $this->postJson('/login', ['email' => 'outro@t.test', 'password' => 'segredo-forte-123'])
            ->assertOk();
    }
}
