<?php

namespace Tests\Feature;

use App\Domain\Colony\Silo;
use App\Domain\Guerra\ResolverCombates;
use App\Domain\GuerraFederativa\AtacarColonia;
use App\Domain\GuerraFederativa\DeclararGuerra;
use App\Domain\GuerraFederativa\ResolverCercoDeColonia;
use App\Domain\Telemetria\RegistrarEvento;
use App\Exceptions\DomainRuleException;
use App\Models\Colony;
use App\Models\Federation;
use App\Models\Unit;
use App\Models\User;
use App\Models\WarSetting;
use Database\Seeders\ResourceTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Cerco de colônia (A2.10, decisões 13, 14 e 15).
 *
 * ⚠️ Esta fatia **revoga o §01**, que declara o slot principal inviolável. Os testes guardam os dois
 * lados da revogação: dentro de guerra a colônia é alvo, **fora dela continua intocável**.
 */
class CercoDeColoniaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ResourceTypeSeeder::class);
    }

    private int $proximo = 0;

    /** @return array{0:Federation,1:Colony} */
    private function federacao(): array
    {
        $f = Federation::create(['name' => 'Fed'.$this->proximo++]);

        $u = User::create([
            'name' => 'c', 'nickname' => 'k'.$this->proximo,
            'email' => 'k'.$this->proximo++.'@t.test', 'password' => Hash::make('x'),
        ]);

        $c = Colony::create([
            'user_id' => $u->id, 'name' => 'C', 'x' => 0, 'y' => $this->proximo,
            'federation_id' => $f->id, 'federation_role' => Federation::LIDER,
        ]);

        $cfg = WarSetting::singleton();
        $f->update(['fert_micro' => (int) $cfg->federativa_custo_fert_micro * 5]);
        DB::table('federation_holdings')->insert([
            'federation_id' => $f->id, 'resource_type' => 'niobio_alienigena',
            'amount' => (int) $cfg->federativa_custo_niobio * 5,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$f->fresh(), $c->fresh()];
    }

    private function comQuartel(Colony $c): Colony
    {
        $c->buildings()->create(['type' => 'quartel', 'level' => 1]);

        return $c->fresh();
    }

    /** @return list<int> */
    private function tropa(Colony $c, int $quantas = 3): array
    {
        $ids = [];

        for ($n = 0; $n < $quantas; $n++) {
            $ids[] = Unit::create([
                'colony_id' => $c->id, 'type' => 'predador', 'level' => 1,
                'status' => 'ociosa', 'hp_bps' => 10_000,
            ])->id;
        }

        return $ids;
    }

    private function comEstoque(Colony $c, int $quanto): Colony
    {
        $c->resources()->updateOrCreate(['resource_type' => 'agua'], ['amount' => $quanto]);

        return $c->fresh();
    }

    private function guerra(Colony $a, Colony $b): void
    {
        app(DeclararGuerra::class)->handle($a, Federation::find($b->federation_id));
    }

    // ─────────────────────────── ⚠️ os dois lados da revogação do §01

    /** Fora de guerra, a colônia continua INVIOLÁVEL — o §01 só cai dentro dela. */
    public function test_fora_de_guerra_a_colonia_e_inviolavel(): void
    {
        [, $a] = $this->federacao();
        [, $b] = $this->federacao();
        $this->comQuartel($a);

        $this->expectException(DomainRuleException::class);
        app(AtacarColonia::class)->handle($a, $b, $this->tropa($a));
    }

    public function test_em_guerra_a_colonia_pode_ser_atacada(): void
    {
        [, $a] = $this->federacao();
        [, $b] = $this->federacao();
        $this->comQuartel($a);
        $this->guerra($a, $b);

        $combate = app(AtacarColonia::class)->handle($a->fresh(), $b, $this->tropa($a));

        $this->assertNull($combate->zone_id, 'zone_id nulo é o que diz "o alvo é colônia"');
        $this->assertSame($b->id, (int) $combate->defender_colony_id);
    }

    /** Decisão 15: marchar sobre colônia exige Quartel. */
    public function test_sem_quartel_nao_marcha(): void
    {
        [, $a] = $this->federacao();
        [, $b] = $this->federacao();
        $this->guerra($a, $b);

        $this->expectException(DomainRuleException::class);
        app(AtacarColonia::class)->handle($a->fresh(), $b, $this->tropa($a));
    }

    public function test_nao_ha_dois_cercos_sobre_a_mesma_colonia(): void
    {
        [, $a] = $this->federacao();
        [, $b] = $this->federacao();
        $this->comQuartel($a);
        $this->guerra($a, $b);
        app(AtacarColonia::class)->handle($a->fresh(), $b, $this->tropa($a));

        $this->expectException(DomainRuleException::class);
        app(AtacarColonia::class)->handle($a->fresh(), $b, $this->tropa($a));
    }

    // ─────────────────────────── ⚠️ o saque: só o excedente

    /**
     * ⚠️ **A linha que a decisão 2 traçou:** leva o excedente, e o protegido **nunca é tocado**.
     *
     * É o que separa "a colônia é alvo" de "a colônia é destruída".
     */
    public function test_o_saque_leva_o_excedente_e_poupa_o_protegido(): void
    {
        [, $a] = $this->federacao();
        [, $b] = $this->federacao();
        $this->comQuartel($a);
        $this->guerra($a, $b);

        $b->buildings()->create(['type' => 'deposito_local', 'level' => 1]);
        $b = $this->comEstoque($b->fresh(), 200_000);
        $protegido = app(Silo::class)->capacidade($b, 'agua');

        // Sem defensores: o cerco vence na chegada.
        $combate = app(AtacarColonia::class)->handle($a->fresh(), $b, $this->tropa($a));
        $combate->update(['proxima_rodada_at' => now()->subMinute()]);

        app(ResolverCercoDeColonia::class)->handle(now());

        $restou = (int) $b->fresh()->resources()->where('resource_type', 'agua')->value('amount');

        $this->assertLessThan(200_000, $restou, 'o saque levou alguma coisa');
        $this->assertGreaterThanOrEqual($protegido, $restou, 'e nunca abaixo do protegido');
    }

    /**
     * ⚠️ **A Torre de Defesa finalmente vale alguma coisa** (decisão 14).
     *
     * Onze colônias já a construíram em produção, defendendo o que ninguém podia atacar. Aqui ela
     * reduz o espólio — e é o terceiro prédio inerte que esta Alpha ressuscita.
     */
    public function test_a_torre_de_defesa_reduz_o_saque(): void
    {
        $levados = [];

        foreach ([0, 5] as $nivelTorre) {
            [, $a] = $this->federacao();
            [, $b] = $this->federacao();
            $this->comQuartel($a);
            $this->guerra($a, $b);

            $b->buildings()->create(['type' => 'deposito_local', 'level' => 1]);

            if ($nivelTorre > 0) {
                $b->buildings()->create(['type' => 'torre_de_defesa', 'level' => $nivelTorre]);
            }

            $b = $this->comEstoque($b->fresh(), 200_000);

            $combate = app(AtacarColonia::class)->handle($a->fresh(), $b, $this->tropa($a));
            $combate->update(['proxima_rodada_at' => now()->subMinute()]);
            app(ResolverCercoDeColonia::class)->handle(now());

            $levados[$nivelTorre] = 200_000
                - (int) $b->fresh()->resources()->where('resource_type', 'agua')->value('amount');
        }

        $this->assertGreaterThan(0, $levados[0], 'sem Torre, o saque leva');
        $this->assertLessThan($levados[0], $levados[5], 'com Torre, leva menos');
    }

    /** ⚠️ E nunca zera: sem teto, uma Torre alta desligaria a guerra sozinha. */
    public function test_a_torre_nunca_zera_o_saque(): void
    {
        [, $a] = $this->federacao();
        [, $b] = $this->federacao();
        $this->comQuartel($a);
        $this->guerra($a, $b);

        $b->buildings()->create(['type' => 'deposito_local', 'level' => 1]);
        $b->buildings()->create(['type' => 'torre_de_defesa', 'level' => 99]);
        $b = $this->comEstoque($b->fresh(), 200_000);

        $combate = app(AtacarColonia::class)->handle($a->fresh(), $b, $this->tropa($a));
        $combate->update(['proxima_rodada_at' => now()->subMinute()]);
        app(ResolverCercoDeColonia::class)->handle(now());

        $this->assertLessThan(
            200_000,
            (int) $b->fresh()->resources()->where('resource_type', 'agua')->value('amount'),
            'mesmo com Torre absurda, o saque leva alguma coisa',
        );
    }

    /** O espólio ENTRA no atacante: saque é transferência, não destruição. */
    public function test_o_saque_entra_no_atacante(): void
    {
        [, $a] = $this->federacao();
        [, $b] = $this->federacao();
        $this->comQuartel($a);
        $this->guerra($a, $b);

        $b->buildings()->create(['type' => 'deposito_local', 'level' => 1]);
        $b = $this->comEstoque($b->fresh(), 200_000);
        $a = $this->comEstoque($a->fresh(), 0);

        $combate = app(AtacarColonia::class)->handle($a->fresh(), $b, $this->tropa($a));
        $combate->update(['proxima_rodada_at' => now()->subMinute()]);
        app(ResolverCercoDeColonia::class)->handle(now());

        $this->assertGreaterThan(
            0,
            (int) $a->fresh()->resources()->where('resource_type', 'agua')->value('amount'),
            'o que saiu de um entrou no outro',
        );
    }

    /** ⚠️ A telemetria sobe JUNTO, e carrega se o defensor estava ausente. */
    public function test_o_saque_registra_telemetria_com_ausencia_do_defensor(): void
    {
        [, $a] = $this->federacao();
        [, $b] = $this->federacao();
        $this->comQuartel($a);
        $this->guerra($a, $b);

        $b->buildings()->create(['type' => 'deposito_local', 'level' => 1]);
        $b = $this->comEstoque($b->fresh(), 200_000);

        $combate = app(AtacarColonia::class)->handle($a->fresh(), $b, $this->tropa($a));
        $combate->update(['proxima_rodada_at' => now()->subMinute()]);
        app(ResolverCercoDeColonia::class)->handle(now());
        app(RegistrarEvento::class)->descarregar();

        $this->assertDatabaseHas('telemetry_events', ['type' => 'colonia_saqueada']);
    }

    // ─────────────────────────── ⚠️ a porta: rota que ninguém alcança é peça inerte

    /**
     * ⚠️ A lista de inimigos só traz quem está DE FACTO em guerra.
     *
     * A tela não deve oferecer o que a regra recusaria — e sem esta rota, atacar exigiria adivinhar
     * o id de uma colônia alheia.
     */
    public function test_a_lista_de_inimigos_so_traz_quem_esta_em_guerra(): void
    {
        [, $a] = $this->federacao();
        [, $b] = $this->federacao();
        [, $neutro] = $this->federacao();
        $this->comQuartel($a);

        $antes = $this->actingAs($a->user)->getJson('/war/inimigos')->assertOk()->json();
        $this->assertSame([], $antes['inimigos'], 'sem guerra, ninguém é alvo');

        $this->guerra($a, $b);

        $depois = $this->actingAs($a->user)->getJson('/war/inimigos')->assertOk()->json();
        $ids = array_column($depois['inimigos'], 'id');

        $this->assertContains($b->id, $ids, 'o inimigo aparece');
        $this->assertNotContains($neutro->id, $ids, 'quem não está em guerra, não');
        $this->assertTrue($depois['tem_quartel']);
    }

    /** E ela diz o que está EM RISCO: marchar sem saber o que se ganha é aposta, não decisão. */
    public function test_a_lista_mostra_o_exposto_do_alvo(): void
    {
        [, $a] = $this->federacao();
        [, $b] = $this->federacao();
        $this->comQuartel($a);
        $this->guerra($a, $b);

        $b->buildings()->create(['type' => 'deposito_local', 'level' => 1]);
        $b->buildings()->create(['type' => 'torre_de_defesa', 'level' => 3]);
        $this->comEstoque($b->fresh(), 200_000);

        $linha = collect($this->actingAs($a->user)->getJson('/war/inimigos')->json()['inimigos'])
            ->firstWhere('id', $b->id);

        $this->assertGreaterThan(0, $linha['exposto'], 'o excedente aparece');
        $this->assertSame(3, $linha['torre'], 'e a Torre do alvo também');
    }

    public function test_a_rota_de_ataque_a_colonia_funciona(): void
    {
        [, $a] = $this->federacao();
        [, $b] = $this->federacao();
        $this->comQuartel($a);
        $this->guerra($a, $b);

        $this->actingAs($a->user)
            ->postJson('/war/attack-colony', ['colony_id' => $b->id, 'unit_ids' => $this->tropa($a)])
            ->assertOk();

        $this->assertDatabaseHas('combats', ['defender_colony_id' => $b->id, 'zone_id' => null]);
    }

    /** O resolvedor de ZONA não pode topar com um cerco de colônia — ele quebraria no `$zona` nulo. */
    public function test_o_resolvedor_de_zona_ignora_cerco_de_colonia(): void
    {
        [, $a] = $this->federacao();
        [, $b] = $this->federacao();
        $this->comQuartel($a);
        $this->guerra($a, $b);

        $combate = app(AtacarColonia::class)->handle($a->fresh(), $b, $this->tropa($a));
        $combate->update(['proxima_rodada_at' => now()->subMinute()]);

        // Não pode explodir, e não pode tocar no combate de colônia.
        app(ResolverCombates::class)->handle(now());

        $this->assertSame('marchando', $combate->fresh()->status);
    }
}
