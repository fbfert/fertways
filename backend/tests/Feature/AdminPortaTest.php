<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A porta do painel de administração (D-71).
 *
 * O painel é a única figura do sistema que **cria valor sem história**: realoca colônias, distribui o
 * Tesouro, julga casos. A auditoria do D-61 nasceu para fechar esse buraco — e a **porta** dela tinha
 * três rombos, que este teste fixa:
 *
 *   1. quem entrava pelo cookie do "lembrar de mim" **não deixava rastro nenhum**;
 *   2. a porta aceitava **tentativas ilimitadas** de senha;
 *   3. a CLI — o *quebre o vidro* — **não sabia criar um dono**, e sabia apagar o último.
 */
class AdminPortaTest extends TestCase
{
    use RefreshDatabase;

    private const SENHA = 'segredo-forte-1234';

    private function dono(string $email = 'dona@fertways.test'): Admin
    {
        return Admin::create([
            'name' => 'Dona', 'email' => $email,
            'password' => Hash::make(self::SENHA), 'role' => Admin::DONO,
        ]);
    }

    // ── 1. O cookie do "lembrar de mim" ──────────────────────────────────────

    /**
     * **O buraco que abriu o D-71.** Em produção o `audit_log` estava havia meses sem UMA linha de
     * login — nem certa, nem errada — enquanto o dono usava o painel todo dia. Ele entrava pelo
     * cookie, que reautentica no `SessionGuard` e **nunca passa pelo controller**, que era onde a
     * auditoria morava. O log parecia dizer "ninguém entrou aqui".
     */
    public function test_quem_volta_pelo_cookie_do_lembrar_de_mim_deixa_rastro(): void
    {
        $dono = $this->dono();
        $dono->forceFill(['remember_token' => Str::random(60)])->save();

        // É este o formato do *recaller* do Laravel: id|token|hash-da-senha. Um navegador que
        // "lembra" do painel manda exatamente isto, sem passar pelo formulário.
        $recaller = $dono->id.'|'.$dono->remember_token.'|'.$dono->password;

        $this->withCookie(Auth::guard('admin')->getRecallerName(), $recaller)
            ->get('/admin')
            ->assertOk();

        $this->assertDatabaseHas('audit_log', [
            'acao' => 'login.lembrado',
            'admin_email' => $dono->email,
        ]);
    }

    /** E quem digita a senha continua sendo `login.ok` — os dois fatos não podem virar um só. */
    public function test_quem_digita_a_senha_e_login_ok_e_nao_lembrado(): void
    {
        $dono = $this->dono();

        $this->post('/admin/login', ['email' => $dono->email, 'password' => self::SENHA]);

        $this->assertDatabaseHas('audit_log', ['acao' => 'login.ok', 'admin_email' => $dono->email]);
        $this->assertDatabaseMissing('audit_log', ['acao' => 'login.lembrado']);
    }

    /** O login do COLONO não pode sujar a auditoria da equipe: são dois guards, e o listener confere. */
    public function test_o_login_do_colono_nao_entra_na_auditoria_da_equipe(): void
    {
        $colono = \App\Models\User::create([
            'name' => 'Colono', 'nickname' => 'colono',
            'email' => 'colono@fertways.test', 'password' => Hash::make(self::SENHA),
        ]);

        Auth::guard('web')->login($colono);

        $this->assertDatabaseCount('audit_log', 0);
    }

    // ── 2. A força bruta ─────────────────────────────────────────────────────

    public function test_cinco_senhas_erradas_e_a_porta_fecha(): void
    {
        $dono = $this->dono();

        for ($i = 0; $i < 5; $i++) {
            $this->post('/admin/login', ['email' => $dono->email, 'password' => 'errada'])
                ->assertSessionHasErrors('email');
        }

        // A sexta nem chega a ser testada contra o banco: é barrada antes.
        $resposta = $this->post('/admin/login', ['email' => $dono->email, 'password' => 'errada']);

        $resposta->assertSessionHasErrors('email');
        $this->assertStringContainsString(
            'Tentativas demais',
            (string) session('errors')->first('email'),
        );

        $this->assertDatabaseHas('audit_log', [
            'acao' => 'login.bloqueado',
            'admin_email' => $dono->email,
        ]);
    }

    /**
     * ⚠️ **E o bloqueio não pode virar a arma.** Se a chave fosse só o e-mail, qualquer um martelando
     * o e-mail do dono o trancaria para fora do próprio painel. A chave inclui o IP: a senha CERTA,
     * vinda de outro lugar, entra mesmo com o balde do atacante cheio.
     */
    public function test_a_senha_certa_ainda_entra_mesmo_com_o_email_sob_ataque(): void
    {
        $dono = $this->dono();

        for ($i = 0; $i < 6; $i++) {
            $this->from('/admin/login')
                ->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])   // o atacante
                ->post('/admin/login', ['email' => $dono->email, 'password' => 'errada']);
        }

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.4'])     // o dono, de casa
            ->post('/admin/login', ['email' => $dono->email, 'password' => self::SENHA])
            ->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticatedAs($dono, 'admin');
    }

    /** Errar quatro vezes e acertar na quinta não pode deixar o balde quase cheio pelo resto do minuto. */
    public function test_entrar_esvazia_o_balde(): void
    {
        $dono = $this->dono();

        for ($i = 0; $i < 4; $i++) {
            $this->post('/admin/login', ['email' => $dono->email, 'password' => 'errada']);
        }

        $this->post('/admin/login', ['email' => $dono->email, 'password' => self::SENHA])
            ->assertRedirect(route('admin.dashboard'));

        $this->post('/admin/logout');

        // Se o balde não tivesse sido esvaziado, estas duas estourariam o limite de 5.
        for ($i = 0; $i < 2; $i++) {
            $this->post('/admin/login', ['email' => $dono->email, 'password' => 'errada']);
        }

        $this->assertDatabaseMissing('audit_log', ['acao' => 'login.bloqueado']);
    }

    // ── 3. O quebre-o-vidro: a CLI ───────────────────────────────────────────

    /**
     * **O rombo mais grave.** `--criar` nunca escrevia o papel, e o default da coluna é `operador`:
     * **não havia como criar um dono pela CLI**. Perdidas as senhas dos donos, o painel não se
     * conserta e a CLI também não — só SQL cru.
     */
    public function test_a_cli_cria_um_dono(): void
    {
        $this->artisan('fertways:admin', [
            '--criar' => true, '--email' => 'novo@fertways.test',
            '--nome' => 'Novo', '--senha' => self::SENHA, '--papel' => 'dono',
        ])->assertSuccessful();

        $this->assertSame(Admin::DONO, Admin::where('email', 'novo@fertways.test')->first()->role);
    }

    /** Sem `--papel`, sai um operador — e o comando DIZ isso, em vez de deixar a pessoa supor. */
    public function test_sem_papel_a_cli_cria_um_operador_e_avisa(): void
    {
        $this->artisan('fertways:admin', [
            '--criar' => true, '--email' => 'op@fertways.test',
            '--nome' => 'Op', '--senha' => self::SENHA,
        ])
            ->expectsOutputToContain('papel operador')
            ->assertSuccessful();

        $this->assertSame(Admin::OPERADOR, Admin::where('email', 'op@fertways.test')->first()->role);
    }

    /** E promove — o caminho de volta quando o painel ficou sem dono capaz de entrar. */
    public function test_a_cli_promove_um_operador_a_dono(): void
    {
        Admin::create([
            'name' => 'Op', 'email' => 'op@fertways.test',
            'password' => Hash::make(self::SENHA), 'role' => Admin::OPERADOR,
        ]);

        $this->artisan('fertways:admin', ['--alterar' => 'op@fertways.test', '--papel' => 'dono'])
            ->assertSuccessful();

        $this->assertSame(Admin::DONO, Admin::where('email', 'op@fertways.test')->first()->role);
        $this->assertDatabaseHas('audit_log', ['acao' => 'admin.editar', 'alvo' => 'admin:1']);
    }

    /**
     * ⚠️ **A armadilha.** A trava contra apagar o último dono existe desde o D-61, no `Contas` — e a
     * CLI chamava `delete()` direto no modelo, **passando por fora dela**. Um `--remover` no dono
     * errado deixava o painel sem dono nenhum e **inacessível para sempre**.
     */
    public function test_a_cli_nao_apaga_o_ultimo_dono(): void
    {
        $dono = $this->dono();

        $this->artisan('fertways:admin', ['--remover' => $dono->email])
            ->expectsConfirmation("Apagar mesmo a conta {$dono->email} (dono)?", 'yes')
            ->assertFailed();

        $this->assertDatabaseHas('admins', ['id' => $dono->id]);
    }

    public function test_a_cli_nao_rebaixa_nem_desativa_o_ultimo_dono(): void
    {
        $dono = $this->dono();

        $this->artisan('fertways:admin', ['--alterar' => $dono->email, '--papel' => 'operador'])
            ->assertFailed();

        $this->artisan('fertways:admin', ['--desativar' => $dono->email])
            ->assertFailed();

        $dono->refresh();
        $this->assertSame(Admin::DONO, $dono->role);
        $this->assertTrue($dono->ativo());
    }

    /** Com DOIS donos, apagar um é legítimo — a trava é sobre o último, não sobre o papel. */
    public function test_com_dois_donos_a_cli_apaga_um(): void
    {
        $this->dono();
        $segundo = $this->dono('segundo@fertways.test');

        $this->artisan('fertways:admin', ['--remover' => $segundo->email])
            ->expectsConfirmation("Apagar mesmo a conta {$segundo->email} (dono)?", 'yes')
            ->assertSuccessful();

        $this->assertDatabaseMissing('admins', ['id' => $segundo->id]);
    }

    /** O `--listar` grita quando há um dono só — que era, literalmente, a pendência que abriu isto. */
    public function test_o_listar_avisa_quando_ha_um_dono_so(): void
    {
        $this->dono();

        $this->artisan('fertways:admin', ['--listar' => true])
            ->expectsOutputToContain('ponto único de falha')
            ->assertSuccessful();

        $this->dono('segundo@fertways.test');

        $this->artisan('fertways:admin', ['--listar' => true])
            ->expectsOutputToContain('2 donos ativos')
            ->assertSuccessful();
    }

    /** Todo ato da CLI deixa rastro — e o rastro diz que veio do shell, não de um navegador. */
    public function test_o_que_a_cli_faz_vai_para_a_auditoria_marcado_como_shell(): void
    {
        $this->artisan('fertways:admin', [
            '--criar' => true, '--email' => 'novo@fertways.test',
            '--nome' => 'Novo', '--senha' => self::SENHA, '--papel' => 'dono',
        ])->assertSuccessful();

        $this->assertDatabaseHas('audit_log', [
            'acao' => 'admin.criar',
            'admin_email' => null,          // no shell não há admin logado, e o log não inventa um
            'agente' => 'artisan (shell no servidor)',
        ]);
    }
}
