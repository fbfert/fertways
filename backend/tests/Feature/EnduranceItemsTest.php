<?php

namespace Tests\Feature;

use App\Domain\Colony\CreateColony;
use App\Domain\Drone\ConcluirMissoes;
use App\Domain\Drone\DroneSpecs;
use App\Domain\Drone\EnviarDrone;
use App\Domain\Drone\FabricarDrone;
use App\Domain\Endurance\ComprarItem;
use App\Domain\Endurance\EfeitosDaEndurance;
use App\Domain\Logistics\ConcluirTrechos;
use App\Domain\Logistics\DespacharVeiculo;
use App\Domain\Marco\Curva;
use App\Domain\Market\ColocarOrdem;
use App\Domain\Market\ExecutarOrdem;
use App\Domain\Production\ColonyTick;
use App\Domain\Transport\Conservacao;
use App\Domain\Transport\Placas;
use App\Domain\Treasury\Tesouro;
use App\Exceptions\DomainRuleException;
use App\Models\Colony;
use App\Models\ColonyEnduranceItem;
use App\Models\EnduranceItem;
use App\Models\Ledger;
use App\Models\MarketAccount;
use App\Models\NeutralZone;
use App\Models\User;
use App\Models\Vehicle;
use Database\Seeders\BuildingSpecSeeder;
use Database\Seeders\ComponentRecipeSeeder;
use Database\Seeders\ResourceTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ErgueEstruturasDaZona;
use Tests\TestCase;

/**
 * A Loja de Peças da Endurance (§05, D-135) — catálogo dinâmico, efeitos empilháveis. Substitui
 * `LojaDaEnduranceTest` (D-132/D-133, 32 peças fixas, um efeito só) — ver D-134 (rejeição) e D-135
 * (a reconstrução).
 */
class EnduranceItemsTest extends TestCase
{
    use RefreshDatabase;
    use ErgueEstruturasDaZona;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ResourceTypeSeeder::class);
        $this->seed(ComponentRecipeSeeder::class);
        $this->seed(BuildingSpecSeeder::class);
    }

    private int $proximoSlot = 0;

    private function colonia(string $nick, int $marco = 1, int $x = 10, int $y = 10): Colony
    {
        $user = User::factory()->create(['email' => "{$nick}@t.test", 'nickname' => $nick]);
        $colony = app(CreateColony::class)->handle($user, "Colônia {$nick}", $x + 2 * $this->proximoSlot++, $y);
        $colony->forceFill(['xp' => Curva::xpDoMarco($marco)])->save();

        return $colony->fresh();
    }

    private function fert(Colony $c): int
    {
        return (int) DB::table('colonies')->where('id', $c->id)->value('fert_micro');
    }

    private function creditar(Colony $c, int $fert): void
    {
        DB::table('colonies')->where('id', $c->id)->increment('fert_micro', $fert * 1_000_000);
    }

    /** Cria um item da Endurance direto no banco — os testes daqui não passam pelo painel. */
    private function item(array $attrs = [], array $efeitos = []): EnduranceItem
    {
        $item = EnduranceItem::create(array_merge([
            'item_key' => 'item_'.Str::random(10),
            'secao' => 'anel_habitacional',
            'nome' => 'Item de teste',
            'tipo' => EnduranceItem::COMUM,
            'quantidade_total' => 100,
            'quantidade_vendida' => 0,
            'preco_micro' => 1_000_000,
            'marco_minimo' => null,
            'vendavel_em_leilao' => false,
        ], $attrs));

        foreach ($efeitos as $e) {
            $item->efeitos()->create($e);
        }

        return $item;
    }

    private function comprar(Colony $c, EnduranceItem $item): ColonyEnduranceItem
    {
        return app(ComprarItem::class)->handle($c, $item->item_key);
    }

    // ---------------------------------------------------------------- a compra

    #[Test]
    public function compra_exige_o_marco_quando_o_item_tem_um(): void
    {
        $a = $this->colonia('a', 1);
        $item = $this->item(['marco_minimo' => 10]);

        $this->expectExceptionMessage('exige o marco 10');
        $this->comprar($a, $item);
    }

    #[Test]
    public function compra_sem_marco_minimo_nao_exige_nada(): void
    {
        $a = $this->colonia('a', 1);
        $item = $this->item(['marco_minimo' => null]);

        $posse = $this->comprar($a, $item);
        $this->assertSame(1, $posse->quantidade);
    }

    #[Test]
    public function a_compra_debita_fert_e_credita_o_tesouro(): void
    {
        $a = $this->colonia('a', 1);
        $item = $this->item(['preco_micro' => 20_000_000]);
        $antes = $this->fert($a);
        $tesouroAntes = app(Tesouro::class)->saldoFertMicro();

        $this->comprar($a, $item);

        $this->assertSame($antes - 20_000_000, $this->fert($a));
        $this->assertSame($tesouroAntes + 20_000_000, app(Tesouro::class)->saldoFertMicro());
        $this->assertSame(1, Ledger::where('type', 'compra_item_endurance')->count());
    }

    #[Test]
    public function item_unico_esgota_depois_da_primeira_compra(): void
    {
        $a = $this->colonia('a', 1);
        $b = $this->colonia('b', 1);
        $this->creditar($a, 500);
        $this->creditar($b, 500);
        $item = $this->item(['tipo' => EnduranceItem::UNICO, 'quantidade_total' => 1]);

        $this->comprar($a, $item);

        $this->expectExceptionMessage('esgotado');
        $this->comprar($b, $item->fresh());
    }

    #[Test]
    public function o_estoque_e_global_entre_colonias(): void
    {
        $a = $this->colonia('a', 1);
        $b = $this->colonia('b', 1);
        $c = $this->colonia('c', 1);
        $item = $this->item(['quantidade_total' => 2]);

        $this->comprar($a, $item);
        $this->comprar($b, $item->fresh());

        $this->expectExceptionMessage('esgotado');
        $this->comprar($c, $item->fresh());
    }

    #[Test]
    public function uma_colonia_pode_comprar_mais_de_uma_unidade_enquanto_ha_estoque(): void
    {
        $a = $this->colonia('a', 1);
        $item = $this->item(['quantidade_total' => 5]);

        $this->comprar($a, $item);
        $posse = $this->comprar($a, $item->fresh());

        $this->assertSame(2, $posse->quantidade);
        $this->assertSame(2, $item->fresh()->quantidade_vendida);
    }

    #[Test]
    public function fert_insuficiente_e_recusado(): void
    {
        $a = $this->colonia('a', 1);
        DB::table('colonies')->where('id', $a->id)->update(['fert_micro' => 0]);
        $item = $this->item();

        $this->expectExceptionMessage('Faltam');
        $this->comprar($a->fresh(), $item);
    }

    // ---------------------------------------------------------------- os efeitos empilham e capam

    #[Test]
    public function os_efeitos_de_producao_empilham_por_unidade_possuida_e_respeitam_o_teto(): void
    {
        $a = $this->colonia('a', 1);
        $this->creditar($a, 1000);
        $item = $this->item(
            ['quantidade_total' => 10],
            [['tipo_efeito' => EfeitosDaEndurance::PRODUCAO_BONUS, 'alvo' => 'mina_local', 'valor_bps' => 2000]],
        );
        $efeitos = app(EfeitosDaEndurance::class);

        $this->assertSame(0, $efeitos->bonusDeProducao($a, 'mina_local'));

        $this->comprar($a, $item);
        $this->assertSame(2000, $efeitos->bonusDeProducao($a->fresh(), 'mina_local'));

        $this->comprar($a->fresh(), $item->fresh()); // 2ª unidade: +2000 = 4000
        $this->assertSame(4000, $efeitos->bonusDeProducao($a->fresh(), 'mina_local'));

        $this->comprar($a->fresh(), $item->fresh()); // 3ª unidade: +2000 = 6000, capado em 5000 (teto)
        $this->assertSame(EfeitosDaEndurance::tetoBps(EfeitosDaEndurance::PRODUCAO_BONUS), $efeitos->bonusDeProducao($a->fresh(), 'mina_local'));
    }

    #[Test]
    public function o_desconto_de_tributo_soma_varios_itens_e_capa_em_30_por_cento(): void
    {
        $a = $this->colonia('a', 1);
        $this->creditar($a, 3000);
        $efeitos = app(EfeitosDaEndurance::class);

        foreach (range(1, 4) as $i) {
            $item = $this->item(
                ['item_key' => "tributo_{$i}"],
                [['tipo_efeito' => EfeitosDaEndurance::DESCONTO_TRIBUTO, 'alvo' => null, 'valor_bps' => 1000]],
            );
            $this->comprar($a->fresh(), $item);
        }

        // 4 × 1000 bps = 4000, capado em 3000 (mesmo teto do D-132/D-133).
        $this->assertSame(3000, $efeitos->descontoDeTributo($a->fresh()));
    }

    // ---------------------------------------------------------------- tributo (transporte e mercado)

    #[Test]
    public function o_desconto_reduz_o_tributo_da_entrega_por_transporte(): void
    {
        [$a, $b] = [$this->colonia('a', 1, 10, 10), $this->colonia('b', 1, 30, 30)];
        $item = $this->item([], [['tipo_efeito' => EfeitosDaEndurance::DESCONTO_TRIBUTO, 'alvo' => null, 'valor_bps' => 100]]);
        $this->comprar($a, $item); // 1%

        $a->resources()->where('resource_type', 'metal_bruto')->update(['amount' => 1000]);

        $veiculo = $a->vehicles()->create([
            'type' => 'furgao_de_comercio', 'level' => 1, 'status' => 'ocioso',
            'capacity' => Vehicle::CAPACIDADE['furgao_de_comercio'],
        ]);
        app(Placas::class)->registrar($veiculo);

        app(DespacharVeiculo::class)->handle($a->fresh(), $veiculo, 'colonia', $b->id, ['metal_bruto' => 1000]);

        $veiculo->refresh();
        $veiculo->update(['arrives_at' => now()->subMinute(), 'departs_at' => now()->subHour()]);

        app(ConcluirTrechos::class)->handle();

        // Metal Bruto é primário: 3% cheio = 300 bps. Com 1% de desconto, sobra 2,97%.
        $taxa = DB::table('tax_events')->where('kind', 'transporte_entrega')->first();
        $this->assertSame(297, (int) $taxa->tax_bps);
    }

    #[Test]
    public function o_desconto_reduz_o_tributo_da_venda_no_mercado_central(): void
    {
        $a = $this->colonia('a', 1);
        $b = $this->colonia('b', 1);
        $item = $this->item([], [['tipo_efeito' => EfeitosDaEndurance::DESCONTO_TRIBUTO, 'alvo' => null, 'valor_bps' => 100]]);
        $this->comprar($a, $item); // 1%

        MarketAccount::create(['colony_id' => $a->id, 'resource_type' => 'metal_bruto', 'amount' => 100]);
        $venda = app(ColocarOrdem::class)->handle($a->fresh(), 'sell', 'metal_bruto', 100, 50_000);
        app(ExecutarOrdem::class)->handle($b, $venda->id, 100);

        $taxa = DB::table('tax_events')->where('kind', 'mercado_venda')->first();
        $this->assertSame(297, (int) $taxa->tax_bps);
    }

    // ---------------------------------------------------------------- produção (grátis vs throughput)

    #[Test]
    public function bonus_de_producao_e_de_graca_numa_construcao_sem_insumo(): void
    {
        $a = $this->colonia('a', 1);
        $this->erguerPredio($a, 'mina_local', 1); // 15 metal_bruto/h (§19.2)
        $a->resources()->update(['amount' => 0]);
        $item = $this->item([], [['tipo_efeito' => EfeitosDaEndurance::PRODUCAO_BONUS, 'alvo' => 'mina_local', 'valor_bps' => 2000]]);
        $this->comprar($a, $item);

        $a->fresh()->update(['last_tick_at' => now()->subHour()]);
        app(ColonyTick::class)->handle($a->fresh(), now());

        // 15 × 1,2 = 18 — mais saída, sem consumir nada a mais (não há insumo).
        $this->assertSame(18, (int) $a->resources()->where('resource_type', 'metal_bruto')->value('amount'));
    }

    #[Test]
    public function bonus_de_producao_e_throughput_numa_construcao_de_conversao(): void
    {
        $a = $this->colonia('a', 1);
        $this->erguerPredio($a, 'industria_siderurgica', 1); // processa 15 metal_bruto/h (§19.2/D-82)
        $a->resources()->update(['amount' => 0]);
        $a->resources()->where('resource_type', 'metal_bruto')->update(['amount' => 3000]);
        $item = $this->item([], [['tipo_efeito' => EfeitosDaEndurance::PRODUCAO_BONUS, 'alvo' => 'industria_siderurgica', 'valor_bps' => 2000]]);
        $this->comprar($a, $item);

        // 15 × 1,2 = 18/h; em 400.000s processa 2.000 Metal Bruto = exatos 2 lotes de 1000.
        $a->fresh()->update(['last_tick_at' => now()->subSeconds(400_000)]);
        app(ColonyTick::class)->handle($a->fresh(), now());

        $this->assertSame(1000, (int) $a->resources()->where('resource_type', 'metal_bruto')->value('amount'));
        // 2 lotes: o dobro do que 1 lote sozinho daria (350) — throughput de verdade, não bônus de graça.
        $this->assertSame(700, (int) $a->resources()->where('resource_type', 'ligas_metalicas')->value('amount'));
        $this->assertSame(2, (int) $a->resources()->where('resource_type', 'tungstenio')->value('amount'));
    }

    // ---------------------------------------------------------------- veículo (velocidade e capacidade)

    #[Test]
    public function bonus_de_capacidade_aumenta_a_capacidade_efetiva_do_veiculo(): void
    {
        $a = $this->colonia('a', 1);
        $veiculo = $a->vehicles()->create([
            'type' => 'furgao_de_comercio', 'level' => 1, 'status' => 'ocioso',
            'capacity' => Vehicle::CAPACIDADE['furgao_de_comercio'],
        ]);
        $item = $this->item([], [['tipo_efeito' => EfeitosDaEndurance::CAPACIDADE_VEICULO, 'alvo' => 'todos', 'valor_bps' => 1000]]);
        $this->comprar($a, $item);

        $base = Vehicle::CAPACIDADE['furgao_de_comercio'];
        $esperada = intdiv($base * 11_000, 10_000); // +10%

        $this->assertSame($esperada, app(Conservacao::class)->capacidadeEfetiva($veiculo->fresh()));
    }

    #[Test]
    public function bonus_de_velocidade_reduz_a_duracao_do_trecho(): void
    {
        $a = $this->colonia('a', 1);
        $veiculo = $a->vehicles()->create([
            'type' => 'furgao_de_comercio', 'level' => 1, 'status' => 'ocioso',
            'capacity' => Vehicle::CAPACIDADE['furgao_de_comercio'],
        ]);
        $conservacao = app(Conservacao::class);
        $semBonus = $conservacao->segundosDoTrecho($veiculo, 40);

        $item = $this->item([], [['tipo_efeito' => EfeitosDaEndurance::VELOCIDADE_VEICULO, 'alvo' => 'todos', 'valor_bps' => 1000]]);
        $this->comprar($a, $item);

        $comBonus = $conservacao->segundosDoTrecho($veiculo->fresh(), 40);

        $this->assertLessThan($semBonus, $comBonus, 'o veículo com bônus chega mais rápido');
    }

    // ---------------------------------------------------------------- Drone (raio e bateria)

    private function colonoComOficina(): User
    {
        $user = User::factory()->create(['tutorial_completed_at' => now()]);
        $colony = app(CreateColony::class)->handle($user, 'Base', 20 + $this->proximoSlot++, 20);
        $colony->buildings()->create(['type' => 'oficina', 'level' => 1, 'slot' => 0]);

        foreach (['componentes_eletronicos' => 2000, 'compostos_quimicos' => 1000, 'metal_bruto' => 1000] as $r => $q) {
            $colony->resources()->where('resource_type', $r)->update(['amount' => $q]);
        }

        return $user->fresh();
    }

    private function zona(int $x, int $y, ?Colony $dona = null, int $robos = 0): NeutralZone
    {
        $z = $this->criarZonaComEstruturas([
            'x' => $x, 'y' => $y, 'district' => 'nordeste', 'mineral' => 'metal_bruto',
            'level' => 1, 'status' => $dona ? 'ocupada' : 'livre', 'deposit_level' => 1,
            'owner_colony_id' => $dona?->id, 'deposit_amount' => $dona ? 777 : 0,
        ]);

        for ($i = 0; $i < $robos; $i++) {
            \App\Models\Unit::create([
                'colony_id' => $dona->id, 'zone_id' => $z->id, 'type' => 'robo_minerador',
                'level' => 1, 'hp_bps' => \App\Models\Unit::INTEIRA, 'status' => 'na_zona',
            ]);
        }

        return $z;
    }

    #[Test]
    public function bonus_de_drone_raio_revela_uma_zona_fora_do_raio_base(): void
    {
        $eu = $this->colonoComOficina();
        $outro = $this->colonoComOficina();
        $alvo = $this->zona(50, 50, $outro->colony, robos: 7);
        // Raio base do Drone n1 é 6 (DroneSpecs); a 8 slots fica fora dele, mas dentro dos 9 do bônus de 50%.
        $vizinha = $this->zona(58, 50, $outro->colony, robos: 3);

        $item = $this->item([], [['tipo_efeito' => EfeitosDaEndurance::DRONE_RAIO, 'alvo' => null, 'valor_bps' => 5000]]);
        $this->comprar($eu->colony, $item);

        $drone = app(FabricarDrone::class)->handle($eu->colony->fresh(), 1);
        app(EnviarDrone::class)->handle($eu->colony->fresh(), $drone, $alvo, 'foto');
        $this->travelTo(now()->addSeconds(400));
        app(ConcluirMissoes::class)->handle(now());

        $zonas = collect($this->actingAs($eu)->getJson('/zones')->json('zones'));
        $this->assertSame(3, $zonas->firstWhere('id', $vizinha->id)['garrison'], 'só aparece com o bônus de raio (base 6 não alcançaria 8 slots)');
    }

    #[Test]
    public function bonus_de_drone_bateria_prolonga_a_vigilancia_alem_da_base(): void
    {
        $eu = $this->colonoComOficina();
        $outro = $this->colonoComOficina();
        $alvo = $this->zona(50, 50, $outro->colony, robos: 7);

        $item = $this->item([], [['tipo_efeito' => EfeitosDaEndurance::DRONE_BATERIA, 'alvo' => null, 'valor_bps' => 5000]]);
        $this->comprar($eu->colony, $item);

        $drone = app(FabricarDrone::class)->handle($eu->colony->fresh(), 1);
        app(EnviarDrone::class)->handle($eu->colony->fresh(), $drone, $alvo, 'vigilancia');
        $this->travelTo(now()->addSeconds(400));
        app(ConcluirMissoes::class)->handle(now());
        $this->assertSame('vigia', $drone->fresh()->leg);

        // Bateria base do n1: 24h (DroneSpecs). Com +50% seriam 36h — em 30h ainda deve estar vigiando.
        $this->travelTo(now()->addHours(30));
        app(ConcluirMissoes::class)->handle(now());
        $this->assertSame('vigia', $drone->fresh()->leg, 'com o bônus, 30h ainda não estourou a bateria (36h)');
    }

    // ---------------------------------------------------------------- a rota player-facing

    #[Test]
    public function a_rota_de_secao_lista_o_catalogo_com_o_estado_certo(): void
    {
        $a = $this->colonia('a', 20);
        $disponivel = $this->item(['secao' => 'baia_criogenica', 'item_key' => 'disp']);
        $bloqueado = $this->item(['secao' => 'baia_criogenica', 'item_key' => 'bloq', 'marco_minimo' => 90]);
        $possuido = $this->item(['secao' => 'baia_criogenica', 'item_key' => 'meu']);
        $this->comprar($a, $possuido);

        $resposta = $this->actingAs($a->user)->getJson('/endurance/secoes/baia_criogenica');
        $resposta->assertOk();

        $itens = collect($resposta->json('itens'))->keyBy('item_key');

        $this->assertSame('disponivel', $itens['disp']['estado']);
        $this->assertSame('bloqueado', $itens['bloq']['estado']);
        $this->assertSame(1, $itens['meu']['possuo']);
    }

    #[Test]
    public function a_rota_de_efeitos_publica_o_desconto_atual_e_os_tetos(): void
    {
        $a = $this->colonia('a', 1);
        $item = $this->item([], [['tipo_efeito' => EfeitosDaEndurance::DESCONTO_TRIBUTO, 'alvo' => null, 'valor_bps' => 500]]);
        $this->comprar($a, $item);

        $resposta = $this->actingAs($a->user)->getJson('/endurance/efeitos')->assertOk();

        $this->assertEquals(5.0, $resposta->json('desconto_tributo_pct'));
        $this->assertEquals(30.0, $resposta->json('teto_desconto_tributo_pct'));
    }
}
