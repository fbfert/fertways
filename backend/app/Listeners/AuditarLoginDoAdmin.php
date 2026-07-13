<?php

namespace App\Listeners;

use App\Domain\Admin\Auditoria;
use App\Models\Admin;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;

/**
 * Audita TODA entrada no painel — e o "toda" é o ponto (D-71).
 *
 * **O buraco que isto fecha.** A auditoria do D-61 gravava o login no `AuthController`, e o
 * comentário de lá dizia que a tentativa recusada "é o único sinal que o painel dá de que alguém
 * está tentando entrar". Só que em produção, meses depois, o `audit_log` **não tinha uma única linha
 * de login** — nem certa, nem errada. O motivo: quem entra pelo cookie do "lembrar de mim" é
 * reautenticado pelo `SessionGuard` a partir do *recaller*, e **nunca passa pelo controller**. O log
 * prometia registrar toda entrada e registrava só uma parte — o pior estado possível para um log,
 * porque o silêncio dele parecia significar "ninguém entrou".
 *
 * Ouvir os eventos do `Auth` pega os dois caminhos, porque é o próprio guard que os dispara.
 *
 * ⚠️ **Sempre confira o guard.** O `Login` e o `Failed` disparam para qualquer guard, e o colono tem
 * o seu. Sem esta guarda, cada login de jogador viraria uma linha na auditoria da equipe.
 */
class AuditarLoginDoAdmin
{
    public function __construct(private readonly Auditoria $auditoria) {}

    public function entrou(Login $evento): void
    {
        // O guard `admin` só autentica `Admin`; o `instanceof` é o que prova isso ao PHP — e ao
        // leitor — antes de eu ler o `->email`.
        if ($evento->guard !== 'admin' || ! $evento->user instanceof Admin) {
            return;
        }

        /*
         * Digitou a senha, ou voltou pelo cookie? Só o **pedido** sabe: o evento é o mesmo nos dois
         * casos (o `remember` dele diz se a sessão será lembrada, e não como ela nasceu).
         *
         * A distinção importa para quem lê o log: "alguém digitou a senha agora" e "um navegador que
         * já tinha a chave voltou" são fatos de segurança diferentes.
         */
        $peloFormulario = request()->routeIs('admin.login.enviar');

        $this->auditoria->login(
            email: (string) $evento->user->email,
            acao: $peloFormulario ? 'login.ok' : 'login.lembrado',
        );
    }

    public function falhou(Failed $evento): void
    {
        if ($evento->guard !== 'admin') {
            return;
        }

        // O e-mail vem das CREDENCIAIS, e não do usuário: numa tentativa recusada ele pode nem
        // existir na tabela — e é exatamente essa a que mais interessa registrar.
        $this->auditoria->login(
            email: (string) ($evento->credentials['email'] ?? '?'),
            acao: 'login.falhou',
        );
    }
}
