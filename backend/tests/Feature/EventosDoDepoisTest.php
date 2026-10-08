<?php

namespace Tests\Feature;

use App\Domain\Colony\CreateColony;
use App\Domain\Eventos\AtivarEventos;
use App\Domain\Eventos\CondicoesDoMundo;
use App\Domain\Eventos\EntregarCestas;
use App\Domain\Eventos\Modificadores;
use App\Domain\Guerra\Forcas;
use App\Models\Admin;
use App\Models\Colony;
use App\Models\Federation;
use App\Models\GameEvent;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\BuildingSpecSeeder;
use Database\Seeders\ComponentRecipeSeeder;
use Database\Seeders\ResourceTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\ErgueEstruturasDaZona;
use Tests\TestCase;

/**
 * O "Depois" do roadmap da A2.8 (D-253): eventos de combate e de Federação, encadeados e por
 * condição composta.
 */
class EventosDoDepoisTest extends TestCase
{
    use ErgueEstruturasDaZona;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ResourceTypeSeeder::class);
        $this->seed(ComponentRecipeSeeder::class);
        $this->seed(BuildingSpecSeeder::class);
    }

    private int $proximo = 0;

    private function colonia(?Federation $fed = null): Colony
    {
        $c = app(CreateColony::class)->handle(User::factory()->create(), 'C', 20 + $this->proximo++, 20);

        if ($fed) {
            $c->update(['federation_id' => $fed->id, 'federation_role' => Federation::MEMBRO]);
        }

        return $c->fresh();
    }

    private function evento(array $extra = []): GameEvent
    {
        return GameEvent::create(array_merge([
            'slug' => 'ev'.$this->proximo++, 'nome' => 'Evento', 'status' => 'ativo',
            'comeca_em' => now()->subHour(), 'termina_em' => now()->addDay(),
            'modificador' => Modificadores::PRODUCAO, 'efeito_bps' => -2_000,
        ], $extra));
    }

    // ── Federação ───────────────────────────────────────────────────────────

    public function test_o_evento_de_federacao_alcanca_so_os_membros_dela(): void
    {
        $fed = Federation::create(['name' => 'Clube']);
        $membro = $this->colonia($fed);
        $fora = $this->colonia();
        $outra = $this->colonia(Federation::create(['name' => 'Outra']));

        $this->evento(['escopo' => 'federacao', 'federation_id' => $fed->id]);
        $m = app(Modificadores::class);

        $this->assertSame(8_000, $m->para($membro, Modificadores::PRODUCAO, now(), now()->addHour()));
        $this->assertSame(10_000, $m->para($fora, Modificadores::PRODUCAO, now(), now()->addHour()));
        $this->assertSame(10_000, $m->para($outra, Modificadores::PRODUCAO, now(), now()->addHour()));
        $this->assertSame(10_000, $m->para(null, Modificadores::PRODUCAO, now(), now()->addHour()), 'o Governo só vê o mundo');
    }

    public function test_a_cesta_de_federacao_so_chega_aos_membros(): void
    {
        $fed = Federation::create(['name' => 'Clube']);
        $membro = $this->colonia($fed);
        $fora = $this->colonia();
        $ev = $this->evento([
            'escopo' => 'federacao', 'federation_id' => $fed->id,
            'modificador' => null, 'efeito_bps' => null, 'recompensas' => ['energia' => 100],
        ]);

        $this->assertSame(1, app(EntregarCestas::class)->doEvento($ev));
        $this->assertTrue($ev->entregas()->where('colony_id', $membro->id)->exists());
        $this->assertFalse($ev->entregas()->where('colony_id', $fora->id)->exists());
    }

    /** A faixa do jogador lê o mesmo escopo que o motor — era a cópia que podia ficar para trás. */
    public function test_a_faixa_mostra_o_evento_de_federacao_so_ao_membro(): void
    {
        $fed = Federation::create(['name' => 'Clube']);
        $membro = $this->colonia($fed);
        $fora = $this->colonia();
        $this->evento(['escopo' => 'federacao', 'federation_id' => $fed->id, 'nome' => 'Festa do Clube']);

        $nomes = fn (Colony $c) => collect($this->actingAs($c->user)->getJson('/eventos')->json('eventos'))->pluck('nome');

        $this->assertContains('Festa do Clube', $nomes($membro));
        $this->assertNotContains('Festa do Clube', $nomes($fora));
    }

    // ── combate ─────────────────────────────────────────────────────────────

    public function test_o_evento_de_combate_mexe_na_defesa_da_zona_do_dono(): void
    {
        $dono = $this->colonia();
        $zona = $this->criarZonaComEstruturas([
            'x' => 47, 'y' => 47, 'district' => 'NE', 'mineral' => 'metal_bruto', 'level' => 1,
            'owner_colony_id' => $dono->id, 'status' => 'ocupada', 'deposit_level' => 1,
        ]);
        Unit::create(['zone_id' => $zona->id, 'type' => 'robo_minerador', 'level' => 1, 'hp_bps' => Unit::INTEIRA, 'status' => 'na_zona']);

        $antes = app(Forcas::class)->defensiva($zona->fresh());
        $this->assertGreaterThan(0, $antes);

        $this->evento(['modificador' => Modificadores::COMBATE_DEFESA, 'efeito_bps' => -5_000]);

        $this->assertSame(intdiv($antes * 5_000, 10_000), app(Forcas::class)->defensiva($zona->fresh()));
    }

    public function test_combate_defesa_e_pontual_e_nao_favorece_o_jogador(): void
    {
        $this->assertContains(Modificadores::COMBATE_DEFESA, Modificadores::PONTUAIS);
        $this->assertFalse((new GameEvent(['modificador' => 'combate_defesa', 'efeito_bps' => 3_000]))->favoreceOJogador());
    }

    // ── encadeados ──────────────────────────────────────────────────────────

    public function test_o_armado_sucede_o_anterior_quando_ele_termina_com_a_duracao_dele(): void
    {
        $a = $this->evento(['slug' => 'seca', 'termina_em' => now()->addHours(2)]);
        $b = $this->evento([
            'slug' => 'chuva', 'status' => 'armado', 'sucede_event_id' => $a->id,
            'comeca_em' => now(), 'termina_em' => now()->addHours(6),
        ]);

        $this->assertSame([], app(AtivarEventos::class)->handle(), 'o anterior ainda vale');

        Carbon::setTestNow(now()->addHours(3));
        $this->assertSame(['chuva'], app(AtivarEventos::class)->handle());
        Carbon::setTestNow();

        $b->refresh();
        $this->assertSame('ativo', $b->status);
        $this->assertSame($a->termina_em->getTimestamp(), $b->comeca_em->getTimestamp(), 'começa no fim do anterior, não no minuto do comando');
        $this->assertSame(6 * 3600, (int) $b->comeca_em->diffInSeconds($b->termina_em), 'a duração escrita');
    }

    public function test_cancelar_o_anterior_quebra_a_corrente(): void
    {
        $a = $this->evento(['termina_em' => now()->addHour()]);
        $a->update(['status' => 'cancelado', 'cancelado_em' => now()]);
        $b = $this->evento(['status' => 'armado', 'sucede_event_id' => $a->id]);

        Carbon::setTestNow(now()->addHours(2));
        $this->assertSame([], app(AtivarEventos::class)->handle());
        Carbon::setTestNow();

        $this->assertSame('armado', $b->fresh()->status);
    }

    /** Rascunho nunca se ativa — a regra do D-232 fica de pé. Só o ARMADO vai ao ar sozinho. */
    public function test_rascunho_com_corrente_nao_se_ativa(): void
    {
        $a = $this->evento(['termina_em' => now()->subMinute()]);
        $b = $this->evento(['status' => 'rascunho', 'sucede_event_id' => $a->id]);

        app(AtivarEventos::class)->handle();

        $this->assertSame('rascunho', $b->fresh()->status);
    }

    public function test_armado_nao_vale_no_mundo(): void
    {
        $c = $this->colonia();
        $e = $this->evento(['status' => 'armado', 'escopo' => 'colonia', 'colony_id' => $c->id]);

        $this->assertFalse($e->vigenteEm(now()));
        $this->assertSame(10_000, app(Modificadores::class)->para($c, Modificadores::PRODUCAO, now(), now()->addHour()));
    }

    // ── condições compostas ─────────────────────────────────────────────────

    public function test_o_armado_por_condicao_espera_o_mundo_e_vai_ao_ar(): void
    {
        $this->colonia();
        $e = $this->evento([
            'slug' => 'festa', 'status' => 'armado', 'gatilho' => 'condicao',
            'comeca_em' => now(), 'termina_em' => now()->addHours(4),
            'condicoes' => CondicoesDoMundo::normalizar('todas', [
                ['metrica' => 'colonias', 'op' => '>=', 'valor' => 2],
                ['metrica' => 'combates', 'op' => '=', 'valor' => 0],
            ]),
        ]);

        $this->assertSame([], app(AtivarEventos::class)->handle(), 'só uma colônia: E não fecha');

        $this->colonia();
        $this->assertSame(['festa'], app(AtivarEventos::class)->handle());
        $this->assertSame('ativo', $e->fresh()->status);
    }

    public function test_qualquer_e_ou(): void
    {
        $this->colonia();
        $c = app(CondicoesDoMundo::class);
        $ev = new GameEvent(['condicoes' => CondicoesDoMundo::normalizar('qualquer', [
            ['metrica' => 'colonias', 'op' => '>=', 'valor' => 50],
            ['metrica' => 'federacoes', 'op' => '<', 'valor' => 1],
        ])]);

        $this->assertTrue($c->satisfeitas($ev), 'a segunda regra basta');
    }

    public function test_condicao_recusa_metrica_fora_do_catalogo(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CondicoesDoMundo::normalizar('todas', [['metrica' => 'DROP TABLE', 'op' => '>=', 'valor' => 1]]);
    }

    // ── o operador ──────────────────────────────────────────────────────────

    public function test_o_comando_arma_uma_corrente_e_recusa_armar_sem_gatilho(): void
    {
        $this->evento(['slug' => 'seca']);

        $this->artisan('fertways:evento', ['slug' => 'chuva', '--producao' => 2000, '--sucede' => 'seca', '--armar' => true])
            ->assertSuccessful();
        $this->assertSame('armado', GameEvent::where('slug', 'chuva')->value('status'));

        $this->artisan('fertways:evento', ['slug' => 'solto', '--producao' => 2000, '--armar' => true])->assertFailed();
    }

    public function test_o_comando_le_a_condicao_e_a_federacao(): void
    {
        $fed = Federation::create(['name' => 'Clube']);

        $this->artisan('fertways:evento', [
            'slug' => 'clube', '--producao' => 1000, '--federacao' => $fed->id,
            '--condicao' => 'colonias>=10;zonas_ocupadas>=3', '--modo' => 'qualquer', '--armar' => true,
        ])->assertSuccessful();

        $e = GameEvent::where('slug', 'clube')->firstOrFail();
        $this->assertSame('federacao', $e->escopo);
        $this->assertSame($fed->id, (int) $e->federation_id);
        $this->assertSame('condicao', $e->gatilho);
        $this->assertSame('qualquer', $e->condicoes['modo']);
        $this->assertCount(2, $e->condicoes['regras']);
    }

    private function dono(): Admin
    {
        return Admin::create([
            'name' => 'Dona', 'email' => 'dona@fertways.test',
            'password' => Hash::make('segredo-forte-1234'), 'role' => Admin::DONO,
        ]);
    }

    public function test_o_painel_cria_com_condicao_e_arma(): void
    {
        $dono = $this->dono();

        $this->actingAs($dono, 'admin')->post('/admin/eventos', [
            'slug' => 'quando-houver-guerra', 'nome' => 'Trégua forçada', 'dias' => 2, 'visibilidade' => 'anunciado',
            'modificador' => 'guerra_declaracao', 'efeito_bps' => -10000,
            'condicao_modo' => 'todas',
            'condicao_metrica' => ['combates', ''], 'condicao_op' => ['>=', '>='], 'condicao_valor' => [1, ''],
        ])->assertSessionHasNoErrors();

        $e = GameEvent::where('slug', 'quando-houver-guerra')->firstOrFail();
        $this->assertSame('rascunho', $e->status, 'criar nunca ativa nem arma');
        $this->assertSame([['metrica' => 'combates', 'op' => '>=', 'valor' => 1]], $e->condicoes['regras']);

        $this->actingAs($dono, 'admin')->post("/admin/eventos/{$e->id}/armar")->assertSessionHas('ok');
        $this->assertSame('armado', $e->fresh()->status);
    }

    public function test_o_painel_recusa_armar_sem_gatilho(): void
    {
        $e = $this->evento(['status' => 'rascunho']);

        $this->actingAs($this->dono(), 'admin')->post("/admin/eventos/{$e->id}/armar")->assertSessionHas('erro');
        $this->assertSame('rascunho', $e->fresh()->status);
    }

    public function test_a_aba_de_eventos_renderiza_com_armados(): void
    {
        $a = $this->evento(['slug' => 'seca']);
        $this->evento(['slug' => 'chuva', 'status' => 'armado', 'sucede_event_id' => $a->id]);

        $this->actingAs($this->dono(), 'admin')->get('/admin/eventos')
            ->assertOk()->assertSee('Armados (1)')->assertSee('quando «Evento» terminar', false);
    }
}
