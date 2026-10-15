<?php

namespace Tests\Feature;

use App\Domain\Colony\CreateColony;
use App\Domain\Guerra\Atacar;
use App\Domain\GuerraFederativa\AtacarColonia;
use App\Domain\GuerraFederativa\DeclararGuerra;
use App\Domain\Populacao\Populacao;
use App\Exceptions\DomainRuleException;
use App\Models\Colony;
use App\Models\Federation;
use App\Models\NeutralZone;
use App\Models\Unit;
use App\Models\User;
use App\Models\WarSetting;
use Database\Seeders\BuildingSpecSeeder;
use Database\Seeders\ComponentRecipeSeeder;
use Database\Seeders\ResourceTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ErgueEstruturasDaZona;
use Tests\TestCase;

/**
 * Decisão 7 da guerra federativa: a tropa consome operadores da colônia (D-260).
 *
 * *"Atacar consome operadores da colônia (a tropa sai de algum lugar), e a população passou a ser
 * recurso escasso. Uma federação em guerra que esvazie as zonas de operadores vê a própria extração
 * degradar — e isso é bom: a guerra deve custar economia, não só Nióbio."* (GDD_GUERRA_FEDERATIVA §6)
 */
class TropaConsomeOperadoresTest extends TestCase
{
    use ErgueEstruturasDaZona;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ResourceTypeSeeder::class);
        $this->seed(ComponentRecipeSeeder::class);
        $this->seed(BuildingSpecSeeder::class);
        DB::table('population_settings')->where('id', 1)->update(['ativo' => true]);
    }

    private int $proximo = 0;

    /** Uma colônia com exatamente `$livres` colonos sem compromisso. */
    private function colonia(int $livres): Colony
    {
        $c = app(CreateColony::class)->handle(User::factory()->create(), 'C', 20 + $this->proximo++ * 3, 20);
        $c->update([
            'fert_micro' => 10_000 * 1_000_000,
            'populacao' => app(Populacao::class)->alocadaEmConstrucoes($c->fresh()) + $livres,
        ]);

        return $c->fresh();
    }

    /** @return list<int> */
    private function sentinelas(Colony $c, int $n): array
    {
        $ids = [];
        for ($i = 0; $i < $n; $i++) {
            $ids[] = Unit::create([
                'colony_id' => $c->id, 'type' => 'sentinela', 'level' => 1, 'status' => 'casa', 'hp_bps' => 10_000,
            ])->id;
        }

        return $ids;
    }

    private function zonaDe(Colony $dono): NeutralZone
    {
        return $this->criarZonaComEstruturas([
            'x' => 47, 'y' => 47, 'district' => 'NE', 'mineral' => 'metal_bruto', 'level' => 1,
            'owner_colony_id' => $dono->id, 'status' => 'ocupada',
            'occupied_at' => now()->subDays(30), 'protected_until' => now()->subDays(20),
            'command_post_level' => 1, 'productive_at' => now()->subDays(20),
            'deposit_level' => 1, 'deposit_amount' => 0, 'last_extraction_at' => now(),
        ])->fresh();
    }

    // ── a conta ─────────────────────────────────────────────────────────────

    public function test_a_tropa_em_campo_conta_como_colonos_alocados(): void
    {
        $c = $this->colonia(10);
        $antes = app(Populacao::class)->estado($c);

        Unit::whereIn('id', $this->sentinelas($c, 3))->update(['status' => 'marchando']);
        $depois = app(Populacao::class)->estado($c->fresh());

        $this->assertSame(0, $antes['em_campanha']);
        $this->assertSame(3, $depois['em_campanha']);
        $this->assertSame($antes['disponivel'] - 3, $depois['disponivel']);
    }

    /** Guarnição de zona não é campanha: a tropa que chegou e ficou não conta mais na colônia. */
    public function test_a_tropa_em_casa_ou_guarnecendo_nao_conta(): void
    {
        $c = $this->colonia(10);
        $this->sentinelas($c, 2);
        Unit::whereIn('id', $this->sentinelas($c, 2))->update(['status' => 'na_zona']);

        $this->assertSame(0, app(Populacao::class)->estado($c->fresh())['em_campanha']);
    }

    // ── o despacho ──────────────────────────────────────────────────────────

    public function test_atacar_zona_exige_colonos_livres_para_a_tropa(): void
    {
        $atacante = $this->colonia(2);
        $zona = $this->zonaDe($this->colonia(0));

        try {
            app(Atacar::class)->handle($atacante, $zona, 'invasao', $this->sentinelas($atacante, 3));
            $this->fail('três sentinelas com dois colonos livres');
        } catch (DomainRuleException $e) {
            $this->assertSame('sem_colonos_para_a_tropa', $e->codigo);
        }

        $combate = app(Atacar::class)->handle($atacante->fresh(), $zona, 'invasao', $this->sentinelas($atacante, 2));
        $this->assertSame('marchando', $combate->status);
    }

    public function test_o_cerco_de_colonia_tambem_exige(): void
    {
        [$a, $b] = $this->emGuerra(livresDoAtacante: 1);

        $this->expectException(DomainRuleException::class);
        app(AtacarColonia::class)->handle($a, $b, $this->sentinelas($a, 2));
    }

    /** Sem a mecânica de população no ar, ninguém pode ser cobrado por uma equipe que o jogo não pede. */
    public function test_sem_a_populacao_ligada_nao_ha_exigencia(): void
    {
        DB::table('population_settings')->where('id', 1)->update(['ativo' => false]);
        $atacante = $this->colonia(0);
        $zona = $this->zonaDe($this->colonia(0));

        $combate = app(Atacar::class)->handle($atacante, $zona, 'invasao', $this->sentinelas($atacante, 3));

        $this->assertSame('marchando', $combate->status);
    }

    /** A proporção é do operador; zero desliga a regra sem deploy. */
    public function test_a_proporcao_e_do_operador_e_zero_desliga(): void
    {
        WarSetting::singleton()->update(['operadores_por_unidade' => 0]);
        $atacante = $this->colonia(0);
        $zona = $this->zonaDe($this->colonia(0));

        $combate = app(Atacar::class)->handle($atacante, $zona, 'invasao', $this->sentinelas($atacante, 3));

        $this->assertSame('marchando', $combate->status);
        $this->assertSame(0, app(Populacao::class)->estado($atacante->fresh())['em_campanha']);
    }

    /** @return array{0:Colony,1:Colony} */
    private function emGuerra(int $livresDoAtacante): array
    {
        $cfg = WarSetting::singleton();
        $colonias = [];

        foreach ([$livresDoAtacante, 0] as $livres) {
            $c = $this->colonia($livres);
            $f = Federation::create(['name' => 'F'.$this->proximo++, 'fert_micro' => (int) $cfg->federativa_custo_fert_micro * 5]);
            DB::table('federation_holdings')->insert([
                'federation_id' => $f->id, 'resource_type' => 'niobio_alienigena',
                'amount' => (int) $cfg->federativa_custo_niobio * 5, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $c->update(['federation_id' => $f->id, 'federation_role' => Federation::LIDER]);
            $colonias[] = $c->fresh();
        }

        [$a, $b] = $colonias;
        $a->buildings()->firstOrCreate(['type' => 'quartel'], ['level' => 1]);
        app(DeclararGuerra::class)->handle($a, Federation::find($b->federation_id));

        return [$a->fresh(), $b->fresh()];
    }
}
