<?php

namespace Tests\Feature;

use App\Domain\Colony\CreateColony;
use App\Models\Colony;
use App\Models\User;
use Database\Seeders\BuildingSpecSeeder;
use Database\Seeders\ComponentRecipeSeeder;
use Database\Seeders\ResourceTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `GET /colonies` — o diretório que faltava para o despacho entre colônias sair do papel.
 *
 * O despacho aceita `destination_type = colonia` e exige a PK do destino, mas nenhum endpoint
 * revelava o `id` de outra colônia. A UI, por isso, só oferecia o Mercado Central.
 *
 * O GDD é omisso sobre o diretório; listar todas as colônias é arbitragem (D-37), e o campo de
 * porte é uma soma arbitrada, não o "Marco" do GDD (D-38).
 */
class DiretorioDeColoniasTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // `CreateColony` semeia construções e recursos a partir das specs; sem elas não há
        // nível para somar nem colônia para fundar.
        $this->seed(ResourceTypeSeeder::class);
        $this->seed(ComponentRecipeSeeder::class);
        $this->seed(BuildingSpecSeeder::class);
    }

    private function colonia(string $nick, int $x, int $y): Colony
    {
        $user = User::factory()->create(['email' => "{$nick}@t.test", 'nickname' => $nick]);
        // O colono escolhe a célula (D-51). A ordenação do diretório é por distância entre
        // colônias, invariante por translação: mudar a Capital de lugar não altera a lista.
        $colony = app(CreateColony::class)->handle($user, "Colônia {$nick}", $x, $y);

        return $colony->fresh();
    }

    private function listar(Colony $como): array
    {
        return $this->actingAs($como->user)->getJson('/colonies')->assertOk()->json('colonies');
    }

    #[Test]
    public function lista_as_outras_colonias_e_omite_a_propria(): void
    {
        $eu = $this->colonia('eu', 10, 10);
        $this->colonia('vizinho', 13, 14);

        $lista = $this->listar($eu);

        $this->assertCount(1, $lista);
        $this->assertSame('vizinho', $lista[0]['nickname']);
        $this->assertSame('Colônia vizinho', $lista[0]['name']);
        $this->assertNotContains($eu->id, array_column($lista, 'id'));
    }

    /** 3-4-5: a distância euclidiana de (10,10) a (13,14) é exatamente 5. */
    #[Test]
    public function traz_coordenadas_e_a_distancia_ate_a_minha_colonia(): void
    {
        $eu = $this->colonia('eu', 10, 10);
        $this->colonia('vizinho', 13, 14);

        $vizinho = $this->listar($eu)[0];

        $this->assertSame(13, $vizinho['x']);
        $this->assertSame(14, $vizinho['y']);
        $this->assertSame(5, $vizinho['distance']);
    }

    #[Test]
    public function ordena_do_mais_perto_ao_mais_longe(): void
    {
        $eu = $this->colonia('eu', 10, 10);
        $this->colonia('longe', 40, 40);   // dist 42
        $this->colonia('perto', 12, 10);   // dist 2
        $this->colonia('medio', 20, 10);   // dist 10

        $this->assertSame(
            ['perto', 'medio', 'longe'],
            array_column($this->listar($eu), 'nickname'),
        );
    }

    /**
     * Duas colônias à mesma distância não podem trocar de lugar entre chamadas: a lista dançaria
     * sob o cursor do jogador. O `id` desempata.
     */
    #[Test]
    public function empate_de_distancia_desempata_pelo_id_e_nao_oscila(): void
    {
        $eu = $this->colonia('eu', 10, 30);
        $norte = $this->colonia('norte', 10, 20);   // dist 10
        $sul = $this->colonia('sul', 10, 40);       // dist 10

        $this->assertSame(10, $this->listar($eu)[0]['distance']);
        $this->assertSame(10, $this->listar($eu)[1]['distance']);

        $esperado = [$norte->id, $sul->id];
        sort($esperado);

        $this->assertSame($esperado, array_column($this->listar($eu), 'id'));
        $this->assertSame($esperado, array_column($this->listar($eu), 'id'));
    }

    /**
     * O porte é a soma dos níveis das construções — arbitrado (D-38), e explicitamente **não** o
     * "Marco" do GDD, que não tem fórmula publicada.
     *
     * A colônia nasce com as 16 construções do MVP em **nível 0** (`CreateColony`), então a soma
     * começa em zero para todo mundo. É o valor honesto: ninguém construiu nada ainda.
     */
    #[Test]
    public function o_porte_e_a_soma_dos_niveis_das_construcoes(): void
    {
        $eu = $this->colonia('eu', 10, 10);
        $vizinho = $this->colonia('vizinho', 20, 20);

        $this->assertSame(0, (int) $vizinho->buildings()->sum('level'));
        $this->assertSame(0, $this->listar($eu)[0]['building_levels_sum']);

        // Subir construções move o porte, e move exatamente o número de degraus subidos.
        $vizinho->buildings()->first()->increment('level', 3);
        $vizinho->buildings()->skip(1)->first()->increment('level', 2);

        $this->assertSame(5, $this->listar($eu)[0]['building_levels_sum']);
    }

    /** Escolher destino não é espionar. Saldo, recursos e frota do vizinho não saem daqui. */
    #[Test]
    public function nao_vaza_recursos_saldo_nem_frota_do_vizinho(): void
    {
        $eu = $this->colonia('eu', 10, 10);
        $this->colonia('vizinho', 20, 20);

        $vizinho = $this->listar($eu)[0];

        $this->assertSame(
            ['building_levels_sum', 'distance', 'id', 'name', 'nickname', 'x', 'y'],
            collect(array_keys($vizinho))->sort()->values()->all(),
        );
    }

    #[Test]
    public function o_id_listado_serve_de_destino_para_o_despacho(): void
    {
        $eu = $this->colonia('eu', 10, 10);
        $vizinho = $this->colonia('vizinho', 40, 10);

        // O ponto do diretório: o `id` que ele publica é o que o despacho pede.
        $this->assertSame($vizinho->id, $this->listar($eu)[0]['id']);

        $furgao = $eu->vehicles()->where('type', 'furgao_de_comercio')->firstOrFail();
        $eu->resources()->where('resource_type', 'metal_bruto')->update(['amount' => 1_000]);
        $eu->resources()->where('resource_type', 'energia')->update(['amount' => 100]);

        $this->actingAs($eu->user)
            ->postJson("/vehicles/{$furgao->id}/dispatch", [
                'destination_type' => 'colonia',
                'destination_id' => $this->listar($eu)[0]['id'],
                'cargo' => ['metal_bruto' => 400],
            ])
            ->assertSuccessful();
    }

    #[Test]
    public function quem_ainda_nao_fundou_colonia_recebe_404(): void
    {
        $this->colonia('vizinho', 20, 20);
        $semColonia = User::factory()->create(['email' => 'sem@t.test', 'nickname' => 'sem']);

        // Sem colônia não há de onde medir distância — e não há veículo para despachar.
        $this->actingAs($semColonia)->getJson('/colonies')->assertNotFound();
    }

    #[Test]
    public function o_diretorio_exige_autenticacao(): void
    {
        $this->getJson('/colonies')->assertUnauthorized();
    }

    #[Test]
    public function o_indice_da_api_anuncia_o_diretorio(): void
    {
        $this->assertContains('GET /colonies (autenticado)', $this->getJson('/')->json('endpoints'));
    }

    /** Uma colônia recém-fundada não tem vizinhos; a lista vazia é resposta, não erro. */
    #[Test]
    public function colono_sozinho_no_servidor_recebe_lista_vazia(): void
    {
        $this->assertSame([], $this->listar($this->colonia('eu', 10, 10)));
    }

    /**
     * A tela do mapa precisa saber onde é a Capital e qual o lado da grade. Esses números vêm da
     * API e não de constantes no frontend, porque a geometria vai mudar (D-51) e um número copiado
     * no React sobreviveria à mudança mentindo.
     */
    #[Test]
    public function o_diretorio_publica_a_geometria_do_mapa_e_a_propria_colonia(): void
    {
        $eu = $this->colonia('eu', 10, 10);

        $r = $this->actingAs($eu->user)->getJson('/colonies')->assertOk();

        $r->assertJsonPath('side', \App\Domain\Logistics\MapaFertways::LADO)
            ->assertJsonPath('capital.x', \App\Domain\Logistics\MapaFertways::CAPITAL_X)
            ->assertJsonPath('capital.y', \App\Domain\Logistics\MapaFertways::CAPITAL_Y)
            ->assertJsonPath('me.id', $eu->id)
            ->assertJsonPath('me.x', 10)
            ->assertJsonPath('me.y', 10);
    }
}
