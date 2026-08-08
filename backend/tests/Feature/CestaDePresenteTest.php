<?php

namespace Tests\Feature;

use App\Domain\Colony\CreateColony;
use App\Domain\Eventos\EntregarCestas;
use App\Domain\Eventos\Modificadores;
use App\Domain\Logistics\OcuparZonaNeutra;
use App\Domain\Logistics\RequisitosDeOcupacao;
use App\Domain\Marco\Curva;
use App\Exceptions\DomainRuleException;
use App\Models\Colony;
use App\Models\GameEvent;
use App\Models\GameEventEntrega;
use App\Models\Ledger;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ErgueEstruturasDaZona;
use Tests\TestCase;

/**
 * A Cesta de Presente e os dois portões de ocupação (D-232).
 *
 * O que estes testes guardam:
 *
 *  - a cesta **escreve** no ledger (ao contrário do modificador, que nunca escreve);
 *  - ela chega **uma vez** por colônia, mesmo se o entregador rodar dez vezes;
 *  - ela alcança **quem fundou depois** de o evento começar;
 *  - o portão do XP e o portão dos colonos **cedem** enquanto o evento vale, e **voltam** depois;
 *  - a régua desce, e o **XP de ninguém sobe** — que é a diferença entre um evento e um presente
 *    de XP, e a única forma de o mundo voltar ao normal quando a janela fechar.
 */
class CestaDePresenteTest extends TestCase
{
    use ErgueEstruturasDaZona;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\ResourceTypeSeeder::class);
        $this->seed(\Database\Seeders\ComponentRecipeSeeder::class);
        $this->seed(\Database\Seeders\BuildingSpecSeeder::class);
    }

    private int $proximo = 0;

    private function colonia(int $xp = 0): Colony
    {
        $u = User::factory()->create();
        $c = app(CreateColony::class)->handle($u, 'C'.$this->proximo, 30 + $this->proximo++, 30);
        $c->forceFill(['xp' => $xp])->save();

        return $c->fresh();
    }

    /** @param array<string,int> $cesta */
    private function eventoComCesta(array $cesta, array $extra = []): GameEvent
    {
        return GameEvent::create(array_merge([
            'slug' => 'cesta'.$this->proximo++,
            'nome' => 'Cesta de Presente',
            'comeca_em' => now()->subHour(),
            'termina_em' => now()->addDays(30),
            'status' => 'ativo',
            'modificador' => null,
            'efeito_bps' => null,
            'recompensas' => $cesta,
        ], $extra));
    }

    private function estoque(Colony $c, string $r): int
    {
        return (int) $c->resources()->where('resource_type', $r)->value('amount');
    }

    // ─────────────────────────────────────────────────────────────── a cesta

    public function test_a_cesta_credita_recursos_e_fert_e_deixa_rastro_no_ledger(): void
    {
        $c = $this->colonia();
        $antes = $this->estoque($c, 'energia');
        $fertAntes = (int) $c->fert_micro;

        $evento = $this->eventoComCesta([
            EntregarCestas::FERT => 400 * 1_000_000,
            'energia' => 20_000,
            'ligas_metalicas' => 1_300,
        ]);

        $this->assertSame(1, app(EntregarCestas::class)->doEvento($evento));

        $c->refresh();
        $this->assertSame($antes + 20_000, $this->estoque($c, 'energia'));
        $this->assertSame($fertAntes + 400 * 1_000_000, (int) $c->fert_micro);

        /*
         * O ponto que separa a cesta do modificador: uma linha por recurso, com tipo próprio. Sem
         * isto o "Desde sua última visita" veria o estoque saltar e não teria o que dizer.
         */
        $this->assertSame(3, Ledger::where('colony_id', $c->id)
            ->where('type', 'presente_evento')->count());

        $this->assertDatabaseHas('ledger', [
            'colony_id' => $c->id, 'type' => 'presente_evento',
            'resource_type' => 'energia', 'amount' => 20_000,
            'ref' => "evento:{$evento->slug}",
        ]);
    }

    public function test_a_cesta_chega_uma_vez_so_por_mais_que_o_entregador_rode(): void
    {
        $c = $this->colonia();
        $evento = $this->eventoComCesta(['energia' => 20_000]);
        $antes = $this->estoque($c, 'energia');

        $entregador = app(EntregarCestas::class);
        $entregador->doEvento($evento);
        $entregador->doEvento($evento);
        $entregador->todos();

        $this->assertSame($antes + 20_000, $this->estoque($c->fresh(), 'energia'), 'uma vez, não três');
        $this->assertSame(1, GameEventEntrega::where('game_event_id', $evento->id)->count());
    }

    public function test_quem_funda_durante_a_janela_tambem_recebe(): void
    {
        $velha = $this->colonia();
        $evento = $this->eventoComCesta(['energia' => 20_000]);

        $this->assertSame(1, app(EntregarCestas::class)->doEvento($evento));

        // O dia 12 de uma janela de 30: uma colônia que não existia quando o evento abriu.
        $nova = $this->colonia();
        // O kit inicial (D-85) já lhe deu energia — o que se mede é o DELTA, não o absoluto.
        $antes = $this->estoque($nova, 'energia');

        $this->assertSame(1, app(EntregarCestas::class)->doEvento($evento->fresh()));

        $this->assertSame($antes + 20_000, $this->estoque($nova->fresh(), 'energia'), 'a retardatária recebeu');
        $this->assertSame(2, GameEventEntrega::where('game_event_id', $evento->id)->count());
        $this->assertTrue(GameEventEntrega::where('game_event_id', $evento->id)
            ->where('colony_id', $velha->id)->exists());
    }

    public function test_evento_encerrado_nao_entrega_mais_e_cancelar_nao_recolhe(): void
    {
        $c = $this->colonia();
        $evento = $this->eventoComCesta(['energia' => 20_000]);

        app(EntregarCestas::class)->doEvento($evento);
        $depoisDaEntrega = $this->estoque($c->fresh(), 'energia');

        $evento->update(['status' => 'cancelado', 'cancelado_em' => now()]);

        // A colônia nova, que chegou depois do cancelamento, não pega nada.
        $tardia = $this->colonia();
        $antesDaTardia = $this->estoque($tardia, 'energia');
        $this->assertSame([], app(EntregarCestas::class)->todos());

        $this->assertSame($antesDaTardia, $this->estoque($tardia->fresh(), 'energia'));
        // E quem recebeu não devolve: o ledger é append-only.
        $this->assertSame($depoisDaEntrega, $this->estoque($c->fresh(), 'energia'));
    }

    public function test_a_cesta_ignora_recurso_que_nao_existe_no_catalogo(): void
    {
        $c = $this->colonia();
        $evento = $this->eventoComCesta(['energia' => 100, 'unobtainium' => 999, 'agua' => 0]);

        app(EntregarCestas::class)->doEvento($evento);

        $this->assertSame(1, Ledger::where('colony_id', $c->id)
            ->where('type', 'presente_evento')->count(), 'só a energia; zero e inexistente saem');
    }

    public function test_a_cesta_de_escopo_de_colonia_alcanca_so_ela(): void
    {
        $alvo = $this->colonia();
        $outra = $this->colonia();
        $alvoAntes = $this->estoque($alvo, 'energia');
        $outraAntes = $this->estoque($outra, 'energia');

        $evento = $this->eventoComCesta(
            ['energia' => 20_000],
            ['escopo' => 'colonia', 'colony_id' => $alvo->id],
        );

        $this->assertSame(1, app(EntregarCestas::class)->doEvento($evento));
        $this->assertSame($alvoAntes + 20_000, $this->estoque($alvo->fresh(), 'energia'));
        $this->assertSame($outraAntes, $this->estoque($outra->fresh(), 'energia'), 'a outra não é servida');
    }

    // ────────────────────────────────────────────── o portão do XP

    public function test_o_portao_do_xp_cede_durante_o_evento_e_volta_depois(): void
    {
        $cheio = Curva::xpDoMarco(RequisitosDeOcupacao::MARCO);
        $requisitos = app(RequisitosDeOcupacao::class);

        $this->assertSame($cheio, $requisitos->xpExigido(), 'sem evento, a régua é a do §05');

        $evento = GameEvent::create([
            'slug' => 'marco_cede', 'nome' => 'O marco cede',
            'comeca_em' => now()->subHour(), 'termina_em' => now()->addDays(30),
            'status' => 'ativo',
            'modificador' => Modificadores::OCUPACAO_MARCO, 'efeito_bps' => -9_500,
        ]);

        $this->assertSame(intdiv($cheio, 20), $requisitos->xpExigido(), '5% do normal');

        $evento->update(['status' => 'cancelado', 'cancelado_em' => now()->subSecond()]);

        $this->assertSame($cheio, $requisitos->xpExigido(), 'cancelou, o portão volta ao lugar');
    }

    /**
     * ⚠️ A régua desce, e o XP não sobe. É o que garante que o mundo volte ao normal quando a
     * janela fechar — um presente de XP seria irreversível, e o título do §05 passaria a mentir.
     */
    public function test_o_evento_nao_da_xp_a_ninguem(): void
    {
        $c = $this->colonia(500);

        GameEvent::create([
            'slug' => 'marco_cede2', 'nome' => 'O marco cede',
            'comeca_em' => now()->subHour(), 'termina_em' => now()->addDays(30),
            'status' => 'ativo',
            'modificador' => Modificadores::OCUPACAO_MARCO, 'efeito_bps' => -9_500,
        ]);

        $this->assertSame(500, (int) $c->fresh()->xp);
        $this->assertSame(5, Curva::marco((int) $c->fresh()->xp), 'o marco dele continua o que era');
    }

    public function test_ocupar_recusa_e_aceita_a_mesma_colonia_conforme_o_portao(): void
    {
        $colony = $this->colonia(500);

        foreach (['metal_bruto' => 50_000, 'ligas_metalicas' => 50_000, 'componentes_eletronicos' => 20_000] as $r => $q) {
            $colony->resources()->where('resource_type', $r)->update(['amount' => $q]);
        }
        $colony->update(['fert_micro' => 100_000 * 1_000_000]);
        $colony = $colony->fresh();

        $zona = $this->criarZonaComEstruturas([
            'x' => 60, 'y' => 60, 'district' => 'nordeste', 'mineral' => 'metal_bruto',
            'level' => 1, 'status' => 'livre', 'deposit_level' => 1,
        ]);

        try {
            app(OcuparZonaNeutra::class)->handle($colony, $zona);
            $this->fail('500 XP não passam pelo portão de 6.000');
        } catch (DomainRuleException $e) {
            $this->assertSame('marco_insuficiente', $e->codigo);
        }

        GameEvent::create([
            'slug' => 'cesta_marco', 'nome' => 'Cesta de Presente',
            'comeca_em' => now()->subHour(), 'termina_em' => now()->addDays(30),
            'status' => 'ativo',
            'modificador' => Modificadores::OCUPACAO_MARCO, 'efeito_bps' => -9_500,
        ]);
        GameEvent::create([
            'slug' => 'cesta_colonos', 'nome' => 'Mutirão de colonos',
            'comeca_em' => now()->subHour(), 'termina_em' => now()->addDays(30),
            'status' => 'ativo',
            'modificador' => Modificadores::OCUPACAO_POPULACAO, 'efeito_bps' => -10_000,
        ]);

        $ocupada = app(OcuparZonaNeutra::class)->handle($colony->fresh(), $zona->fresh());

        $this->assertSame($colony->id, $ocupada->owner_colony_id, 'com o evento, a mesma colônia ocupa');
    }

    /** A tela e o comando têm de contar a MESMA verdade — a lição que custou o D-224. */
    public function test_a_tela_do_mapa_conta_a_mesma_verdade_que_o_comando(): void
    {
        $c = $this->colonia(500);
        $requisitos = app(RequisitosDeOcupacao::class);

        $antes = collect($requisitos->para($c)['falta'])->pluck('tipo');
        $this->assertTrue($antes->contains('marco'), 'sem evento, a tela acusa o marco');

        GameEvent::create([
            'slug' => 'cesta_marco2', 'nome' => 'Cesta',
            'comeca_em' => now()->subHour(), 'termina_em' => now()->addDays(30),
            'status' => 'ativo',
            'modificador' => Modificadores::OCUPACAO_MARCO, 'efeito_bps' => -9_500,
        ]);

        $depois = $requisitos->para($c->fresh());

        $this->assertFalse(collect($depois['falta'])->pluck('tipo')->contains('marco'),
            'com o evento, a tela para de acusar o portão que já cedeu');
        $this->assertSame(300, $depois['xp_exigido']);
        $this->assertSame(6_000, $depois['xp_normal']);
    }

    // ────────────────────────────────────────────── o portão dos colonos

    public function test_o_portao_dos_colonos_isenta_e_volta(): void
    {
        // A população nasce DESLIGADA (D-178) e a produção está assim nos testes; ligá-la aqui é o
        // que torna a isenção mensurável — sem ela, `operadoresExigidos()` já era zero por outro
        // motivo, e o teste passaria sem provar nada.
        \DB::table('population_settings')->where('id', 1)->update(['ativo' => true]);
        app(\App\Domain\Populacao\Parametros::class)->recarregar();

        $requisitos = app(RequisitosDeOcupacao::class);
        $normal = $requisitos->operadoresExigidos();

        $this->assertGreaterThan(0, $normal, 'com população ligada, ocupar pede gente');

        GameEvent::create([
            'slug' => 'colonos', 'nome' => 'Mutirão',
            'comeca_em' => now()->subHour(), 'termina_em' => now()->addDays(30),
            'status' => 'ativo',
            'modificador' => Modificadores::OCUPACAO_POPULACAO, 'efeito_bps' => -10_000,
        ]);

        $this->assertSame(0, $requisitos->operadoresExigidos(), 'isento enquanto durar');
    }

    // ────────────────────────────────────────────── o comando

    public function test_o_comando_ensaia_a_cesta_sem_entregar_e_so_entrega_com_ativar(): void
    {
        $c = $this->colonia();
        $antes = $this->estoque($c, 'energia');

        $argumentos = [
            'slug' => 'cesta_cli',
            '--nome' => 'Cesta de Presente',
            '--cesta' => '__fert__:400,energia:20000',
            '--horas' => 720,
        ];

        $this->artisan('fertways:evento', $argumentos)->assertSuccessful();

        $this->assertSame('rascunho', GameEvent::where('slug', 'cesta_cli')->value('status'));
        $this->assertSame($antes, $this->estoque($c->fresh(), 'energia'), 'ensaio não entrega');

        $this->artisan('fertways:evento', $argumentos + ['--ativar' => true])->assertSuccessful();

        $c->refresh();
        $this->assertSame($antes + 20_000, $this->estoque($c, 'energia'));
        // `__fert__:400` são 400 Fert$, e não 400 micro — a mesma escala que o painel usa.
        $this->assertSame(400 * 1_000_000, (int) $c->fert_micro - 100 * 1_000_000);
    }

    public function test_o_comando_recusa_recurso_desconhecido_na_cesta(): void
    {
        $this->artisan('fertways:evento', [
            'slug' => 'cesta_torta',
            '--cesta' => 'metal-bruto:100',
        ])->assertFailed();

        $this->assertNull(GameEvent::where('slug', 'cesta_torta')->first());
    }

    public function test_o_comando_recusa_reescrever_um_evento_ja_entregue(): void
    {
        $this->colonia();

        $args = ['slug' => 'cesta_uma_vez', '--cesta' => 'energia:100', '--horas' => 720];
        $this->artisan('fertways:evento', $args + ['--ativar' => true])->assertSuccessful();

        $this->artisan('fertways:evento', [
            'slug' => 'cesta_uma_vez', '--cesta' => 'energia:999999', '--horas' => 720,
        ])->assertFailed();

        $this->assertSame(100, GameEvent::where('slug', 'cesta_uma_vez')->first()->recompensas['energia']);
    }

    /** Os dois portões são PONTUAIS: `para()` recusa, porque "meio aberto" não é um estado. */
    public function test_os_portoes_de_ocupacao_recusam_a_media_ponderada(): void
    {
        foreach ([Modificadores::OCUPACAO_MARCO, Modificadores::OCUPACAO_POPULACAO] as $m) {
            try {
                app(Modificadores::class)->para(null, $m, now(), now()->addHour());
                $this->fail("«{$m}» devia recusar a média");
            } catch (\LogicException $e) {
                $this->assertStringContainsString('pontual', $e->getMessage());
            }
        }
    }
}
