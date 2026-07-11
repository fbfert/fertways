<?php

namespace App\Console\Commands;

use App\Models\Admin;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

/**
 * Cria e administra as contas da equipe (operadores do painel /central/admin).
 *
 * **Por que artisan.** Não há auto-registro no painel — seria uma porta para qualquer um virar
 * admin. A equipe se cria por CLI, à mão em produção, como o primeiro conciliador (D-44) e o
 * NeutralZoneSeeder (D-52). Ver docs/decisoes.md D-56.
 *
 *   artisan fertways:admin --criar --email=eq@fertways.test --nome="Equipe" --senha=trocar-isto-123
 *   artisan fertways:admin --listar
 *   artisan fertways:admin --remover=eq@fertways.test
 */
class AdminUser extends Command
{
    protected $signature = 'fertways:admin
        {--criar}
        {--email= : e-mail da conta}
        {--nome= : nome de exibição}
        {--senha= : senha (mínimo 8)}
        {--listar}
        {--remover= : e-mail da conta a remover}';

    protected $description = 'Cria e administra contas da equipe (painel de administração)';

    public function handle(): int
    {
        if ($this->option('listar')) {
            return $this->listar();
        }

        if ($this->option('remover')) {
            return $this->remover((string) $this->option('remover'));
        }

        if ($this->option('criar')) {
            return $this->criar();
        }

        $this->error('Use --criar, --listar ou --remover=<email>.');

        return self::FAILURE;
    }

    private function criar(): int
    {
        $dados = [
            'email' => (string) $this->option('email'),
            'nome' => (string) $this->option('nome'),
            'senha' => (string) $this->option('senha'),
        ];

        $v = Validator::make($dados, [
            'email' => ['required', 'email', 'unique:admins,email'],
            'nome' => ['required', 'string', 'max:60'],
            'senha' => ['required', 'string', 'min:8'],
        ]);

        if ($v->fails()) {
            foreach ($v->errors()->all() as $erro) {
                $this->error($erro);
            }

            return self::FAILURE;
        }

        $admin = Admin::create([
            'name' => $dados['nome'],
            'email' => $dados['email'],
            'password' => Hash::make($dados['senha']),
        ]);

        $this->info("Admin #{$admin->id} criado: {$admin->email}");

        return self::SUCCESS;
    }

    private function remover(string $email): int
    {
        $n = Admin::where('email', $email)->delete();

        $this->info($n > 0 ? "Admin {$email} removido." : "Admin {$email} não encontrado.");

        return self::SUCCESS;
    }

    private function listar(): int
    {
        $admins = Admin::orderBy('id')->get();

        if ($admins->isEmpty()) {
            $this->line('Nenhum admin cadastrado. Crie o primeiro com --criar.');

            return self::SUCCESS;
        }

        $this->table(
            ['#', 'Nome', 'E-mail', 'Criado'],
            $admins->map(fn (Admin $a) => [$a->id, $a->name, $a->email, $a->created_at?->format('Y-m-d H:i')])->all(),
        );

        return self::SUCCESS;
    }
}
