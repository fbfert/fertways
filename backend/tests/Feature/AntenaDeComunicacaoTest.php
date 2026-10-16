<?php

namespace Tests\Feature;

use App\Domain\Building\Funcoes;
use App\Domain\Colony\CreateColony;
use App\Domain\Eventos\Modificadores;
use App\Models\Colony;
use App\Models\GameEvent;
use App\Models\User;
use Database\Seeders\BuildingSpecSeeder;
use Database\Seeders\ComponentRecipeSeeder;
use Database\Seeders\ResourceTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A Antena de Comunicação avisa antes (D-271).
 *
 * O GDD promete *"comunicação com a Capital, alertas, eventos"* e nenhum número; até aqui ela só
 * consumia energia. A recomendação aprovada não inventa recurso nem bônus: a colônia com Antena fica
 * sabendo dos **eventos do mundo antes de eles começarem** — 12 horas por nível. Sem Antena, o evento
 * aparece quando começa, como sempre.
 */
class AntenaDeComunicacaoTest extends TestCase
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

    private function colonia(int $antena = 0): Colony
    {
        $c = app(CreateColony::class)->handle(User::factory()->create(), 'C', 10 + $this->proximo++ * 2, 20);
        if ($antena > 0) {
            $this->erguerPredio($c, 'antena_de_comunicacao', $antena);
        }

        return $c->fresh();
    }

    private function evento(int $comecaEmHoras, array $extra = []): GameEvent
    {
        return GameEvent::create(array_merge([
            'slug' => 'ev'.$this->proximo++,
            'nome' => 'Tempestade de Poeira',
            'mensagem_publica' => 'A produção vai cair.',
            'comeca_em' => now()->addHours($comecaEmHoras),
            'termina_em' => now()->addHours($comecaEmHoras + 24),
            'status' => 'ativo',
            'visibilidade' => 'anunciado',
            'modificador' => Modificadores::PRODUCAO,
            'efeito_bps' => -2_000,
        ], $extra));
    }

    private function eventos(Colony $c): array
    {
        return $this->actingAs($c->user)->getJson('/eventos')->assertOk()->json('eventos');
    }

    public function test_sem_antena_o_evento_so_aparece_quando_comeca(): void
    {
        $this->evento(6);

        $this->assertSame([], $this->eventos($this->colonia()));
    }

    public function test_com_antena_o_evento_aparece_antes_marcado_como_em_breve(): void
    {
        $this->evento(6);

        $e = $this->eventos($this->colonia(antena: 1));

        $this->assertCount(1, $e);
        $this->assertTrue($e[0]['em_breve']);
        $this->assertSame('Tempestade de Poeira', $e[0]['nome']);
        $this->assertNotNull($e[0]['comeca_em']);
    }

    /** 12 horas por nível: o nível 1 não vê um evento a 20 h; o nível 2 vê. */
    public function test_o_alcance_cresce_com_o_nivel(): void
    {
        $this->evento(20);

        $this->assertSame([], $this->eventos($this->colonia(antena: 1)));
        $this->assertCount(1, $this->eventos($this->colonia(antena: 2)));
    }

    /** A Antena não fura segredo: o secreto continua fora, e o parcial continua sem nome. */
    public function test_a_antena_respeita_a_visibilidade(): void
    {
        $this->evento(3, ['visibilidade' => 'secreto']);
        $this->evento(3, ['visibilidade' => 'parcial']);

        $e = $this->eventos($this->colonia(antena: 1));

        $this->assertCount(1, $e);
        $this->assertTrue($e[0]['parcial']);
        $this->assertTrue($e[0]['em_breve']);
        $this->assertArrayNotHasKey('nome', $e[0]);
    }

    /** O evento que já vale continua aparecendo para todos, como antes — e não é "em breve". */
    public function test_evento_vigente_nao_e_em_breve(): void
    {
        $this->evento(-1);

        $e = $this->eventos($this->colonia());
        $this->assertCount(1, $e);
        $this->assertFalse($e[0]['em_breve']);
    }

    public function test_o_catalogo_diz_o_que_a_antena_faz(): void
    {
        $f = Funcoes::paraJogador('antena_de_comunicacao');

        $this->assertSame('avisa', $f['efeito']);
        $this->assertStringContainsString('12 horas por nível', $f['resumo']);
    }
}
