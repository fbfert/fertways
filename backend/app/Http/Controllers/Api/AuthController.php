<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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
            'nickname' => ['required', 'string', 'min:3', 'max:32', 'alpha_dash', 'unique:users,nickname'],
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

        return response()->json(['message' => 'Sessão encerrada.']);
    }
}
