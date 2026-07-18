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
 *   o quê      a ação (`tesouro.subsidio_colono`) e o alvo (`user:12`)
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
     * @param  string  $acao    verbo canônico e estável: `jogador.suspender`, `tesouro.subsidio_colono`
     * @param  string|null  $alvo    `user:12`, `colony:4`, `admin:2` — texto, não FK
     * @param  array|null  $de      o estado antes (só os campos que interessam)
     * @param  array|null  $para    o estado depois
     */
    public function registrar(string $acao, string $resumo, ?string $alvo = null, ?array $de = null, ?array $para = null): AuditEntry
    {
        $admin = Auth::guard('admin')->user();

        /*
         * ⚠️ **No `artisan` não há admin logado, e a linha sai sem "quem".** Desde o D-71 a CLI
         * também audita (`fertways:admin` cria e promove donos), e uma linha sem admin, sem IP e sem
         * navegador é indistinguível de um bug para quem lê o log. Dizer "artisan" é dizer a verdade:
         * **quem fez isto tinha shell no servidor** — o que é uma informação de segurança, não um
         * detalhe técnico. Não há como saber quem é a pessoa; o painel sabe, o console não.
         */
        $noConsole = app()->runningInConsole();

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
            'ip' => $noConsole ? null : Request::ip(),
            'agente' => $noConsole ? 'artisan (shell no servidor)' : mb_substr((string) Request::userAgent(), 0, 255),
            'created_at' => now(),
        ]);
    }

    /** As quatro coisas que podem acontecer na porta do painel (D-71). */
    public const LOGINS = [
        'login.ok' => 'Entrou no painel',
        // Nunca era registrado antes do D-71: o cookie do "lembrar de mim" não passa pelo controller.
        'login.lembrado' => 'Voltou ao painel pelo cookie de "lembrar de mim"',
        'login.falhou' => 'Tentativa de login recusada',
        'login.bloqueado' => 'Tentativas demais — login bloqueado por alguns segundos',
    ];

    /**
     * Registra o que acontece na porta do painel — **e a tentativa recusada é a mais importante das
     * quatro**: é o único sinal que o painel dá de que alguém está tentando entrar.
     *
     * ⚠️ **Quem chama isto são os EVENTOS do `Auth`** (ver `Listeners\AuditarLoginDoAdmin`), e não o
     * controller. Enquanto era o controller, quem entrava pelo cookie do "lembrar de mim" não
     * deixava rastro nenhum — e o log ficou meses sem uma única linha de login sem que ninguém
     * notasse, porque um log silencioso parece dizer "não houve nada".
     *
     * A linha da tentativa recusada não tem `admin_id`: o e-mail digitado pode nem existir. É por
     * isso que a coluna é nulável.
     */
    public function login(string $email, string $acao): AuditEntry
    {
        $entrou = $acao === 'login.ok' || $acao === 'login.lembrado';

        return AuditEntry::create([
            'admin_id' => $entrou ? Auth::guard('admin')->id() : null,
            'admin_email' => $email,
            'papel' => $entrou ? Auth::guard('admin')->user()?->role : null,
            'acao' => $acao,
            'alvo' => null,
            'resumo' => (self::LOGINS[$acao] ?? 'Login').": {$email}",
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
