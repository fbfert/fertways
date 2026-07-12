<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Admin\Auditoria;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Login/logout do painel de administração, no guard `admin` (sessão). Isolado da auth de colono.
 */
class AuthController extends Controller
{
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

        /*
         * O `attempt` já filtra o desativado: sem isto, desativar um admin não tiraria o acesso dele,
         * e a única coisa que a coluna faria seria mudar uma cor na tabela.
         */
        $credenciais = $dados + ['desativado_em' => null];

        if (! Auth::guard('admin')->attempt($credenciais, $request->boolean('remember'))) {
            // D-61: a tentativa que FALHA é a mais importante de registrar — é o único sinal que o
            // painel dá de que alguém está tentando entrar.
            $auditoria->login($dados['email'], sucesso: false);

            throw ValidationException::withMessages(['email' => 'Credenciais inválidas.']);
        }

        $request->session()->regenerate();
        $auditoria->login($dados['email'], sucesso: true);

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
}
