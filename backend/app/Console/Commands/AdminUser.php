<?php

namespace App\Console\Commands;

use App\Domain\Admin\Contas;
use App\Exceptions\DomainRuleException;
use App\Models\Admin;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/**
 * Cria e administra as contas da equipe (operadores do painel /central/admin).
 *
 * **Por que artisan.** Não há auto-registro no painel — seria uma porta para qualquer um virar
 * admin. A equipe se cria por CLI, à mão em produção, como o primeiro conciliador (D-44) e o
 * NeutralZoneSeeder (D-52). Ver docs/decisoes.md D-56.
 *
 * **E por que ela AINDA existe, depois de o painel ganhar o seu próprio CRUD (D-61):** ela é o
 * *quebre o vidro*. O painel gere admins, mas só se **houver um dono capaz de entrar nele**. Perdidas
 * as senhas dos donos, o painel não tem como se consertar — e o que resta é isto aqui, no shell.
 *
 * ⚠️ **Até o D-71 este comando era uma armadilha, e vinha de antes de os papéis existirem (D-61):**
 *
 *   - `--criar` **não escrevia o papel**, e o default da coluna é `operador`. Ou seja: **não havia
 *     como criar nem promover um dono pela CLI** — o quebre-o-vidro não quebrava vidro nenhum, e a
 *     única saída real era SQL cru.
 *   - `--remover` chamava `delete()` **direto no modelo**, por fora do `Domain\Admin\Contas`. A trava
 *     que impede apagar o último dono ("o painel ficaria inacessível para sempre") estava escrita e
 *     testada, e a CLI **passava ao largo dela**.
 *   - `--listar` não mostrava papel nem se a conta estava desativada: um admin sem acesso nenhum
 *     aparecia igualzinho a um dono.
 *
 * Agora tudo passa pelo `Contas`, que é onde as travas moram, e cada ato deixa linha na auditoria.
 *
 *   artisan fertways:admin --listar
 *   artisan fertways:admin --criar --email=eq@fertways.test --nome="Equipe" --senha=trocar-isto-123 --papel=dono
 *   artisan fertways:admin --alterar=eq@fertways.test --papel=dono
 *   artisan fertways:admin --alterar=eq@fertways.test --senha=nova-senha-forte-123
 *   artisan fertways:admin --desativar=eq@fertways.test
 *   artisan fertways:admin --reativar=eq@fertways.test
 *   artisan fertways:admin --remover=errado@fertways.test
 */
class AdminUser extends Command
{
    protected $signature = 'fertways:admin
        {--criar}
        {--email= : e-mail da conta a criar}
        {--nome= : nome de exibição}
        {--senha= : senha (mínimo 8); com --alterar, redefine a senha}
        {--papel= : dono ou operador; com --criar o padrão é operador}
        {--listar}
        {--alterar= : e-mail da conta a alterar (papel, senha ou nome)}
        {--desativar= : e-mail da conta a desativar (tira o acesso, preserva o rastro)}
        {--reativar= : e-mail da conta a reativar}
        {--remover= : e-mail da conta a APAGAR de vez — prefira --desativar}';

    protected $description = 'Cria e administra contas da equipe (painel de administração)';

    public function __construct(private readonly Contas $contas)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            return match (true) {
                (bool) $this->option('listar') => $this->listar(),
                (bool) $this->option('criar') => $this->criar(),
                (bool) $this->option('alterar') => $this->alterar((string) $this->option('alterar')),
                (bool) $this->option('desativar') => $this->desativar((string) $this->option('desativar')),
                (bool) $this->option('reativar') => $this->reativar((string) $this->option('reativar')),
                (bool) $this->option('remover') => $this->remover((string) $this->option('remover')),
                default => $this->semOpcao(),
            };
        } catch (DomainRuleException $e) {
            // É aqui que a trava do último dono aparece para quem está no shell. Ela existe desde o
            // D-61; até o D-71, a CLI não a consultava.
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function criar(): int
    {
        $papel = (string) ($this->option('papel') ?: Admin::OPERADOR);

        $dados = [
            'email' => (string) $this->option('email'),
            'nome' => (string) $this->option('nome'),
            'senha' => (string) $this->option('senha'),
            'papel' => $papel,
        ];

        if (! $this->conferir($dados, [
            'email' => ['required', 'email', 'unique:admins,email'],
            'nome' => ['required', 'string', 'max:60'],
            'senha' => ['required', 'string', 'min:8'],
            'papel' => ['required', 'in:'.implode(',', Admin::PAPEIS)],
        ])) {
            return self::FAILURE;
        }

        $admin = $this->contas->criar([
            'name' => $dados['nome'],
            'email' => $dados['email'],
            // O cast `hashed` do modelo cifra sozinho, e reconhece o que já vem cifrado.
            'password' => $dados['senha'],
            'role' => $papel,
        ]);

        $this->info("Admin #{$admin->id} criado: {$admin->email} — papel {$admin->role}.");

        if (! $admin->ehDono()) {
            $this->line('  <fg=gray>Um operador NÃO gere admins e NÃO realoca colônias. Para isso, --papel=dono.</>');
        }

        return self::SUCCESS;
    }

    /** Muda papel, senha e/ou nome. É por aqui que se PROMOVE alguém a dono sem entrar no painel. */
    private function alterar(string $email): int
    {
        $alvo = $this->achar($email);

        if (! $alvo) {
            return self::FAILURE;
        }

        $papel = (string) $this->option('papel');
        $senha = (string) $this->option('senha');
        $nome = (string) $this->option('nome');

        if ($papel === '' && $senha === '' && $nome === '') {
            $this->error('Nada a alterar. Use --papel, --senha ou --nome junto com --alterar.');

            return self::FAILURE;
        }

        if (! $this->conferir(
            array_filter(['papel' => $papel, 'senha' => $senha, 'nome' => $nome], fn ($v) => $v !== ''),
            [
                'papel' => ['sometimes', 'in:'.implode(',', Admin::PAPEIS)],
                'senha' => ['sometimes', 'string', 'min:8'],
                'nome' => ['sometimes', 'string', 'max:60'],
            ],
        )) {
            return self::FAILURE;
        }

        $antes = $alvo->role;

        // `$autor` nulo: no shell não há admin logado. A trava do último dono não depende disso.
        $alvo = $this->contas->editar($alvo, null, array_filter([
            'role' => $papel ?: null,
            'password' => $senha ?: null,
            'name' => $nome ?: null,
        ]));

        $this->info("Admin #{$alvo->id} alterado: {$alvo->email}.");

        if ($papel !== '' && $papel !== $antes) {
            $this->line("  papel: {$antes} → <fg=green>{$alvo->role}</>");
        }

        if ($senha !== '') {
            $this->line('  <fg=yellow>senha redefinida.</> As sessões abertas dele continuam válidas.');
        }

        return self::SUCCESS;
    }

    private function desativar(string $email): int
    {
        $alvo = $this->achar($email);

        if (! $alvo) {
            return self::FAILURE;
        }

        $this->contas->desativar($alvo);
        $this->info("Admin {$email} desativado. Não entra mais; o rastro dele na auditoria fica.");

        return self::SUCCESS;
    }

    private function reativar(string $email): int
    {
        $alvo = $this->achar($email);

        if (! $alvo) {
            return self::FAILURE;
        }

        $this->contas->reativar($alvo);
        $this->info("Admin {$email} reativado.");

        return self::SUCCESS;
    }

    private function remover(string $email): int
    {
        $alvo = $this->achar($email);

        if (! $alvo) {
            return self::FAILURE;
        }

        $this->warn('Apagar é DEFINITIVO e some com a conta. Para só tirar o acesso, use --desativar.');

        if (! $this->confirm("Apagar mesmo a conta {$email} ({$alvo->role})?", false)) {
            $this->line('Nada foi feito.');

            return self::SUCCESS;
        }

        $this->contas->apagar($alvo);
        $this->info("Admin {$email} apagado.");

        return self::SUCCESS;
    }

    private function listar(): int
    {
        $admins = Admin::orderBy('id')->get();

        if ($admins->isEmpty()) {
            $this->line('Nenhum admin cadastrado. Crie o primeiro com --criar --papel=dono.');

            return self::SUCCESS;
        }

        $this->table(
            ['#', 'Nome', 'E-mail', 'Papel', 'Estado', 'Criado'],
            $admins->map(fn (Admin $a) => [
                $a->id,
                $a->name,
                $a->email,
                $a->ehDono() ? '<fg=green>dono</>' : 'operador',
                $a->ativo() ? 'ativo' : '<fg=red>desativado</>',
                $a->created_at?->format('Y-m-d H:i'),
            ])->all(),
        );

        /*
         * A contagem de donos ativos não é enfeite: **um dono só é ponto único de falha**. Perdida
         * aquela senha, ninguém promove ninguém pelo painel, e o jogo passa a depender de alguém com
         * shell no servidor. É barato ter dois; é caro descobrir tarde que só havia um.
         */
        $donos = $admins->filter(fn (Admin $a) => $a->ehDono() && $a->ativo())->count();

        match (true) {
            $donos === 0 => $this->error('NENHUM dono ativo: o painel não consegue mais gerir admins. Promova alguém com --alterar=<email> --papel=dono.'),
            $donos === 1 => $this->warn('Só UM dono ativo — ponto único de falha. Perdida essa senha, o painel só se conserta por aqui. Crie um segundo.'),
            default => $this->line("<fg=green>{$donos} donos ativos.</>"),
        };

        return self::SUCCESS;
    }

    private function achar(string $email): ?Admin
    {
        $alvo = Admin::where('email', $email)->first();

        if (! $alvo) {
            $this->error("Admin {$email} não encontrado. Veja os que existem com --listar.");
        }

        return $alvo;
    }

    /** @param  array<string, array<int, string>>  $regras */
    private function conferir(array $dados, array $regras): bool
    {
        $v = Validator::make($dados, $regras);

        if ($v->fails()) {
            foreach ($v->errors()->all() as $erro) {
                $this->error($erro);
            }

            return false;
        }

        return true;
    }

    private function semOpcao(): int
    {
        $this->error('Use --listar, --criar, --alterar=<email>, --desativar=<email>, --reativar=<email> ou --remover=<email>.');

        return self::FAILURE;
    }
}
