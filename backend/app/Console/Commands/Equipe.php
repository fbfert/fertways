<?php

namespace App\Console\Commands;

use App\Domain\Telemetria\Retencao;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Marca contas como da equipe, para ficarem fora da medida de retenção (D-267).
 *
 *     fertways:equipe --listar
 *     fertways:equipe --marcar=email@exemplo.com   (e --desmarcar=)
 *     fertways:equipe --todos-os-atuais            (antes do primeiro teste: "tudo o que existe sou eu")
 *
 * Bots e contas de sistema não são marcados: eles já ficam fora pelo domínio do e-mail.
 */
class Equipe extends Command
{
    protected $signature = 'fertways:equipe
        {--listar : mostra quem está marcado}
        {--marcar= : e-mail da conta a marcar}
        {--desmarcar= : e-mail da conta a desmarcar}
        {--todos-os-atuais : marca todo humano que existe agora}';

    protected $description = 'Contas da equipe ficam fora da medida de retenção (D-267).';

    public function handle(): int
    {
        if ($email = $this->option('marcar') ?? $this->option('desmarcar')) {
            $user = User::where('email', $email)->first();

            if (! $user) {
                $this->error("Nenhuma conta com o e-mail {$email}.");

                return self::FAILURE;
            }

            $user->forceFill(['equipe' => $this->option('marcar') !== null])->save();
            $this->info(($user->equipe ? 'Marcada' : 'Desmarcada')." como equipe: {$email}");

            return self::SUCCESS;
        }

        if ($this->option('todos-os-atuais')) {
            $n = $this->humanos()->where('equipe', false)->update(['equipe' => true]);
            $this->info("{$n} conta(s) marcada(s) como equipe. Quem se cadastrar a partir de agora conta como jogador.");

            return self::SUCCESS;
        }

        $this->table(['id', 'nickname', 'cadastro'], $this->humanos()->where('equipe', true)
            ->orderBy('id')->get(['id', 'nickname', 'created_at'])->map(fn ($u) => [$u->id, $u->nickname, $u->created_at])->all());

        return self::SUCCESS;
    }

    private function humanos()
    {
        return User::where('email', 'not like', '%'.Retencao::DOMINIO_DOS_BOTS)
            ->where('email', 'not like', '%'.Retencao::DOMINIO_DO_SISTEMA);
    }
}
