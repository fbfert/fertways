<?php

namespace App\Domain\Admin;

use App\Exceptions\DomainRuleException;
use App\Models\Admin;
use Illuminate\Support\Facades\Hash;

/**
 * O CRUD das contas de administração (D-61). **Só o dono.**
 *
 * **As duas travas que o painel tem contra si mesmo**, e que não são opcionais:
 *
 *  1. **Ninguém apaga nem rebaixa a si mesmo.** O erro clássico é o dono que se rebaixa a operador
 *     "para testar" e descobre que já não pode voltar atrás.
 *  2. **Não se apaga nem rebaixa o último dono.** Sem dono, o painel fica **inacessível para sempre**
 *     — não haveria quem promovesse ninguém, e a única saída seria mexer no banco pelo `artisan`.
 *
 * **Desativar, não apagar.** Um admin apagado deixaria as linhas de auditoria dele órfãs, e o
 * histórico de quem julgou o quê é justamente o que a auditoria existe para guardar. `desativado_em`
 * tira o acesso e preserva o rastro. (O `admin_email` é gravado em cada linha por segurança extra —
 * ver `Auditoria`.)
 */
class Contas
{
    public function __construct(private readonly Auditoria $auditoria) {}

    public function criar(array $dados): Admin
    {
        $admin = Admin::create([
            'name' => $dados['name'],
            'email' => $dados['email'],
            'password' => $dados['password'],
            'role' => $dados['role'],
        ]);

        $this->auditoria->registrar(
            'admin.criar',
            "Criou o admin {$admin->email} como {$admin->role}.",
            "admin:{$admin->id}",
            null,
            ['name' => $admin->name, 'email' => $admin->email, 'role' => $admin->role],
        );

        return $admin;
    }

    public function editar(Admin $alvo, Admin $autor, array $dados): Admin
    {
        $antes = $this->retrato($alvo);

        // Rebaixar o último dono trancaria o painel — inclusive rebaixando a si mesmo.
        if (isset($dados['role']) && $dados['role'] !== Admin::DONO && $alvo->ehDono()) {
            $this->exigirNaoSerOUltimoDono($alvo, 'rebaixar');
        }

        $alvo->fill(array_filter([
            'name' => $dados['name'] ?? null,
            'email' => $dados['email'] ?? null,
            'role' => $dados['role'] ?? null,
        ]));

        if (! empty($dados['password'])) {
            $alvo->password = $dados['password'];
        }

        $alvo->save();

        $this->auditoria->registrar(
            'admin.editar',
            "Editou o admin {$alvo->email}.".(empty($dados['password']) ? '' : ' Senha redefinida.'),
            "admin:{$alvo->id}",
            $antes,
            $this->retrato($alvo->fresh()),
        );

        return $alvo->fresh();
    }

    public function desativar(Admin $alvo, Admin $autor): void
    {
        if ($alvo->id === $autor->id) {
            throw new DomainRuleException(
                'nao_se_desativa',
                'Você não pode desativar a si mesmo. Peça a outro dono.',
            );
        }

        if ($alvo->ehDono()) {
            $this->exigirNaoSerOUltimoDono($alvo, 'desativar');
        }

        $antes = $this->retrato($alvo);
        $alvo->forceFill(['desativado_em' => now()])->save();

        $this->auditoria->registrar(
            'admin.desativar',
            "Desativou o admin {$alvo->email}.",
            "admin:{$alvo->id}",
            $antes,
            $this->retrato($alvo->fresh()),
        );
    }

    public function reativar(Admin $alvo): void
    {
        $antes = $this->retrato($alvo);
        $alvo->forceFill(['desativado_em' => null])->save();

        $this->auditoria->registrar(
            'admin.reativar',
            "Reativou o admin {$alvo->email}.",
            "admin:{$alvo->id}",
            $antes,
            $this->retrato($alvo->fresh()),
        );
    }

    /**
     * O painel não pode ficar sem dono ATIVO. Se ficar, ninguém promove ninguém, e a única saída é
     * o `artisan` no servidor — que é exatamente a situação que este projeto evita por princípio.
     */
    private function exigirNaoSerOUltimoDono(Admin $alvo, string $verbo): void
    {
        $outrosDonos = Admin::where('role', Admin::DONO)
            ->whereNull('desativado_em')
            ->whereKeyNot($alvo->id)
            ->count();

        if ($outrosDonos === 0) {
            throw new DomainRuleException(
                'ultimo_dono',
                "Não dá para {$verbo} o último dono: o painel ficaria sem ninguém que possa gerir admins.",
            );
        }
    }

    private function retrato(Admin $admin): array
    {
        return [
            'name' => $admin->name,
            'email' => $admin->email,
            'role' => $admin->role,
            'desativado_em' => $admin->desativado_em?->toDateTimeString(),
        ];
    }
}
