<?php

namespace Tests\Feature;

use App\Domain\Avisos\Avisos;
use App\Domain\Colony\CreateColony;
use App\Models\Colony;
use App\Models\Combat;
use App\Models\NeutralZone;
use App\Models\User;
use Database\Seeders\BuildingOperatorRequirementSeeder;
use Database\Seeders\BuildingSpecSeeder;
use Database\Seeders\ResourceTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A faixa de avisos (A2.V2 — docs/decisoes.md D-211).
 *
 * ⚠️ O que estes testes guardam **não é a lista de avisos** — ela vai crescer. É a regra que decidiu
 * o desenho: *aviso não é tudo o que é verdade; é o que é verdade e raro.* Dois candidatos foram
 * cortados por dispararem para 28 e 19 das 29 colônias, e nada além de um teste impede que voltem.
 */
class AvisosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ResourceTypeSeeder::class);
        $this->seed(BuildingSpecSeeder::class);
        $this->seed(BuildingOperatorRequirementSeeder::class);
    }

    private function colono(int $x = 14, int $y = 14): User
    {
        $user = User::factory()->create();
        app(CreateColony::class)->handle($user, 'Teste', $x, $y);

        return $user->fresh();
    }

    /** @return list<string> os códigos dos avisos, na ordem em que vieram */
    private function codigos(Colony $colonia): array
    {
        $colonia = Colony::with(['buildings', 'resources'])->findOrFail($colonia->id);

        return array_column(app(Avisos::class)->paraColonia($colonia), 'codigo');
    }

    /**
     * ⚠️ A regra que decidiu o desenho, e a única que não pode mudar sem decisão nova.
     *
     * "População no teto" dispara para 28 das 29 colônias de produção, e "sem colonos livres" para
     * 19. Um aviso que quase todos veem sempre vira moldura — e ensina a ignorar a faixa inteira,
     * levando junto o aviso de cerco. Se alguém os acrescentar, é aqui que reprova.
     */
    public function test_a_faixa_nao_avisa_o_que_vale_para_quase_todo_mundo(): void
    {
        $user = $this->colono();
        $codigos = $this->codigos($user->colony);

        $this->assertNotContains('populacao_no_teto', $codigos);
        $this->assertNotContains('sem_colonos_livres', $codigos);
    }

    /** Sem nada a dizer, a faixa fica vazia — silêncio é estado válido, e a tela some. */
    public function test_colonia_ocupada_e_sem_problema_nao_gera_urgencia(): void
    {
        $user = $this->colono();

        // A fila é por `building_id`, e não por tipo — a colônia nasce com prédios, então há um.
        DB::table('build_queue')->insert([
            'colony_id' => $user->colony->id,
            'building_id' => $user->colony->buildings()->value('id'),
            'target_level' => 2,
            'position' => 1,
            'quoted_cost_json' => '{}',
            'enqueued_at' => now(),
            'finishes_at' => now()->addHour(),
            'status' => 'em_obra',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $severidades = array_column(
            app(Avisos::class)->paraColonia(Colony::with(['buildings', 'resources'])->find($user->colony->id)),
            'severidade',
        );

        $this->assertNotContains(Avisos::URGENTE, $severidades);
        $this->assertNotContains('fila_vazia', $this->codigos($user->colony->fresh()));
    }

    public function test_a_fila_parada_vira_oportunidade(): void
    {
        $user = $this->colono();

        $this->assertContains('fila_vazia', $this->codigos($user->colony));
    }

    /**
     * O cerco é urgente, e **vem primeiro** — a ordem da lista é a ordem de agir.
     *
     * Sem a ordenação, a vaga do Laboratório podia aparecer acima de uma zona sendo estrangulada.
     */
    public function test_o_cerco_e_urgente_e_encabeca_a_lista(): void
    {
        $user = $this->colono();

        NeutralZone::create([
            'x' => 47, 'y' => 47, 'district' => 'NE', 'mineral' => 'metal_bruto', 'level' => 1,
            'owner_colony_id' => $user->colony->id, 'status' => 'ocupada',
            'occupied_at' => now()->subDays(5), 'productive_at' => now()->subDays(4),
            'last_extraction_at' => now(), 'sieged_at' => now(),
        ]);

        $avisos = app(Avisos::class)->paraColonia(
            Colony::with(['buildings', 'resources'])->find($user->colony->id),
        );

        $this->assertSame('zona_cercada', $avisos[0]['codigo'], 'o urgente encabeça');
        $this->assertSame(Avisos::URGENTE, $avisos[0]['severidade']);
    }

    /** Estar sob ataque é urgente; atacar é apenas atenção — quem marcha escolheu marchar. */
    public function test_sob_ataque_e_urgente_e_atacar_nao(): void
    {
        $defensor = $this->colono(14, 14);
        $atacante = $this->colono(16, 16);

        Combat::create([
            'zone_id' => null,
            'attacker_colony_id' => $atacante->colony->id,
            'defender_colony_id' => $defensor->colony->id,
            'tipo' => 'invasao', 'status' => 'marchando', 'rodada' => 0,
            'chega_at' => now()->addHour(),
        ]);

        $doDefensor = app(Avisos::class)->paraColonia(
            Colony::with(['buildings', 'resources'])->find($defensor->colony->id),
        );
        $doAtacante = app(Avisos::class)->paraColonia(
            Colony::with(['buildings', 'resources'])->find($atacante->colony->id),
        );

        $this->assertSame('sob_ataque', $doDefensor[0]['codigo']);
        $this->assertSame(Avisos::URGENTE, $doDefensor[0]['severidade']);

        $atacar = collect($doAtacante)->firstWhere('codigo', 'atacando');
        $this->assertNotNull($atacar);
        $this->assertSame(Avisos::ATENCAO, $atacar['severidade']);
    }

    public function test_a_rota_responde_ao_dono_da_colonia(): void
    {
        $user = $this->colono();

        $this->actingAs($user)->getJson('/avisos')
            ->assertOk()
            ->assertJsonStructure(['avisos' => [['codigo', 'severidade', 'titulo', 'detalhe']]]);
    }
}
