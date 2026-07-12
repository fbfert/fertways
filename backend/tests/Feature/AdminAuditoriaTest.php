<?php

namespace Tests\Feature;

use App\Domain\Colony\CreateColony;
use App\Models\Admin;
use App\Models\AuditEntry;
use App\Models\Colony;
use App\Models\Ledger;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

/**
 * A administração deixa rastro (D-61): auditoria append-only, papéis, suspensão, correção de estado
 * e realocação.
 */
class AdminAuditoriaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\ResourceTypeSeeder::class);
        $this->seed(\Database\Seeders\BuildingSpecSeeder::class);
        $this->seed(\Database\Seeders\TransportSettingSeeder::class);
    }

    private function dono(): Admin
    {
        return Admin::create([
            'name' => 'Dona', 'email' => 'dona@fertways.test',
            'password' => Hash::make('segredo-forte-1234'), 'role' => Admin::DONO,
        ]);
    }

    private function operador(): Admin
    {
        return Admin::create([
            'name' => 'Op', 'email' => 'op@fertways.test',
            'password' => Hash::make('segredo-forte-1234'), 'role' => Admin::OPERADOR,
        ]);
    }

    private int $proximo = 0;

    private function colono(): User
    {
        $user = User::factory()->create(['tutorial_completed_at' => now()]);
        app(CreateColony::class)->handle($user, 'Colônia', 10 + $this->proximo++, 20);

        return $user->fresh();
    }

    // ──────────────────────────────────────────── a auditoria é append-only

    public function test_a_linha_de_auditoria_nao_se_altera_nem_se_apaga(): void
    {
        /*
         * Um log que o próprio operador pudesse editar ou apagar não seria auditoria, seria
         * decoração. As duas travas (modelo e tabela sem `updated_at`) são de propósito.
         */
        $entrada = AuditEntry::create([
            'acao' => 'teste', 'resumo' => 'x', 'created_at' => now(),
        ]);

        $this->expectException(RuntimeException::class);
        $entrada->update(['resumo' => 'outro']);
    }

    public function test_a_linha_de_auditoria_nao_se_apaga(): void
    {
        $entrada = AuditEntry::create(['acao' => 'teste', 'resumo' => 'x', 'created_at' => now()]);

        $this->expectException(RuntimeException::class);
        $entrada->delete();
    }

    // ──────────────────────────────────────────── toda ação de admin é registrada

    public function test_o_login_do_admin_e_registrado(): void
    {
        $this->dono();

        $this->post('/admin/login', ['email' => 'dona@fertways.test', 'password' => 'segredo-forte-1234']);

        $this->assertDatabaseHas('audit_log', ['acao' => 'login.ok', 'admin_email' => 'dona@fertways.test']);
    }

    public function test_o_login_que_falha_tambem_e_registrado(): void
    {
        // A tentativa que falha é a MAIS importante: é o único sinal de que alguém está tentando
        // entrar. Ela não tem admin_id — o e-mail digitado pode nem existir.
        $this->post('/admin/login', ['email' => 'ninguem@fertways.test', 'password' => 'errada']);

        $this->assertDatabaseHas('audit_log', [
            'acao' => 'login.falhou',
            'admin_email' => 'ninguem@fertways.test',
            'admin_id' => null,
        ]);
    }

    public function test_distribuir_o_tesouro_deixa_rastro(): void
    {
        $colony = $this->colono()->colony;
        \App\Models\TreasuryHolding::updateOrCreate(['resource_type' => 'ligas_metalicas'], ['amount' => 1_000]);

        $this->actingAs($this->dono(), 'admin')
            ->from(route('admin.economia'))
            ->post(route('admin.tesouro.distribuir'), [
                'colony_id' => $colony->id, 'recurso' => 'ligas_metalicas', 'quantidade' => 100,
            ])->assertSessionHas('ok');

        $log = AuditEntry::where('acao', 'tesouro.distribuir')->first();

        $this->assertNotNull($log, 'a distribuição do Tesouro tem de aparecer no log');
        $this->assertSame("colony:{$colony->id}", $log->alvo);
        $this->assertSame('dona@fertways.test', $log->admin_email);
        $this->assertSame('dono', $log->papel, 'o papel DA HORA, não o de hoje');
    }

    // ──────────────────────────────────────────── suspensão

    public function test_o_suspenso_nao_entra_e_perde_os_tokens(): void
    {
        $user = $this->colono();
        $user->createToken('fertways');

        $this->actingAs($this->operador(), 'admin')
            ->from(route('admin.jogador', $user))
            ->post(route('admin.jogador.suspender', $user), ['motivo' => 'multi-conta', 'dias' => 7])
            ->assertSessionHas('ok');

        // Os tokens morrem AGORA: token do Sanctum não expira, e a porta trancada com a janela
        // aberta não tranca nada.
        $this->assertSame(0, $user->tokens()->count());

        $this->postJson('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertStatus(422);
    }

    public function test_o_suspenso_nao_despacha_carga_mas_a_colonia_continua_producindo(): void
    {
        $user = $this->colono();
        $colony = $user->colony;
        $colony->resources()->update(['amount' => 100_000]);
        $veiculo = $colony->vehicles()->first();

        app(\App\Domain\Admin\Suspender::class)->suspender($user, 'teste', null);

        // A saída de carga fecha — reusando a restrição comercial do §9.4, sem inventar mecânica.
        $this->actingAs($user->fresh())->postJson("/vehicles/{$veiculo->id}/dispatch", [
            'destination_type' => 'mercado_central',
            'cargo' => ['metal_bruto' => 100],
        ])->assertStatus(422)->assertJsonPath('code', 'conta_suspensa');

        // Mas o mundo não para: a colônia segue produzindo.
        $antes = (int) $colony->resources()->where('resource_type', 'oxigenio')->value('amount');
        $this->travelTo(now()->addHours(3));
        app(\App\Domain\Production\ColonyTick::class)->handle($colony->fresh(), now());

        $this->assertGreaterThan(
            $antes,
            (int) $colony->resources()->where('resource_type', 'oxigenio')->value('amount'),
            'a colônia do suspenso continua produzindo — nada se perde',
        );
    }

    public function test_a_suspensao_com_prazo_expira_sozinha(): void
    {
        $user = $this->colono();
        app(\App\Domain\Admin\Suspender::class)->suspender($user, 'teste', now()->addDays(3));

        $this->assertTrue(\App\Domain\Admin\Suspender::estaSuspenso($user->fresh()));

        $this->travelTo(now()->addDays(4));

        // Sem tick, sem varredura: a comparação na leitura basta, e é mais confiável do que uma
        // rotina periódica que pode não rodar.
        $this->assertFalse(\App\Domain\Admin\Suspender::estaSuspenso($user->fresh()));
    }

    // ──────────────────────────────────────────── correção de estado

    public function test_corrigir_estado_lanca_no_ledger_com_o_motivo(): void
    {
        $user = $this->colono();
        $colony = $user->colony;

        $this->actingAs($this->operador(), 'admin')
            ->from(route('admin.jogador', $user))
            ->post(route('admin.jogador.corrigir', $user), [
                'motivo' => 'bug do tick duplicou a produção',
                'fert' => 500,
                'recursos' => ['metal_bruto' => 999],
                'indices' => ['confianca_comercial' => 700],
            ])->assertSessionHas('ok');

        $colony = $colony->fresh();

        $this->assertSame(500 * Colony::MICRO_POR_FERT, (int) $colony->fert_micro);
        $this->assertSame(999, (int) $colony->resources()->where('resource_type', 'metal_bruto')->value('amount'));
        $this->assertSame(700, (int) $user->fresh()->confianca_comercial);

        /*
         * O ponto do D-61: dinheiro que nasce do nada TEM DE TER HISTÓRIA. O ledger é a única defesa
         * contra um operador que cria valor em silêncio — e o motivo escrito vai junto.
         */
        $ajuste = Ledger::where('colony_id', $colony->id)->where('type', 'ajuste_admin')->get();

        $this->assertGreaterThanOrEqual(2, $ajuste->count(), 'um lançamento por Fert$ e por recurso');
        $this->assertStringContainsString('bug do tick', $ajuste->first()->ref);

        // E a auditoria guardou o antes e o depois, que o ledger não guarda.
        $log = AuditEntry::where('acao', 'jogador.corrigir')->first();
        $this->assertSame(
            Colony::SALDO_INICIAL_MICRO,
            $log->de['fert_micro'],
            'antes ele tinha os 50 Fert$ do kit inicial',
        );
        $this->assertSame(500 * Colony::MICRO_POR_FERT, $log->para['fert_micro']);
    }

    public function test_correcao_sem_motivo_e_recusada(): void
    {
        $user = $this->colono();

        $this->actingAs($this->operador(), 'admin')
            ->from(route('admin.jogador', $user))
            ->post(route('admin.jogador.corrigir', $user), ['fert' => 500])
            ->assertSessionHasErrors('motivo');
    }

    // ──────────────────────────────────────────── papéis

    public function test_o_operador_nao_ve_a_tela_de_admins(): void
    {
        $this->actingAs($this->operador(), 'admin')->get(route('admin.admins'))->assertForbidden();
    }

    public function test_o_operador_nao_realoca(): void
    {
        $user = $this->colono();

        $this->actingAs($this->operador(), 'admin')
            ->post(route('admin.jogador.realocar', $user), [
                'x' => 30, 'y' => 30, 'motivo' => 'x', 'confirmacao' => 'REALOCAR',
            ])->assertForbidden();
    }

    public function test_o_operador_suspende_e_corrige(): void
    {
        // A linha entre os papéis é o que altera o estado do jogo de forma difícil de desfazer.
        // Suspender é moderação, e corrigir conserta bug — os dois são do operador.
        $user = $this->colono();

        $this->actingAs($this->operador(), 'admin')
            ->from(route('admin.jogador', $user))
            ->post(route('admin.jogador.suspender', $user), ['motivo' => 'teste'])
            ->assertSessionHas('ok');

        $this->assertTrue(\App\Domain\Admin\Suspender::estaSuspenso($user->fresh()));
    }

    // ──────────────────────────────────────────── as travas do CRUD de admins

    public function test_nao_se_desativa_o_ultimo_dono(): void
    {
        $dono = $this->dono();
        $outro = $this->operador();

        // Só há um dono. Desativá-lo trancaria o painel para sempre.
        $this->actingAs($outro, 'admin');

        $this->actingAs($dono, 'admin')
            ->from(route('admin.admins'))
            ->post(route('admin.admin.desativar', $dono))
            ->assertSessionHas('erro');

        $this->assertTrue($dono->fresh()->ativo());
    }

    public function test_ninguem_se_desativa(): void
    {
        $dono = $this->dono();
        Admin::create([
            'name' => 'Outro dono', 'email' => 'd2@fertways.test',
            'password' => Hash::make('segredo-forte-1234'), 'role' => Admin::DONO,
        ]);

        // Agora há dois donos — mas ninguém desativa a si mesmo, de qualquer jeito.
        $this->actingAs($dono, 'admin')
            ->from(route('admin.admins'))
            ->post(route('admin.admin.desativar', $dono))
            ->assertSessionHas('erro');

        $this->assertTrue($dono->fresh()->ativo());
    }

    public function test_o_admin_desativado_nao_entra(): void
    {
        $admin = $this->operador();
        $admin->forceFill(['desativado_em' => now()])->save();

        $this->post('/admin/login', ['email' => 'op@fertways.test', 'password' => 'segredo-forte-1234'])
            ->assertSessionHasErrors('email');

        $this->assertGuest('admin');
    }

    // ──────────────────────────────────────────── realocação

    public function test_realocar_move_e_refaz_a_viagem_em_curso(): void
    {
        $user = $this->colono();
        $colony = $user->colony;
        $colony->resources()->update(['amount' => 100_000]);

        // Um veículo a caminho da Capital, com a distância da posição ANTIGA.
        $veiculo = $colony->vehicles()->first();
        $this->actingAs($user)->postJson("/vehicles/{$veiculo->id}/dispatch", [
            'destination_type' => 'mercado_central',
            'cargo' => ['metal_bruto' => 100],
        ])->assertCreated();

        $distanciaAntes = (int) $veiculo->fresh()->distance_slots;

        // O comando `fertways:realocar-founders` RECUSARIA isto. O painel força e refaz (D-61).
        $this->actingAs($this->dono(), 'admin')
            ->from(route('admin.jogador', $user))
            ->post(route('admin.jogador.realocar', $user), [
                'x' => 2, 'y' => 2, 'motivo' => 'consertando um slot inválido',
                'confirmacao' => 'REALOCAR',
            ])->assertSessionHas('ok');

        $colony = $colony->fresh();
        $veiculo = $veiculo->fresh();

        $this->assertSame([2, 2], [(int) $colony->x, (int) $colony->y]);
        $this->assertSame('em_rota', $veiculo->status, 'o veículo não some nem trava');
        $this->assertNotSame($distanciaAntes, (int) $veiculo->distance_slots, 'a viagem foi refeita');
    }

    public function test_realocar_sem_a_palavra_e_recusado(): void
    {
        $user = $this->colono();

        $this->actingAs($this->dono(), 'admin')
            ->from(route('admin.jogador', $user))
            ->post(route('admin.jogador.realocar', $user), [
                'x' => 2, 'y' => 2, 'motivo' => 'x', 'confirmacao' => 'sim',
            ])->assertSessionHas('erro');

        $this->assertNotSame(2, (int) $user->colony->fresh()->x);
    }

    public function test_nao_se_realoca_para_celula_ocupada(): void
    {
        $a = $this->colono();
        $b = $this->colono();

        $this->actingAs($this->dono(), 'admin')
            ->from(route('admin.jogador', $a))
            ->post(route('admin.jogador.realocar', $a), [
                'x' => $b->colony->x, 'y' => $b->colony->y,
                'motivo' => 'x', 'confirmacao' => 'REALOCAR',
            ])->assertSessionHas('erro');
    }

    // ──────────────────────────────────────────── a busca

    public function test_a_busca_acha_por_placa(): void
    {
        $user = $this->colono();
        $placa = $user->colony->vehicles()->first()->plate;

        // A placa é o único identificador de outro jogador que aparece na tela de um colono — e por
        // isso é o que alguém tem à mão quando vem reclamar.
        $this->actingAs($this->operador(), 'admin')
            ->get(route('admin.jogadores', ['q' => $placa]))
            ->assertOk()
            ->assertSee($user->nickname);
    }
}
