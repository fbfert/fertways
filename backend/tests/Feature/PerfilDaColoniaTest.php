<?php

namespace Tests\Feature;

use App\Domain\Especializacao\Perfil;
use App\Models\Colony;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * O perfil derivado da colônia (A2.4).
 *
 * O que estes testes guardam é a regra do GDD ALPHA 2 §8.1: **especialização é calculada, nunca
 * declarada**. E a contrapartida obrigatória daquela regra — ela precisa ser exibida, com o que a
 * colônia ganha e do que passa a depender.
 */
class PerfilDaColoniaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\ResourceTypeSeeder::class);
        $this->seed(\Database\Seeders\BuildingSpecSeeder::class);
    }

    private int $proximo = 0;

    private function colonia(array $predios): Colony
    {
        $u = User::create([
            'name' => 'c', 'nickname' => 'e'.$this->proximo,
            'email' => 'e'.$this->proximo.'@t.test', 'password' => Hash::make('x'),
        ]);

        $c = Colony::create(['user_id' => $u->id, 'name' => 'C', 'x' => 0, 'y' => $this->proximo++]);

        foreach ($predios as [$tipo, $nivel]) {
            $c->buildings()->create(['type' => $tipo, 'level' => $nivel]);
        }

        return $c->fresh(['buildings']);
    }

    // ────────────────────────────────────────────── a regra do §8.1

    /**
     * **Não existe rota de escrita, e isso é a regra e não uma omissão.**
     *
     * O §8.1 proíbe escolha declarada de perfil. Um POST aqui seria a segunda camada que aquela
     * regra existe para impedir, e traria junto respec, custo de troca e a troca oportunista na
     * véspera de cada evento.
     */
    public function test_nao_existe_como_declarar_perfil(): void
    {
        $rotas = collect(app('router')->getRoutes())
            ->filter(fn ($r) => str_contains($r->uri(), 'perfil-da-colonia'))
            ->flatMap(fn ($r) => $r->methods())
            ->unique()->values()->all();

        $this->assertSame(['GET', 'HEAD'], $rotas, 'o perfil é leitura derivada, nunca escrita');
    }

    public function test_o_perfil_exige_autenticacao(): void
    {
        $this->getJson('/perfil-da-colonia')->assertStatus(401);
    }

    // ────────────────────────────────────────────── a vocação

    /**
     * A vocação sai do VALOR produzido, não da quantidade.
     *
     * Quantidade enganaria: o Reator faz 506 energia/h e a Mina 51 metal/h, mas o metal vale dez
     * vezes mais por unidade. Uma colônia não é "energética" por produzir muitos números.
     */
    public function test_a_vocacao_sai_do_valor_e_nao_da_quantidade(): void
    {
        $c = $this->colonia([['reator_de_energia', 4], ['mina_local', 4]]);

        $p = app(Perfil::class)->de($c);

        $this->assertSame('metal_bruto', $p['vocacao']);
        $this->assertGreaterThan(
            $p['producao']['metal_bruto'],
            $p['producao']['energia'],
            'a energia é produzida em MAIOR quantidade, e ainda assim não é a vocação',
        );
    }

    /** Colônia sem produtor nenhum não tem vocação — e dizer isso é melhor do que inventar uma. */
    public function test_sem_producao_nao_ha_vocacao(): void
    {
        $p = app(Perfil::class)->de($this->colonia([['laboratorio', 1]]));

        $this->assertNull($p['vocacao']);
        $this->assertSame(0, $p['forca_pct']);
    }

    /** A força separa especialista de generalista sem inventar um limiar de perfil. */
    public function test_a_forca_mede_o_quanto_o_principal_domina(): void
    {
        $especialista = app(Perfil::class)->de($this->colonia([['mina_local', 4]]));
        $generalista = app(Perfil::class)->de($this->colonia([
            ['mina_local', 4], ['fazenda', 4], ['captacao_de_agua', 4], ['reator_de_energia', 4],
        ]));

        $this->assertSame(100, $especialista['forca_pct']);
        $this->assertLessThan($especialista['forca_pct'], $generalista['forca_pct']);
    }

    // ────────────────────────────────────────────── a dependência

    /**
     * A dependência estrutural que o critério de saída da A2.4 pede.
     *
     * A Oficina precisa dos minerais eletrônicos, que o **Governo monopoliza** — nenhuma colônia os
     * produz, nem a mais especializada. É dependência por desenho, não por falta de investimento.
     */
    public function test_a_oficina_depende_de_mineral_que_ninguem_produz(): void
    {
        $p = app(Perfil::class)->de($this->colonia([['oficina', 4]]));

        $this->assertSame('componentes_eletronicos', $p['vocacao']);

        foreach (['estanho', 'cobre', 'silicio', 'aluminio'] as $mineral) {
            $this->assertContains($mineral, $p['depende_de']);
        }
    }

    /** Quem converte depende do que converte: a Refinaria precisa de metal, água e biomassa. */
    public function test_quem_converte_depende_do_insumo_que_nao_produz(): void
    {
        $p = app(Perfil::class)->de($this->colonia([['refinaria_quimica', 4]]));

        $this->assertContains('metal_bruto', $p['depende_de']);
        $this->assertContains('agua', $p['depende_de']);
        $this->assertContains('biomassa', $p['depende_de']);
    }

    /** E deixa de depender do que passa a produzir — a dependência é calculada, não fixa. */
    public function test_produzir_o_proprio_insumo_tira_a_dependencia(): void
    {
        $so = app(Perfil::class)->de($this->colonia([['refinaria_quimica', 4]]));
        $com = app(Perfil::class)->de($this->colonia([['refinaria_quimica', 4], ['mina_local', 4]]));

        $this->assertContains('metal_bruto', $so['depende_de']);
        $this->assertNotContains('metal_bruto', $com['depende_de']);
    }

    /** Energia é debitada por toda construção erguida, produza ela o que produzir. */
    public function test_todo_mundo_depende_de_energia_ate_produzi_la(): void
    {
        $sem = app(Perfil::class)->de($this->colonia([['mina_local', 1]]));
        $com = app(Perfil::class)->de($this->colonia([['mina_local', 1], ['reator_de_energia', 1]]));

        $this->assertContains('energia', $sem['depende_de']);
        $this->assertNotContains('energia', $com['depende_de']);
    }

    // ────────────────────────────────────────────── a auditoria da fase

    /**
     * A especialização "já existente" que a A2.4 mandava auditar: as construções REPETÍVEIS.
     *
     * O comentário de `Building::REPETIVEIS` já dizia, desde o D-59, que "repetir é estratégia
     * econômica (especializar a colônia em metal, em química) e não truque". O mecanismo existia e
     * nunca tinha sido lido nem mostrado a ninguém.
     */
    public function test_o_perfil_enxerga_a_repeticao_de_construcoes(): void
    {
        $p = app(Perfil::class)->de($this->colonia([
            ['mina_local', 3], ['mina_local', 2], ['mina_local', 1], ['fazenda', 1],
        ]));

        $this->assertSame(['mina_local' => 3], $p['repetidas']);
    }

    public function test_a_api_devolve_o_perfil_do_colono(): void
    {
        $c = $this->colonia([['mina_local', 4]]);

        $this->actingAs($c->user)->getJson('/perfil-da-colonia')
            ->assertOk()
            ->assertJson(['tem_colonia' => true, 'vocacao' => 'metal_bruto']);
    }
}
