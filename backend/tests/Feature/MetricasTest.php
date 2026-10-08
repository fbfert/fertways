<?php

namespace Tests\Feature;

use App\Domain\Colony\CreateColony;
use App\Domain\Telemetria\Indicadores;
use App\Models\Admin;
use App\Models\TelemetryEvent;
use App\Models\User;
use Database\Seeders\BuildingSpecSeeder;
use Database\Seeders\ResourceTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Painel de métricas (A2.0.2).
 *
 * O que estes testes guardam é sobretudo **honestidade de medida**: que um zero não seja
 * confundido com uma ausência, que o viés da duração de sessão apareça junto do número, e que a
 * lista de lacunas encolha sozinha quando um evento passa a ser emitido.
 */
class MetricasTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ResourceTypeSeeder::class);
        $this->seed(BuildingSpecSeeder::class);
    }

    private function colono(string $nick): User
    {
        return User::create([
            'name' => $nick, 'nickname' => $nick,
            'email' => $nick.'@fertways.test', 'password' => Hash::make('segredo-forte-123'),
        ]);
    }

    private function evento(string $tipo, ?User $u, string $quando, string $origem = 'humano'): void
    {
        TelemetryEvent::create([
            'type' => $tipo, 'user_id' => $u?->id, 'origin' => $origem, 'created_at' => $quando,
        ]);
    }

    // ─────────────────────────────────────────────────────────────── sessão

    public function test_dau_conta_jogador_distinto_e_nao_login(): void
    {
        $a = $this->colono('ana');
        $this->evento('login', $a, now()->subHours(2)->toDateTimeString());
        $this->evento('login', $a, now()->subHour()->toDateTimeString());
        $this->evento('login', $this->colono('bia'), now()->subHour()->toDateTimeString());

        $d = app(Indicadores::class)->tudo();

        $this->assertSame(2, $d['sessao']['dau'], 'dois jogadores, três logins');
        $this->assertSame(3, $d['sessao']['logins']);
    }

    /** Um DAU que conta o operador é um DAU mentiroso. */
    public function test_evento_de_sistema_nao_entra_no_dau(): void
    {
        $this->evento('login', $this->colono('ana'), now()->subHour()->toDateTimeString());
        $this->evento('evento_global', null, now()->subHour()->toDateTimeString(), 'sistema');

        $this->assertSame(1, app(Indicadores::class)->tudo()['sessao']['dau']);
    }

    public function test_duracao_mediana_sai_dos_pares_login_logout(): void
    {
        $a = $this->colono('ana');
        $this->evento('login', $a, now()->subHours(5)->toDateTimeString());
        $this->evento('logout', $a, now()->subHours(5)->addMinutes(20)->toDateTimeString());

        $d = app(Indicadores::class)->tudo()['sessao'];

        $this->assertSame(20, $d['duracao_mediana_min']);
        $this->assertSame(1, $d['pares_login_logout']);
    }

    /**
     * O viés precisa ser mensurável, não só mencionado.
     *
     * Quem fecha a aba nunca emite logout. Se a cobertura não fosse exposta, uma mediana calculada
     * sobre 1 de 3 sessões seria lida como "a sessão típica" — e não é.
     */
    public function test_cobertura_de_logout_expoe_o_vies(): void
    {
        $a = $this->colono('ana');
        $this->evento('login', $a, now()->subHours(5)->toDateTimeString());
        $this->evento('logout', $a, now()->subHours(5)->addMinutes(10)->toDateTimeString());
        $this->evento('login', $a, now()->subHours(3)->toDateTimeString());
        $this->evento('login', $a, now()->subHours(2)->toDateTimeString());

        $d = app(Indicadores::class)->tudo()['sessao'];

        $this->assertSame(3, $d['logins']);
        $this->assertSame(1, $d['pares_login_logout']);
        $this->assertEqualsWithDelta(33.3, $d['cobertura_logout_pct'], 0.1);
    }

    /** Login sobre login: a sessão anterior nunca foi encerrada e não vira duração inventada. */
    public function test_login_sem_logout_nao_vira_duracao(): void
    {
        $a = $this->colono('ana');
        $this->evento('login', $a, now()->subHours(5)->toDateTimeString());
        $this->evento('login', $a, now()->subHours(4)->toDateTimeString());

        $d = app(Indicadores::class)->tudo()['sessao'];

        $this->assertSame(0, $d['pares_login_logout']);
        $this->assertNull($d['duracao_mediana_min']);
    }

    // ───────────────────────────────────────────────── zero não é ausência

    /**
     * A invariante central do painel.
     *
     * Um retrato diário vazio não quer dizer "a economia parou" — quer dizer que o agregador ainda
     * não rodou. A tela precisa distinguir as duas coisas, e a bandeira é como ela distingue.
     */
    public function test_retrato_vazio_e_marcado_como_ausencia_e_nao_como_zero(): void
    {
        $this->assertFalse(app(Indicadores::class)->tudo()['economia']['tem_retrato']);
    }

    // ─────────────────────────────────────────────────────────── lacunas

    /** Sem emissor, o indicador é declarado ausente — não preenchido com zero. */
    public function test_indicador_sem_instrumentacao_aparece_como_lacuna(): void
    {
        $lacunas = array_column(app(Indicadores::class)->lacunas(), 'falta');

        $this->assertContains('onboarding_abandonado', $lacunas);
    }

    /**
     * E a lista encolhe sozinha quando o evento passa a existir.
     *
     * É o que impede a lista de virar folclore: ela é derivada do que a tabela realmente contém, e
     * não uma constante que alguém teria de lembrar de editar.
     */
    public function test_lacuna_some_quando_o_evento_passa_a_ser_emitido(): void
    {
        $antes = array_column(app(Indicadores::class)->lacunas(), 'falta');
        $this->assertContains('colonia_fundada', $antes);

        app(CreateColony::class)->handle($this->colono('novo'), 'Colônia', 0, 3);

        $depois = array_column(app(Indicadores::class)->lacunas(), 'falta');
        $this->assertNotContains('colonia_fundada', $depois);
    }

    /** Fundar emite o evento — é o ato que o funil de onboarding conta, e o ledger não o vê. */
    public function test_fundar_colonia_emite_evento(): void
    {
        app(CreateColony::class)->handle($this->colono('funda'), 'Colônia', 0, 3);

        $this->assertSame(1, TelemetryEvent::where('type', 'colonia_fundada')->count());
    }

    // ───────────────────────────────────────────────────────────── riqueza

    public function test_concentracao_de_riqueza_usa_a_fatia_do_topo(): void
    {
        foreach (range(1, 10) as $i) {
            $c = app(CreateColony::class)->handle($this->colono("rico{$i}"), "C{$i}", 0, 2 + $i);
            $c->update(['fert_micro' => $i === 1 ? 900_000_000 : 11_111_111]);
        }

        $r = app(Indicadores::class)->tudo()['riqueza'];

        $this->assertSame(10, $r['colonias']);
        $this->assertSame(1, $r['topo_10_quantas']);
        $this->assertGreaterThan(85, $r['topo_10_pct']);
    }

    // ─────────────────────────────────────────────────────────────── porta

    public function test_o_painel_de_metricas_exige_admin(): void
    {
        $this->get('/admin/metricas')->assertRedirect();
    }

    /**
     * Renderiza a página de verdade, e não só confere o status.
     *
     * Uma view Blade quebra em RUNTIME — um `$dados['chave']` que não existe, um método errado num
     * objeto. Nenhum teste de unidade dos indicadores pegaria isso; só montar a página pega. Foi
     * por isto que este teste existe, e é por isto que ele afirma texto da tela, não só o 200.
     */
    public function test_a_pagina_de_metricas_renderiza(): void
    {
        $a = $this->colono('ana');
        $this->evento('login', $a, now()->subHour()->toDateTimeString());

        $this->actingAs($this->admin(), 'admin')
            ->get('/admin/metricas')
            ->assertOk()
            ->assertSee('Métricas de produto')
            ->assertSee('DAU')
            ->assertSee('Ainda sem medida');
    }

    /** A página aguenta um mundo completamente vazio — é o estado de um servidor recém-aberto. */
    public function test_a_pagina_renderiza_com_o_mundo_vazio(): void
    {
        $this->actingAs($this->admin(), 'admin')->get('/admin/metricas')->assertOk();
    }

    private function admin(): Admin
    {
        return Admin::create([
            'name' => 'Equipe', 'email' => 'eq@t.test',
            'password' => Hash::make('segredo-forte-123'),
        ]);
    }

    // ──────────────────────────────────────────────── retorno pós-saque (D-251)

    private function saque(User $u, string $quando, bool $ausente = true): void
    {
        TelemetryEvent::create([
            'type' => 'colonia_saqueada', 'user_id' => $u->id, 'colony_id' => null, 'origin' => 'sistema',
            'payload' => ['defensor_offline' => $ausente, 'levado' => 100], 'created_at' => $quando,
        ]);
    }

    /** Voltou, não voltou e em aberto são três estados — um saque de ontem ainda não é abandono. */
    public function test_retorno_pos_saque_separa_os_tres_estados(): void
    {
        $voltou = $this->colono('voltou');
        $sumiu = $this->colono('sumiu');
        $ontem = $this->colono('ontem');

        $this->saque($voltou, now()->subDays(5)->toDateTimeString());
        $this->evento('login', $voltou, now()->subDays(5)->addHours(6)->toDateTimeString());

        $this->saque($sumiu, now()->subDays(10)->toDateTimeString());
        $this->evento('login', $sumiu, now()->subDays(1)->toDateTimeString()); // voltou, mas tarde demais

        $this->saque($ontem, now()->subDay()->toDateTimeString());

        $sa = app(Indicadores::class)->tudo()['saque_ausente'];

        $this->assertSame(3, $sa['saques']);
        $this->assertSame(1, $sa['voltou']);
        $this->assertSame(1, $sa['nao_voltou'], 'login nove dias depois não conta como volta');
        $this->assertSame(1, $sa['em_aberto']);
        $this->assertSame(6.0, $sa['mediana_horas']);
    }

    public function test_retorno_pos_saque_ignora_defensor_presente_e_bot(): void
    {
        $presente = $this->colono('presente');
        $bot = User::create([
            'name' => 'b', 'nickname' => 'bot1', 'email' => 'bot1@bots.fertways.local',
            'password' => Hash::make('segredo-forte-123'),
        ]);

        $this->saque($presente, now()->subDays(2)->toDateTimeString(), ausente: false);
        $this->saque($bot, now()->subDays(2)->toDateTimeString());

        $sa = app(Indicadores::class)->tudo()['saque_ausente'];

        $this->assertSame(0, $sa['saques']);
        $this->assertSame(1, $sa['bots_ignorados']);
    }

    /** Sem saque nenhum, o indicador é lacuna nomeada, e não "0 de 0". */
    public function test_sem_saque_o_retorno_e_lacuna(): void
    {
        $this->assertContains(
            'colonia_saqueada',
            array_column(app(Indicadores::class)->lacunas(), 'falta'),
        );
    }
}
