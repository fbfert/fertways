<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Admin\Auditoria;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Login/logout do painel de administração, no guard `admin` (sessão). Isolado da auth de colono.
 *
 * ⚠️ **Quem AUDITA o login não é este controller** — é o `Listeners\AuditarLoginDoAdmin`, pendurado
 * nos eventos do `Auth` (D-71). Era aqui, e quem entrava pelo cookie do "lembrar de mim" **não
 * passava por aqui** e não deixava rastro. O único ponto por onde toda entrada passa é o guard.
 */
class AuthController extends Controller
{
    /**
     * O freio de força bruta (D-71). Antes dele a porta do painel aceitava **tentativas ilimitadas**
     * — e é a mesma porta que realoca colônia e distribui o Tesouro.
     *
     * **Duas chaves, e as duas são necessárias:**
     *
     *   e-mail + IP   5/min. É o ataque que interessa: alguém que sabe o e-mail e chuta a senha.
     *   IP            20/min. Senão bastaria variar o e-mail a cada tentativa para nunca esbarrar
     *                 no primeiro limite — cada e-mail teria um balde só seu.
     *
     * ⚠️ **A chave inclui o IP de propósito.** Se fosse só o e-mail, qualquer um do outro lado do
     * mundo poderia **trancar o dono para fora** martelando o e-mail dele — a defesa viraria a arma.
     */
    private const TENTATIVAS_POR_CONTA = 5;

    private const TENTATIVAS_POR_IP = 20;

    public function showLogin(): View|RedirectResponse
    {
        if (Auth::guard('admin')->check()) {
            return redirect()->route('admin.dashboard');
        }

        return view('admin.login');
    }

    public function login(Request $request, Auditoria $auditoria): RedirectResponse
    {
        $dados = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $this->exigirNaoEstarBloqueado($request, $dados['email'], $auditoria);

        /*
         * O `attempt` já filtra o desativado: sem isto, desativar um admin não tiraria o acesso dele,
         * e a única coisa que a coluna faria seria mudar uma cor na tabela.
         */
        $credenciais = $dados + ['desativado_em' => null];

        if (! Auth::guard('admin')->attempt($credenciais, $request->boolean('remember'))) {
            // Cada chave tem o seu balde, e os dois enchem juntos. O evento `Failed` do guard já
            // registrou a tentativa na auditoria — ver o listener.
            foreach ($this->chaves($request, $dados['email']) as $chave) {
                RateLimiter::hit($chave);
            }

            throw ValidationException::withMessages(['email' => 'Credenciais inválidas.']);
        }

        // Entrou: os baldes se esvaziam. Senão, quem errou a senha quatro vezes e acertou na quinta
        // continuaria a um passo do bloqueio pelo resto do minuto.
        foreach ($this->chaves($request, $dados['email']) as $chave) {
            RateLimiter::clear($chave);
        }

        $request->session()->regenerate();

        return redirect()->intended(route('admin.dashboard'));
    }

    public function logout(Request $request, Auditoria $auditoria): RedirectResponse
    {
        $auditoria->registrar('logout', 'Saiu do painel.');

        Auth::guard('admin')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }

    /**
     * Bloqueia, avisa **quantos segundos faltam** e deixa a linha na auditoria.
     *
     * O bloqueio é registrado porque é o sinal mais forte que o painel tem: cinco recusas seguidas
     * podem ser um dedo gordo, mas um bloqueio quer dizer que alguém insistiu.
     */
    private function exigirNaoEstarBloqueado(Request $request, string $email, Auditoria $auditoria): void
    {
        [$porConta, $porIp] = $this->chaves($request, $email);

        $estourou = RateLimiter::tooManyAttempts($porConta, self::TENTATIVAS_POR_CONTA)
            || RateLimiter::tooManyAttempts($porIp, self::TENTATIVAS_POR_IP);

        if (! $estourou) {
            return;
        }

        $auditoria->login($email, 'login.bloqueado');

        $segundos = max(
            RateLimiter::availableIn($porConta),
            RateLimiter::availableIn($porIp),
        );

        throw ValidationException::withMessages([
            'email' => "Tentativas demais. Tente de novo em {$segundos} segundos.",
        ]);
    }

    /** @return array{string, string} a chave da conta e a do IP, nesta ordem */
    private function chaves(Request $request, string $email): array
    {
        // Minúsculas e sem espaços: senão `Dono@x.com ` e `dono@x.com` seriam dois baldes, e cinco
        // tentativas viram dez.
        $conta = Str::lower(trim($email));

        return [
            "admin-login:{$conta}|{$request->ip()}",
            "admin-login-ip:{$request->ip()}",
        ];
    }
}
