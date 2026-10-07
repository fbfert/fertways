<?php

namespace Tests\Feature;

use App\Domain\Colony\CreateColony;
use App\Domain\Endurance\ComprarItem;
use App\Domain\Endurance\ConcluirEscavacoes;
use App\Domain\Endurance\Escavar;
use App\Domain\Eventos\EntregarCestas;
use App\Domain\Production\ColonyTick;
use App\Exceptions\DomainRuleException;
use App\Models\Admin;
use App\Models\Colony;
use App\Models\ColonyEnduranceItem;
use App\Models\EnduranceEscavacao;
use App\Models\EnduranceEscavacaoSetting;
use App\Models\EnduranceItem;
use App\Models\EnduranceItemInstance;
use App\Models\GameEvent;
use App\Models\Ledger;
use App\Models\User;
use Database\Seeders\BuildingSpecSeeder;
use Database\Seeders\ComponentRecipeSeeder;
use Database\Seeders\ResourceTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * A escavação da Endurance e os lotes de evento (A2.9 / GDD_ALPHA2 §11 e §11.2; D-249).
 */
class EscavacaoDaEnduranceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ResourceTypeSeeder::class);
        $this->seed(ComponentRecipeSeeder::class);
        $this->seed(BuildingSpecSeeder::class);
    }

    private int $proximo = 0;

    private function colonia(int $xp = 0): Colony
    {
        $c = app(CreateColony::class)->handle(User::factory()->create(), 'Base', 20 + $this->proximo++, 20);
        $c->resources()->where('resource_type', 'energia')->update(['amount' => 1_000]);
        $c->forceFill(['xp' => $xp, 'fert_micro' => 500 * Colony::MICRO_POR_FERT])->save();

        return $c->fresh();
    }

    private function ligar(array $custo = [], int $minutos = 60): void
    {
        EnduranceEscavacaoSetting::singleton()->update([
            'ativo' => true, 'duracao_minutos' => $minutos, 'custo' => $custo ?: null,
        ]);
    }

    private function peca(array $extra = []): EnduranceItem
    {
        return EnduranceItem::create(array_merge([
            'item_key' => 'peca'.$this->proximo++, 'secao' => 'comando', 'nome' => 'Peça',
            'tipo' => EnduranceItem::COMUM, 'origem' => EnduranceItem::ESCAVACAO,
            'quantidade_total' => 5, 'quantidade_vendida' => 0, 'preco_micro' => 1_000_000,
        ], $extra));
    }

    // ── a chave-mestra ──────────────────────────────────────────────────────

    public function test_nasce_desligada_e_sem_parametros(): void
    {
        $config = EnduranceEscavacaoSetting::singleton();

        $this->assertFalse($config->ativo);
        $this->assertNull($config->duracao_minutos, 'nenhum número de jogo inventado');
        $this->assertFalse($config->ligada());

        $this->peca();
        $this->expectException(DomainRuleException::class);
        app(Escavar::class)->handle($this->colonia(), 'comando');
    }

    public function test_ligada_sem_duracao_continua_desligada(): void
    {
        EnduranceEscavacaoSetting::singleton()->update(['ativo' => true, 'duracao_minutos' => null]);

        $this->assertFalse(EnduranceEscavacaoSetting::singleton()->ligada());
    }

    // ── o ciclo inteiro ─────────────────────────────────────────────────────

    public function test_escavar_cobra_reserva_e_so_entrega_quando_volta(): void
    {
        $this->ligar([EntregarCestas::FERT => 50 * Colony::MICRO_POR_FERT, 'energia' => 200], 60);
        $peca = $this->peca();
        $c = $this->colonia();

        $e = app(Escavar::class)->handle($c, 'comando');

        $this->assertSame(1, $peca->fresh()->quantidade_vendida, 'reservada no início');
        $this->assertSame(450 * Colony::MICRO_POR_FERT, (int) $c->fresh()->fert_micro);
        $this->assertSame(800, (int) $c->resources()->where('resource_type', 'energia')->value('amount'));
        $this->assertSame(2, Ledger::where('colony_id', $c->id)->where('type', 'escavacao_endurance')->count());
        $this->assertFalse(ColonyEnduranceItem::where('colony_id', $c->id)->exists(), 'ainda não achou');

        $this->assertSame(0, app(ConcluirEscavacoes::class)->handle($c), 'antes do prazo, nada');

        Carbon::setTestNow($e->finishes_at->copy()->addSecond());
        $this->assertSame(1, app(ConcluirEscavacoes::class)->handle($c));
        Carbon::setTestNow();

        $this->assertSame(1, (int) ColonyEnduranceItem::where('colony_id', $c->id)->value('quantidade'));
        $this->assertSame(EnduranceEscavacao::CONCLUIDA, $e->fresh()->status);
    }

    public function test_o_unico_achado_ganha_o_descobridor_na_volta(): void
    {
        $this->ligar();
        $unico = $this->peca(['tipo' => EnduranceItem::UNICO, 'quantidade_total' => 1]);
        $c = $this->colonia();

        $e = app(Escavar::class)->handle($c, 'comando');
        $this->assertFalse(EnduranceItemInstance::exists(), 'a biografia começa quando a peça sai do chão');

        Carbon::setTestNow($e->finishes_at->copy()->addSecond());
        app(ConcluirEscavacoes::class)->handle($c);
        Carbon::setTestNow();

        $i = EnduranceItemInstance::where('endurance_item_id', $unico->id)->firstOrFail();
        $this->assertSame($c->id, (int) $i->descobridor_colony_id);
        $this->assertSame('descoberta', $i->historico()->first()->motivo);
    }

    /** O tick conclui a escavação, antes da produção — como a pesquisa. */
    public function test_o_tick_traz_a_equipe_de_volta(): void
    {
        $this->ligar();
        $this->peca();
        $c = $this->colonia();
        $e = app(Escavar::class)->handle($c, 'comando');

        app(ColonyTick::class)->handle($c->fresh(), $e->finishes_at->copy()->addMinute());

        $this->assertSame(EnduranceEscavacao::CONCLUIDA, $e->fresh()->status);
    }

    // ── as recusas ──────────────────────────────────────────────────────────

    public function test_uma_escavacao_de_cada_vez(): void
    {
        $this->ligar();
        $this->peca();
        $c = $this->colonia();
        app(Escavar::class)->handle($c, 'comando');

        $this->expectExceptionMessage('já está escavando');
        app(Escavar::class)->handle($c, 'comando');
    }

    /** Seção sem nada a achar recusa ANTES de cobrar: ninguém paga por uma escavação vazia. */
    public function test_secao_esgotada_recusa_sem_cobrar(): void
    {
        $this->ligar([EntregarCestas::FERT => 50 * Colony::MICRO_POR_FERT]);
        $this->peca(['quantidade_vendida' => 5]);
        $c = $this->colonia();

        try {
            app(Escavar::class)->handle($c, 'comando');
            $this->fail('devia recusar');
        } catch (DomainRuleException $e) {
            $this->assertSame('secao_esgotada', $e->codigo);
        }

        $this->assertSame(500 * Colony::MICRO_POR_FERT, (int) $c->fresh()->fert_micro);
    }

    /** Peça da loja não se acha, e peça acima do marco não entra no sorteio. */
    public function test_o_sorteio_so_ve_pecas_de_escavacao_ao_alcance_do_marco(): void
    {
        $this->ligar();
        $this->peca(['origem' => EnduranceItem::LOJA]);
        $this->peca(['marco_minimo' => 10]);

        $this->assertCount(0, app(Escavar::class)->achados($this->colonia(0), 'comando'));
        $this->assertCount(1, app(Escavar::class)->achados($this->colonia(1_000_000), 'comando'));
    }

    public function test_falta_de_recurso_nomeia_o_que_falta(): void
    {
        $this->ligar(['energia' => 5_000]);
        $this->peca();

        $this->expectExceptionMessage('energia (1000 de 5000)');
        app(Escavar::class)->handle($this->colonia(), 'comando');
    }

    // ── a loja e os lotes de evento ─────────────────────────────────────────

    public function test_a_loja_nao_vende_peca_de_escavacao(): void
    {
        $p = $this->peca();

        $this->expectExceptionMessage('só se acha escavando');
        app(ComprarItem::class)->handle($this->colonia(), $p->item_key);
    }

    private function eventoAtivo(array $extra = []): GameEvent
    {
        return GameEvent::create(array_merge([
            'slug' => 'lote'.$this->proximo++, 'nome' => 'Lote', 'status' => 'ativo',
            'comeca_em' => now()->subHour(), 'termina_em' => now()->addDay(),
        ], $extra));
    }

    public function test_o_lote_de_evento_so_existe_dentro_da_janela(): void
    {
        $ev = $this->eventoAtivo();
        $p = $this->peca(['origem' => EnduranceItem::LOJA, 'game_event_id' => $ev->id]);
        $c = $this->colonia();

        $this->assertTrue($p->liberadaEm(now()));
        app(ComprarItem::class)->handle($c, $p->item_key);

        $ev->update(['status' => 'cancelado', 'cancelado_em' => now()->subMinute()]);

        $this->assertFalse($p->fresh()->liberadaEm(now()));
        $this->expectExceptionMessage('não está disponível agora');
        app(ComprarItem::class)->handle($c, $p->item_key);
    }

    /** O escopo SQL e o método PHP dizem a mesma coisa — rascunho, ativo, vencido e cancelado. */
    public function test_o_escopo_e_o_metodo_concordam(): void
    {
        $casos = [
            $this->eventoAtivo(),
            $this->eventoAtivo(['status' => 'rascunho']),
            $this->eventoAtivo(['comeca_em' => now()->subDays(3), 'termina_em' => now()->subDay()]),
            $this->eventoAtivo(['status' => 'cancelado', 'cancelado_em' => now()->subMinute()]),
            $this->eventoAtivo(['status' => 'cancelado', 'cancelado_em' => now()->addHour()]),
        ];

        foreach ($casos as $ev) {
            $p = $this->peca(['game_event_id' => $ev->id]);

            $this->assertSame(
                $p->liberadaEm(now()),
                EnduranceItem::whereKey($p->id)->liberadas(now())->exists(),
                "evento {$ev->slug} ({$ev->status})",
            );
        }

        $this->assertTrue($this->peca()->liberadaEm(now()), 'sem evento, sempre');
    }

    // ── a API ───────────────────────────────────────────────────────────────

    public function test_a_secao_mostra_a_escavacao_e_esconde_o_que_e_dela_da_loja(): void
    {
        $this->ligar(['energia' => 10], 30);
        $this->peca(['tipo' => EnduranceItem::UNICO, 'quantidade_total' => 1]);
        $this->peca(['origem' => EnduranceItem::LOJA, 'nome' => 'Da loja']);
        $c = $this->colonia();

        $r = $this->actingAs($c->user)->getJson('/endurance/secoes/comando')->assertOk();

        $this->assertSame(['Da loja'], collect($r->json('itens'))->pluck('nome')->all());
        $this->assertTrue($r->json('escavacao.ligada'));
        $this->assertSame(1, $r->json('escavacao.a_achar'));
        $this->assertTrue($r->json('escavacao.tem_unico'));
        $this->assertSame(['energia' => 10], $r->json('escavacao.custo'));

        $mapa = collect($this->actingAs($c->user)->getJson('/endurance/secoes-mapa')->json('secoes'))
            ->firstWhere('chave', 'comando');
        $this->assertSame(1, $mapa['pecas']);
        $this->assertSame(1, $mapa['a_escavar']);
    }

    public function test_o_endpoint_escava(): void
    {
        $this->ligar();
        $this->peca();
        $c = $this->colonia();

        $this->actingAs($c->user)->postJson('/endurance/secoes/comando/escavar')->assertCreated();

        $r = $this->actingAs($c->user)->getJson('/endurance/secoes/comando');
        $this->assertSame('comando', $r->json('escavacao.em_andamento.secao'));
        $this->assertNull($r->json('escavacao.ultima_achada'), 'o que vai achar não aparece antes da volta');
    }

    // ── o painel ────────────────────────────────────────────────────────────

    private function dono(): Admin
    {
        return Admin::create([
            'name' => 'Dona', 'email' => 'dona@fertways.test',
            'password' => Hash::make('segredo-forte-1234'), 'role' => Admin::DONO,
        ]);
    }

    public function test_o_painel_recusa_ligar_sem_duracao(): void
    {
        $this->actingAs($this->dono(), 'admin')
            ->post('/admin/endurance/escavacao', ['ativo' => 1, 'custo' => 'fert:50'])
            ->assertSessionHas('erro');

        $this->assertFalse(EnduranceEscavacaoSetting::singleton()->ativo);
    }

    public function test_o_painel_liga_com_custo_em_fert_e_recurso(): void
    {
        $this->actingAs($this->dono(), 'admin')
            ->post('/admin/endurance/escavacao', [
                'ativo' => 1, 'duracao_minutos' => 90, 'custo' => "fert:50\nenergia:200",
            ])
            ->assertSessionHas('ok');

        $c = EnduranceEscavacaoSetting::singleton();
        $this->assertTrue($c->ligada());
        $this->assertSame([EntregarCestas::FERT => 50 * Colony::MICRO_POR_FERT, 'energia' => 200], $c->custo);
    }
}
