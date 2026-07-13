<?php

namespace Tests\Feature;

use App\Domain\Colony\CreateColony;
use App\Domain\Drone\ConcluirMissoes;
use App\Domain\Drone\DroneSpecs;
use App\Domain\Drone\EnviarDrone;
use App\Domain\Drone\FabricarDrone;
use App\Domain\Transport\Conservacao;
use App\Domain\Transport\MercadoDeUsados;
use App\Exceptions\DomainRuleException;
use App\Models\Colony;
use App\Models\NeutralZone;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O Drone de Exploração e a névoa que lhe dá ofício (D-74; GDD §16.1, §21.4).
 *
 * O D-37 abriu o mapa sem névoa e o Drone ficou sem o que revelar. O D-74 pôs o segredo de volta —
 * **só no interior das zonas alheias** (guarnição e depósito) — e o Drone virou o olheiro: com a
 * guerra no ar, quem quer saber a força de defesa antes de gastar Sentinelas manda-o primeiro.
 */
class DroneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\ResourceTypeSeeder::class);
        $this->seed(\Database\Seeders\ComponentRecipeSeeder::class);
        $this->seed(\Database\Seeders\BuildingSpecSeeder::class);
        $this->seed(\Database\Seeders\TransportSettingSeeder::class);
    }

    private int $proximo = 0;

    /** Colônia em (20,20) com Oficina e os recursos do Drone. */
    private function colono(int $oficina = 1): User
    {
        $user = User::factory()->create(['tutorial_completed_at' => now()]);
        $colony = app(CreateColony::class)->handle($user, 'Base', 20 + $this->proximo++, 20);

        if ($oficina > 0) {
            $colony->buildings()->create(['type' => 'oficina', 'level' => $oficina, 'slot' => 0]);
        }

        foreach (['componentes_eletronicos' => 2000, 'compostos_quimicos' => 1000, 'metal_bruto' => 1000] as $r => $q) {
            $colony->resources()->where('resource_type', $r)->update(['amount' => $q]);
        }

        return $user->fresh();
    }

    private function zona(int $x, int $y, ?Colony $dona = null, int $robos = 0): NeutralZone
    {
        $z = NeutralZone::create([
            'x' => $x, 'y' => $y, 'district' => 'nordeste', 'mineral' => 'metal_bruto',
            'level' => 1, 'status' => $dona ? 'ocupada' : 'livre', 'deposit_level' => 1,
            'owner_colony_id' => $dona?->id, 'deposit_amount' => $dona ? 777 : 0,
        ]);

        for ($i = 0; $i < $robos; $i++) {
            Unit::create([
                'colony_id' => $dona->id, 'zone_id' => $z->id, 'type' => 'robo_minerador',
                'level' => 1, 'hp_bps' => Unit::INTEIRA, 'status' => 'na_zona',
            ]);
        }

        return $z;
    }

    private function drone(User $user, int $nivel = 1): Vehicle
    {
        return app(FabricarDrone::class)->handle($user->colony, $nivel);
    }

    // ---------------------------------------------------------------- a fábrica (Oficina, D-74)

    public function test_a_oficina_fabrica_o_drone_pelo_custo_publicado(): void
    {
        $user = $this->colono();
        $antes = $user->colony->resources()->where('resource_type', 'componentes_eletronicos')->value('amount');

        $drone = $this->drone($user);

        // §4.3 do v3.4 (a curva 1,65× — ver D-52): 50 Componentes + 15 Compostos + 4 Metal no nível 1.
        $this->assertSame($antes - 50, $user->colony->resources()->where('resource_type', 'componentes_eletronicos')->value('amount'));
        $this->assertSame(DroneSpecs::TIPO, $drone->type);
        $this->assertSame(0, (int) $drone->capacity, 'o Drone olha, não transporta');
        $this->assertNotNull($drone->plate, '§16.3: todo veículo civil tem placa');
        $this->assertFalse(app(Conservacao::class)->deprecia($drone), '§16.4: o Drone não deprecia');
    }

    public function test_sem_oficina_nao_ha_drone_e_o_nivel_dela_e_o_teto(): void
    {
        try {
            $this->drone($this->colono(oficina: 0));
            $this->fail('Fabricou sem Oficina.');
        } catch (DomainRuleException $e) {
            $this->assertSame('sem_oficina', $e->codigo);
        }

        try {
            $this->drone($this->colono(oficina: 1), nivel: 3);
            $this->fail('Fabricou acima do teto da Oficina.');
        } catch (DomainRuleException $e) {
            $this->assertSame('nivel_acima_da_oficina', $e->codigo);
        }
    }

    // ---------------------------------------------------------------- a névoa (D-74)

    public function test_o_interior_de_zona_alheia_e_nevoa(): void
    {
        $eu = $this->colono();
        $outro = $this->colono();
        $this->zona(50, 50, $outro->colony, robos: 7);

        $zonas = $this->actingAs($eu)->getJson('/zones')->assertOk()->json('zones');
        $alheia = collect($zonas)->firstWhere('x', 50);

        // Null, não zero: zero é um fato ("está indefesa"); null é não saber.
        $this->assertNull($alheia['garrison']);
        $this->assertNull($alheia['deposit_amount']);
        $this->assertSame('nenhuma', $alheia['intel']);

        // O público continua público: mineral, nível, dono — e os deriváveis do nível.
        $this->assertSame('metal_bruto', $alheia['mineral']);
        $this->assertNotNull($alheia['owner']);
    }

    public function test_o_dono_e_a_zona_livre_veem_tudo(): void
    {
        $eu = $this->colono();
        $this->zona(50, 50, $eu->colony, robos: 7);
        $this->zona(60, 60);   // livre: não tem interior a esconder

        $zonas = collect($this->actingAs($eu)->getJson('/zones')->json('zones'));

        $minha = $zonas->firstWhere('x', 50);
        $this->assertSame(7, $minha['garrison']);
        $this->assertSame('dona', $minha['intel']);

        $livre = $zonas->firstWhere('x', 60);
        $this->assertSame(0, $livre['garrison']);
        $this->assertSame('livre', $livre['intel']);
    }

    // ---------------------------------------------------------------- a missão FOTO (ida e volta)

    public function test_a_foto_revela_a_zona_e_as_vizinhas_no_raio_e_e_datada(): void
    {
        $eu = $this->colono();
        $outro = $this->colono();
        $alvo = $this->zona(50, 50, $outro->colony, robos: 7);
        $vizinha = $this->zona(54, 50, $outro->colony, robos: 3);   // a 4 slots: dentro do raio 6
        $longe = $this->zona(60, 50, $outro->colony, robos: 9);     // a 10 slots: fora

        $drone = $this->drone($eu);
        app(EnviarDrone::class)->handle($eu->colony->fresh(), $drone, $alvo, 'foto');

        // (20,20) → (50,50): 42 slots; a 8 slots/min, 315 s de voo (D-74).
        $this->travelTo(now()->addSeconds(316));
        app(ConcluirMissoes::class)->handle(now());

        $zonas = collect($this->actingAs($eu)->getJson('/zones')->json('zones'));

        $this->assertSame(7, $zonas->firstWhere('id', $alvo->id)['garrison']);
        $this->assertSame(777, $zonas->firstWhere('id', $alvo->id)['deposit_amount']);
        $this->assertSame('foto', $zonas->firstWhere('id', $alvo->id)['intel']);
        $this->assertNotNull($zonas->firstWhere('id', $alvo->id)['intel_em'], 'a foto é DATADA');

        $this->assertSame(3, $zonas->firstWhere('id', $vizinha->id)['garrison'], 'o raio revela a vizinha');
        $this->assertNull($zonas->firstWhere('id', $longe->id)['garrison'], 'fora do raio continua névoa');

        // E o drone dá meia-volta sozinho: ida e volta é o modo (§21.4).
        $this->assertSame('volta', $drone->fresh()->leg);

        $this->travelTo(now()->addSeconds(316));
        app(ConcluirMissoes::class)->handle(now());
        $this->assertSame('ocioso', $drone->fresh()->status, 'em casa, recarregado (recarga automática, §21.4)');
    }

    public function test_a_foto_envelhece_e_nao_mente_o_agora(): void
    {
        $eu = $this->colono();
        $outro = $this->colono();
        $alvo = $this->zona(50, 50, $outro->colony, robos: 7);

        $drone = $this->drone($eu);
        app(EnviarDrone::class)->handle($eu->colony->fresh(), $drone, $alvo, 'foto');
        $this->travelTo(now()->addSeconds(316));
        app(ConcluirMissoes::class)->handle(now());

        // Depois da foto, o dono reforça a zona: a guarnição real dobra.
        for ($i = 0; $i < 7; $i++) {
            Unit::create([
                'colony_id' => $outro->colony->id, 'zone_id' => $alvo->id, 'type' => 'robo_minerador',
                'level' => 1, 'hp_bps' => Unit::INTEIRA, 'status' => 'na_zona',
            ]);
        }

        $visto = collect($this->actingAs($eu)->getJson('/zones')->json('zones'))->firstWhere('id', $alvo->id);

        // A foto continua dizendo 7 — o que se viu, quando se viu. O real (14) exige nova passagem.
        $this->assertSame(7, $visto['garrison']);
        $this->assertSame('foto', $visto['intel']);
    }

    // ---------------------------------------------------------------- a VIGILÂNCIA (ida simples)

    public function test_a_vigilancia_transmite_ao_vivo_ate_a_bateria_acabar(): void
    {
        $eu = $this->colono();
        $outro = $this->colono();
        $alvo = $this->zona(50, 50, $outro->colony, robos: 7);

        $drone = $this->drone($eu);
        app(EnviarDrone::class)->handle($eu->colony->fresh(), $drone, $alvo, 'vigilancia');

        $this->travelTo(now()->addSeconds(316));
        app(ConcluirMissoes::class)->handle(now());
        $this->assertSame('vigia', $drone->fresh()->leg, 'chegou e FICOU: ida simples (§21.4)');

        // Ao vivo é ao vivo: a guarnição muda AGORA, e a tela vê o número novo — sem nova missão.
        Unit::create([
            'colony_id' => $outro->colony->id, 'zone_id' => $alvo->id, 'type' => 'robo_minerador',
            'level' => 1, 'hp_bps' => Unit::INTEIRA, 'status' => 'na_zona',
        ]);

        $visto = collect($this->actingAs($eu)->getJson('/zones')->json('zones'))->firstWhere('id', $alvo->id);
        $this->assertSame(8, $visto['garrison']);
        $this->assertSame('ao_vivo', $visto['intel']);

        // A bateria do nível 1 são 24 h PUBLICADAS (§21.4). Acabou: fotografa e volta sozinho.
        $this->travelTo(now()->addHours(25));
        app(ConcluirMissoes::class)->handle(now());
        $this->assertSame('volta', $drone->fresh()->leg);

        // E a vigilância que terminou vira FOTO — não esquecimento.
        $visto = collect($this->actingAs($eu)->getJson('/zones')->json('zones'))->firstWhere('id', $alvo->id);
        $this->assertSame('foto', $visto['intel']);
        $this->assertSame(8, $visto['garrison']);
    }

    // ---------------------------------------------------------------- as beiradas

    public function test_a_missao_nao_debita_energia_da_colonia(): void
    {
        $eu = $this->colono();
        $alvo = $this->zona(50, 50);
        $energia = $eu->colony->resources()->where('resource_type', 'energia')->value('amount');

        app(EnviarDrone::class)->handle($eu->colony->fresh(), $this->drone($eu), $alvo, 'foto');

        // §21.4: bateria própria, recarga automática. Cobrar kWh do estoque seria cobrar duas vezes.
        $this->assertSame($energia, $eu->colony->resources()->where('resource_type', 'energia')->value('amount'));
    }

    public function test_o_mercado_de_usados_recusa_o_drone(): void
    {
        // §16.1 o diz "vendável" — mas sem âncora ele reabriria a lavagem que o D-73 fechou.
        $eu = $this->colono();

        try {
            app(MercadoDeUsados::class)->anunciar($eu->colony->fresh(), $this->drone($eu), 5_000 * Colony::MICRO_POR_FERT);
            $this->fail('Anunciou um Drone sem teto de revenda.');
        } catch (DomainRuleException $e) {
            $this->assertSame('drone_sem_ancora_de_revenda', $e->codigo);
        }
    }

    public function test_a_maquina_de_carga_recusa_o_drone(): void
    {
        $eu = $this->colono();
        $drone = $this->drone($eu);

        // O Drone olha, não transporta — e o VeiculoSpecs não o conhece DE PROPÓSITO.
        $this->actingAs($eu)->postJson("/vehicles/{$drone->id}/dispatch", [
            'destination_type' => 'mercado_central',
            'cargo' => ['metal_bruto' => 1],
        ])->assertStatus(422);
    }

    public function test_pelo_endpoint_fabrica_e_envia(): void
    {
        $eu = $this->colono();
        $alvo = $this->zona(50, 50);

        $id = $this->actingAs($eu)->postJson('/drones', ['nivel' => 1])
            ->assertCreated()
            ->assertJsonPath('raio', 6)
            ->assertJsonPath('bateria_horas', 24)
            ->json('id');

        $this->actingAs($eu)->postJson("/drones/{$id}/mission", ['zone_id' => $alvo->id, 'modo' => 'vigilancia'])
            ->assertCreated()
            ->assertJsonPath('fase', 'ida')
            ->assertJsonPath('modo', 'vigilancia');
    }
}
