<?php

namespace Tests\Feature;

use App\Domain\Telemetria\Retencao;
use App\Models\Colony;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Onde os jogadores param (D-264).
 *
 * O §16 do GDD registrou que o último login humano era de 17/07, e que nenhuma regra conserta a
 * ausência de jogador. Antes de construir mais sistema, a pergunta é de medida: **quem entra, até onde
 * chega, e onde desiste.** Tudo derivado do que já está gravado — cadastro, colônia, atos de XP,
 * login, último resumo visto —, sem evento novo.
 */
class RetencaoTest extends TestCase
{
    use RefreshDatabase;

    private int $proximo = 0;

    private function jogador(Carbon $cadastro, ?string $email = null): User
    {
        $n = $this->proximo++;
        $u = User::create([
            'name' => 'j', 'nickname' => 'j'.$n,
            'email' => $email ?? "j{$n}@exemplo.com", 'password' => Hash::make('x'),
        ]);
        $u->forceFill(['created_at' => $cadastro])->save();

        return $u;
    }

    private function colonia(User $u, Carbon $fundou, int $xp = 0): Colony
    {
        $c = Colony::create(['user_id' => $u->id, 'name' => 'C', 'x' => 0, 'y' => $this->proximo++, 'xp' => $xp]);
        $c->forceFill(['created_at' => $fundou, 'founded_at' => $fundou])->save();

        return $c;
    }

    private function ato(Colony $c, Carbon $quando, string $acao = 'obra_concluida'): void
    {
        DB::table('xp_entries')->insert(['colony_id' => $c->id, 'acao' => $acao, 'xp' => 15, 'ref' => 'x', 'created_at' => $quando]);
    }

    private function mundo(): array
    {
        $agora = now()->startOfDay()->addHours(12);
        $dia0 = $agora->copy()->subDays(40);

        // Nunca fundou.
        $this->jogador($dia0);

        // Fundou, agiu no dia 0, nunca voltou.
        $a = $this->jogador($dia0);
        $ca = $this->colonia($a, $dia0);
        $this->ato($ca, $dia0->copy()->addHours(2));

        // Voltou, durou 10 dias, foi embora. Último ato: missão.
        $b = $this->jogador($dia0);
        $cb = $this->colonia($b, $dia0, xp: 1_500);
        $this->ato($cb, $dia0->copy()->addHours(1));
        $this->ato($cb, $dia0->copy()->addDays(10), 'missao_concluida');

        // Ativo até hoje, pelo login (o token), mesmo sem ato recente.
        $c = $this->jogador($dia0);
        $cc = $this->colonia($c, $dia0);
        $this->ato($cc, $dia0->copy()->addHours(3));
        DB::table('personal_access_tokens')->insert([
            'tokenable_type' => User::class, 'tokenable_id' => $c->id, 'name' => 't',
            'token' => hash('sha256', 'c'), 'abilities' => '["*"]', 'last_used_at' => $agora->copy()->subDay(),
            'created_at' => $dia0, 'updated_at' => $agora,
        ]);

        // Um bot: não conta em nada.
        $bot = $this->jogador($dia0, 'robo@bots.fertways.local');
        $this->colonia($bot, $dia0);

        return [$agora];
    }

    public function test_o_funil_vai_do_cadastro_a_quem_ainda_joga(): void
    {
        [$agora] = $this->mundo();
        $this->travelTo($agora);

        $f = app(Retencao::class)->relatorio()['funil'];

        $this->assertSame(4, $f['cadastrou'], 'o bot não entra');
        $this->assertSame(3, $f['fundou']);
        $this->assertSame(3, $f['agiu']);
        $this->assertSame(2, $f['voltou_d1']);
        $this->assertSame(2, $f['ativo_d7']);
        $this->assertSame(1, $f['ativo_d30']);
        $this->assertSame(1, $f['ativo_agora']);
    }

    /** Quem sumiu há 14 dias ou mais: em que degrau parou, e o que fez por último. */
    public function test_o_relatorio_diz_onde_cada_um_parou(): void
    {
        [$agora] = $this->mundo();
        $this->travelTo($agora);

        $r = app(Retencao::class)->relatorio();

        $this->assertSame(3, $r['abandonos']['total']);
        $this->assertSame([
            'nunca_fundou' => 1,
            'nao_voltou_no_dia_seguinte' => 1,
            'parou_na_primeira_semana' => 0,
            'parou_antes_de_30_dias' => 1,
            'parou_depois_de_30_dias' => 0,
        ], $r['abandonos']['por_degrau']);
        $this->assertSame(1, $r['abandonos']['ultimo_ato']['missao_concluida']);
        $this->assertSame(1, $r['abandonos']['ultimo_ato']['obra_concluida']);
    }

    /** O "Desde sua última visita" visto também é visita — quem só olha a colônia não sumiu. */
    public function test_ver_o_resumo_conta_como_atividade(): void
    {
        $agora = now();
        $u = $this->jogador($agora->copy()->subDays(20));
        $this->colonia($u, $agora->copy()->subDays(20));
        $u->forceFill(['resumo_visto_em' => $agora->copy()->subHours(5)])->save();

        $this->assertSame(1, app(Retencao::class)->relatorio()['funil']['ativo_agora']);
    }

    /** O comando diz com que banco fala antes de qualquer número — ele é feito para rodar contra a produção. */
    public function test_o_comando_diz_o_banco_e_nao_escreve_nada(): void
    {
        [$agora] = $this->mundo();
        $this->travelTo($agora);
        $antes = DB::table('users')->count() + DB::table('xp_entries')->count();

        $this->artisan('fertways:retencao')
            ->expectsOutputToContain('Banco: ')
            ->assertSuccessful();

        $this->assertSame($antes, DB::table('users')->count() + DB::table('xp_entries')->count());
    }

    /**
     * As contas da equipe não são jogadores (D-267). Todo humano do servidor hoje é o próprio usuário
     * testando (D-265): se o teste com pessoas de verdade começasse sem esta marca, o funil misturaria
     * os testadores com as contas dele, e a primeira medida real nasceria contaminada.
     */
    public function test_conta_da_equipe_nao_entra_na_retencao(): void
    {
        [$agora] = $this->mundo();
        $this->travelTo($agora);

        $antes = app(Retencao::class)->relatorio()['funil']['cadastrou'];
        User::where('email', 'like', '%@exemplo.com')->limit(2)->update(['equipe' => true]);

        $this->assertSame($antes - 2, app(Retencao::class)->relatorio()['funil']['cadastrou']);
    }

    /** Antes do teste: marcar de uma vez todo humano que existe hoje — "tudo o que existe sou eu". */
    public function test_o_comando_marca_os_atuais_como_equipe_sem_tocar_nos_bots(): void
    {
        $this->jogador(now()->subDays(3));
        $this->jogador(now()->subDays(2));
        $bot = $this->jogador(now()->subDay(), 'r2@bots.fertways.local');

        $this->artisan('fertways:equipe', ['--todos-os-atuais' => true])->assertSuccessful();

        $this->assertSame(0, User::where('email', 'like', '%@exemplo.com')->where('equipe', false)->count());
        $this->assertFalse((bool) $bot->fresh()->equipe, 'bot não é equipe: ele já fica fora pelo domínio');
        $this->assertSame(0, app(Retencao::class)->relatorio()['funil']['cadastrou']);

        // Quem se cadastra DEPOIS é testador, e conta.
        $this->jogador(now());
        $this->assertSame(1, app(Retencao::class)->relatorio()['funil']['cadastrou']);
    }

    public function test_o_painel_de_metricas_mostra_a_retencao(): void
    {
        $dono = \App\Models\Admin::create([
            'name' => 'Dona', 'email' => 'dona@fertways.test',
            'password' => Hash::make('segredo-forte-1234'), 'role' => \App\Models\Admin::DONO,
        ]);

        $this->actingAs($dono, 'admin')->get('/admin/metricas')->assertOk()->assertSee('data-retencao', false);
    }
}
