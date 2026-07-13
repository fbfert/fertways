<?php

namespace App\Http\Controllers\Api;

use App\Domain\Trade\AcordoSpecs;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * O perfil do colono (docs/decisoes.md D-69).
 *
 * Ele podia jogar, guerrear e comerciar — e **não podia trocar a própria senha**. A única forma de
 * mudar qualquer coisa da conta era pedir a um operador, pelo painel de admin.
 *
 * ── O que se edita, e o que NÃO se edita ────────────────────────────────────────────────────────
 *
 * Edita: nome, nickname, e-mail, senha e o **nome da colônia**.
 *
 * ⚠️ **Os quatro índices de reputação (§26.2) NÃO se editam, e nunca poderão.** Eles são o histórico
 * do colono no Ministério — Confiança Comercial, Conduta Social, Status Cívico, Honra Militar. Deixar
 * o próprio dono mexer neles seria deixá-lo apagar as suas condenações. Aparecem aqui porque ele tem
 * direito de os ver; são só leitura.
 */
class ProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $colony = $user->colony()->first();

        return response()->json([
            'name' => $user->name,
            'nickname' => $user->nickname,
            'email' => $user->email,
            'colony_name' => $colony?->name,
            'desde' => $user->created_at,

            /*
             * Os quatro índices do §26.2, **isolados e sem compensação cruzada** (seção 0 do GDD):
             * ser um bom cidadão não paga uma dívida comercial. Só leitura.
             */
            'reputacao' => [
                'confianca_comercial' => $user->confianca_comercial,
                'conduta_social' => $user->conduta_social,
                'status_civico' => $user->status_civico,
                'honra_militar_diplomatica' => $user->honra_militar_diplomatica,
            ],
            // Abaixo disto, a Confiança Comercial bloqueia o acesso ao Mercado (§26.2, D-43).
            'limiar_bloqueio' => AcordoSpecs::LIMIAR_MERCADO,

            'conciliador' => $user->conciliador_desde !== null,
        ]);
    }

    /**
     * Muda nome, nickname, e-mail e o nome da colônia.
     *
     * ⚠️ **Trocar o e-mail exige a SENHA ATUAL**, e trocar o nome não. A diferença não é capricho: o
     * e-mail é com o que se ENTRA no jogo. Quem pegasse uma sessão aberta num computador esquecido
     * poderia trocá-lo, trocar a senha, e o dono nunca mais entraria — **não há recuperação de conta
     * em Fertways**. Um nome mal escolhido se corrige; uma conta tomada, não.
     */
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        $dados = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'nickname' => ['required', 'string', 'max:60', Rule::unique('users', 'nickname')->ignore($user->id)],
            'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')->ignore($user->id)],
            'colony_name' => ['nullable', 'string', 'max:120'],
            'senha_atual' => ['nullable', 'string'],
        ]);

        $trocaEmail = $dados['email'] !== $user->email;

        if ($trocaEmail && ! Hash::check($dados['senha_atual'] ?? '', $user->password)) {
            return response()->json([
                'message' => 'Para trocar o e-mail, informe a sua senha atual. É com ele que você entra.',
                'code' => 'senha_atual_incorreta',
            ], 422);
        }

        $user->forceFill([
            'name' => $dados['name'],
            'nickname' => $dados['nickname'],
            'email' => $dados['email'],
        ])->save();

        if (! empty($dados['colony_name'])) {
            $user->colony()->update(['name' => $dados['colony_name']]);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Troca a senha, e **revoga as outras sessões**.
     *
     * A revogação não é zelo: se o colono está trocando a senha porque desconfia que alguém entrou na
     * conta dele, uma senha nova sem revogar os tokens **não expulsa ninguém** — o token do Sanctum
     * não expira, e o invasor continua dentro com a chave antiga. É a lição do D-53 (o logout que não
     * revogava) e a mesma coisa que a redefinição do painel de admin já faz.
     *
     * O token DESTA requisição sobrevive: seria absurdo deslogar quem acabou de se proteger.
     */
    public function password(Request $request): JsonResponse
    {
        $user = $request->user();

        $dados = $request->validate([
            'senha_atual' => ['required', 'string'],
            'senha' => ['required', 'string', 'confirmed', Password::min(8)],
        ]);

        if (! Hash::check($dados['senha_atual'], $user->password)) {
            return response()->json([
                'message' => 'A senha atual está incorreta.',
                'code' => 'senha_atual_incorreta',
            ], 422);
        }

        $user->forceFill(['password' => Hash::make($dados['senha'])])->save();

        $atual = $request->user()->currentAccessToken();
        $revogados = $user->tokens()->where('id', '!=', $atual?->id)->count();
        $user->tokens()->where('id', '!=', $atual?->id)->delete();

        return response()->json([
            'ok' => true,
            'sessoes_revogadas' => $revogados,
        ]);
    }
}
