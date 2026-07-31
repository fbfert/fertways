<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Domain\Admin\Suspender;
use App\Domain\Telemetria\RegistrarEvento;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Laravel\Sanctum\PersonalAccessToken;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            // "Nickname é único no servidor e obrigatório" (GDD, Identidade do colono).
            'nickname' => ['required', 'string', 'min:3', 'max:32', 'alpha_dash', 'unique:users,nickname',
                // §03: o nickname "passa pelo mesmo filtro automático de termos do chat" — desde o
                // D-77 esse filtro existe de verdade, e a promessa vale nos dois sentidos.
                function ($attr, $value, $fail) {
                    if (\App\Domain\Chat\Filtro::barra($value)) {
                        $fail('Este nickname contém um termo vedado (§03).');
                    }
                }],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', Password::defaults()],
        ]);

        $user = User::create([
            'name' => $dados['name'],
            'nickname' => $dados['nickname'],
            'email' => $dados['email'],
            'password' => Hash::make($dados['password']),
        ]);

        return response()->json([
            'token' => $user->createToken('fertways')->plainTextToken,
            'user' => ['id' => $user->id, 'nickname' => $user->nickname],
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $dados['email'])->first();

        if (! $user || ! Hash::check($dados['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => 'Credenciais inválidas.']);
        }

        /*
         * Suspensão (D-61): a porta está trancada. Vem **depois** da checagem da senha, de propósito
         * — quem erra a senha não fica sabendo que a conta existe e está suspensa.
         *
         * A mensagem diz o motivo e o prazo. Um banimento que não explica por quê e até quando não é
         * moderação, é castigo mudo.
         */
        if (Suspender::estaSuspenso($user)) {
            $ate = $user->suspenso_ate
                ? 'até '.$user->suspenso_ate->format('d/m/Y H:i')
                : 'por tempo indeterminado';

            throw ValidationException::withMessages([
                'email' => "Conta suspensa {$ate}. Motivo: {$user->suspenso_motivo}",
            ]);
        }

        /*
         * Telemetria (A2.0.1). O login é o único evento que o ledger nunca verá — ele registra fato
         * econômico, e entrar no jogo não é um. Sem isto não há DAU, não há duração de sessão e não
         * há intervalo entre sessões: as três derivam deste par login/logout.
         *
         * Depois da suspensão, de propósito: tentativa barrada não é sessão, e contá-la inflaria o
         * DAU com gente que não entrou.
         */
        app(RegistrarEvento::class)->handle('login', $user);

        return response()->json([
            'token' => $user->createToken('fertways')->plainTextToken,
            'user' => ['id' => $user->id, 'nickname' => $user->nickname],
        ]);
    }

    /**
     * Encerra a sessão **no servidor**, revogando o token que fez a chamada.
     *
     * Apagar o token só do `localStorage` não é logout: quem tivesse copiado o valor continuaria
     * entrando com ele para sempre, porque token do Sanctum não expira por padrão. O GDD exige
     * que "todas as ações econômicas relevantes exijam sessão segura e proteção contra
     * repetição" (seção 15, Arquitetura) — uma sessão que não se pode encerrar não é segura.
     *
     * Revoga só o token corrente: quem estiver logado noutro dispositivo continua logado.
     */
    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();

        // Com autenticação por sessão (e não por token) o Sanctum devolve um `TransientToken`,
        // que não é uma linha no banco e não tem o que revogar.
        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        /*
         * O par do `login`. A duração da sessão é a diferença entre os dois — e por isso ela vai
         * sair torta para quem simplesmente fecha a aba, que é a maioria. Isso não é defeito deste
         * registro: é o limite de medir sessão sem heartbeat, e o painel (A2.0.2) vai precisar
         * dizer isso em vez de apresentar uma mediana que finge não ter esse viés.
         */
        app(RegistrarEvento::class)->handle('logout', $request->user());

        return response()->json(['message' => 'Sessão encerrada.']);
    }
}
