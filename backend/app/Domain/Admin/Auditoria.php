<?php

namespace App\Domain\Admin;

use App\Models\AuditEntry;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * O registro de tudo o que a administração faz (D-61).
 *
 * **Por que isto existe.** O `Ledger` é append-only porque, neste jogo, recurso não nasce sem
 * história. Mas o painel de administração podia julgar um caso, distribuir 10.000 Fert$ do Tesouro
 * ou disparar um tick **sem deixar rastro nenhum** — o operador era a única figura do sistema capaz
 * de criar valor sem história. Isto fecha esse buraco.
 *
 * **O que se guarda, e por quê cada coisa:**
 *
 *   quem       o admin e o **papel que ele tinha na hora** — se ele for promovido depois, o log não
 *              pode reescrever o passado
 *   quando     `created_at`, e só ele: linha de auditoria não se atualiza
 *   o quê      a ação (`tesouro.distribuir`) e o alvo (`user:12`)
 *   antes/     os valores, para responder à pergunta que de fato se faz do log: não "quem mexeu na
 *   depois     colônia 4?", mas "**o que exatamente** mudou nela, e do que para o quê?"
 *   de onde    IP e navegador
 *
 * **O e-mail vai numa coluna própria, e não é FK.** Se um admin for apagado, o rastro do que ele fez
 * **não pode sumir com ele** — pelo mesmo motivo por que o ledger não tem FK para o veículo.
 */
class Auditoria
{
    /**
     * Registra um ato administrativo.
     *
     * @param  string  $acao    verbo canônico e estável: `jogador.suspender`, `tesouro.distribuir`
     * @param  string|null  $alvo    `user:12`, `colony:4`, `admin:2` — texto, não FK
     * @param  array|null  $de      o estado antes (só os campos que interessam)
     * @param  array|null  $para    o estado depois
     */
    public function registrar(string $acao, string $resumo, ?string $alvo = null, ?array $de = null, ?array $para = null): AuditEntry
    {
        $admin = Auth::guard('admin')->user();

        return AuditEntry::create([
            'admin_id' => $admin?->id,
            'admin_email' => $admin?->email,
            // O papel **da hora**: promover alguém amanhã não pode reescrever o que ele era hoje.
            'papel' => $admin?->role,
            'acao' => $acao,
            'alvo' => $alvo,
            'resumo' => mb_substr($resumo, 0, 255),
            'de' => $de,
            'para' => $para,
            'ip' => Request::ip(),
            'agente' => mb_substr((string) Request::userAgent(), 0, 255),
            'created_at' => now(),
        ]);
    }

    /**
     * Registra uma tentativa de login — **inclusive a que falhou**.
     *
     * A que falhou é a mais importante das duas: é o único sinal que o painel dá de que alguém está
     * tentando entrar. Ela não tem `admin_id` (o e-mail digitado pode nem existir), e é por isso que
     * a coluna é nulável.
     */
    public function login(string $email, bool $sucesso): AuditEntry
    {
        return AuditEntry::create([
            'admin_id' => $sucesso ? Auth::guard('admin')->id() : null,
            'admin_email' => $email,
            'papel' => $sucesso ? Auth::guard('admin')->user()?->role : null,
            'acao' => $sucesso ? 'login.ok' : 'login.falhou',
            'alvo' => null,
            'resumo' => $sucesso ? "Entrou no painel: {$email}" : "Tentativa de login recusada: {$email}",
            'de' => null,
            'para' => null,
            'ip' => Request::ip(),
            'agente' => mb_substr((string) Request::userAgent(), 0, 255),
            'created_at' => now(),
        ]);
    }

    /**
     * O jeito preguiçoso de auditar uma alteração: dá-se o modelo antes e depois, e ele extrai o que
     * mudou. Evita que cada ação tenha de montar dois arrays à mão e esquecer um campo.
     *
     * @param  array<string>  $campos  quais atributos interessam
     */
    public function alteracao(string $acao, string $resumo, string $alvo, array $antes, array $depois): AuditEntry
    {
        return $this->registrar($acao, $resumo, $alvo, $antes, $depois);
    }
}
