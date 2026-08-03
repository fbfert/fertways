<?php

namespace Tests\Feature;

use App\Domain\Colony\CreateColony;
use App\Domain\Endurance\EfeitosDaEndurance;
use App\Domain\Pesquisa\EfeitosDaPesquisa;
use App\Domain\Pesquisa\Pesquisar;
use App\Domain\Production\ColonyTick;
use App\Exceptions\DomainRuleException;
use App\Models\Colony;
use App\Models\Technology;
use App\Models\User;
use Database\Seeders\BuildingSpecSeeder;
use Database\Seeders\ComponentRecipeSeeder;
use Database\Seeders\ResourceTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A pesquisa LIGADA: a chave, a conclusão e os efeitos chegando ao jogo (A2.3).
 *
 * ⚠️ Estes testes existem porque a A2.3 entregou o modelo e parou: não havia rota, não havia tela, e
 * `EfeitosDaPesquisa` não era consumido por ninguém. Ligar a chave naquele estado não teria feito
 * **nada** — o mesmo defeito da população no D-178.
 */
class PesquisaLigadaTest extends TestCase
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

    private function ligar(): void
    {
        DB::table('research_settings')->where('id', 1)->update(['ativo' => true]);
    }

    private function colonia(): Colony
    {
        $u = User::factory()->create();
        $c = app(CreateColony::class)->handle($u, 'Pq', 10 + $this->proximo++, 20);
        $c->resources()->update(['amount' => 500_000]);
        $this->erguerPredio($c, 'laboratorio', 5);
        $this->erguerPredio($c, 'reator_de_energia', 5);

        return $c->fresh();
    }

    private function tecnologia(array $efeitos, int $segundos = 3600): Technology
    {
        return Technology::create([
            'chave' => 'tec'.$this->proximo++,
            'nome' => 'Tec',
            'descricao' => 'Uma tecnologia de teste.',
            'trilha' => 'energia',
            // ⚠️ O ARRAY CRU: o modelo já faz o cast. Codificar aqui codificaria duas vezes — a
            // mesma armadilha do D-183, terceira vez nesta sessão.
            'custo_json' => ['metal_bruto' => 10],
            'duracao_segundos' => $segundos,
            'nivel_maximo' => 3,
            'laboratorio_minimo' => 1,
            'efeitos_json' => $efeitos,
            'ativa' => true,
            'versao' => 1,
        ]);
    }

    // ────────────────────────────────────────────── a chave-mestra

    /** ⚠️ O par que impede a chave de voltar a ser decorativa. Desligada, ninguém pesquisa. */
    public function test_desligada_a_pesquisa_e_recusada(): void
    {
        $c = $this->colonia();

        $this->expectException(DomainRuleException::class);
        app(Pesquisar::class)->handle($c, $this->tecnologia([]));
    }

    public function test_ligada_a_pesquisa_comeca(): void
    {
        $this->ligar();
        $c = $this->colonia();

        app(Pesquisar::class)->handle($c, $this->tecnologia([]));

        $this->assertDatabaseHas('colony_technologies', [
            'colony_id' => $c->id, 'status' => 'pesquisando',
        ]);
    }

    // ────────────────────────────────────────────── ⚠️ o que faltava: conclusão e efeito

    /**
     * ⚠️ **A pesquisa termina.**
     *
     * O docblock de `ConcluirPesquisa` afirmava que *"`ColonyTick` chama isto antes de calcular
     * produção"* — e o `ColonyTick` NÃO o chamava. A frase descrevia uma intenção que ninguém tinha
     * ligado, e o efeito era pior que não ter a frase: a pesquisa nunca acabava, e a colônia perdia
     * a vaga do Laboratório para sempre sem receber bônus nenhum.
     */
    public function test_o_tick_conclui_a_pesquisa_vencida(): void
    {
        $this->ligar();
        $c = $this->colonia();
        app(Pesquisar::class)->handle($c, $this->tecnologia([], segundos: 60));

        /*
         * ⚠️ O RELÓGIO tem de andar de verdade, e não só o parâmetro do tick.
         *
         * `ConcluirPesquisa` compara `finishes_at` com `now()` — o relógio de parede, não o instante
         * que o tick recebe. Está certo assim para a produção, onde o tick roda com o agora real;
         * mas um teste que só passa `now()->addHours(2)` como argumento não move o `now()` que a
         * conclusão consulta, e a pesquisa nunca vence.
         */
        $this->travel(2)->hours();
        app(ColonyTick::class)->handle($c, now());

        $this->assertDatabaseHas('colony_technologies', [
            'colony_id' => $c->id, 'status' => 'concluida', 'nivel' => 1,
        ]);
    }

    /**
     * ⚠️ **E o efeito CHEGA à produção.**
     *
     * `EfeitosDaPesquisa` existia desde a A2.3 e não era consumido por ninguém: o número certo indo
     * para o vazio. Este é o teste que prova que pesquisar mudou alguma coisa no jogo.
     */
    public function test_a_tecnologia_concluida_aumenta_a_producao(): void
    {
        $this->ligar();

        $sem = $this->colonia();
        $com = $this->colonia();

        $tec = $this->tecnologia(
            [['tipo' => 'producao_bonus', 'alvo' => 'reator_de_energia', 'valor_bps' => 3_000]],
            segundos: 60,
        );

        app(Pesquisar::class)->handle($com, $tec);

        // Ver o comentário do teste acima: o relógio anda, e não só o argumento do tick.
        $this->travel(2)->hours();
        app(ColonyTick::class)->handle($com, now());
        app(ColonyTick::class)->handle($sem, now());

        $energiaCom = (int) $com->fresh()->resources()->where('resource_type', 'energia')->value('amount');
        $energiaSem = (int) $sem->fresh()->resources()->where('resource_type', 'energia')->value('amount');

        $this->assertGreaterThan($energiaSem, $energiaCom, 'a tecnologia rendeu produção de verdade');
    }

    /**
     * ⚠️ O teto é AGREGADO entre Endurance e pesquisa.
     *
     * Cada fonte respeitar o limite sozinha permitiria que duas de 30% dessem 60% — e aí o teto
     * deixaria de limitar o que ele existe para limitar. O próprio docblock de `somaBps()` avisava
     * que quem quisesse teto conjunto teria de somar antes de limitar, no consumidor.
     */
    public function test_o_teto_de_producao_e_agregado(): void
    {
        $this->ligar();
        $c = $this->colonia();

        // Um valor absurdo: se o teto não fosse aplicado, a produção explodiria.
        $tec = $this->tecnologia(
            [['tipo' => 'producao_bonus', 'alvo' => 'reator_de_energia', 'valor_bps' => 999_999]],
            segundos: 60,
        );

        app(Pesquisar::class)->handle($c, $tec);
        $this->travel(2)->hours();
        app(ColonyTick::class)->handle($c, now());

        $bonus = app(EfeitosDaPesquisa::class)->bonusDeProducaoPorAlvo($c->fresh());
        $teto = EfeitosDaEndurance::tetoBps(
            EfeitosDaEndurance::PRODUCAO_BONUS,
        );

        $this->assertGreaterThan($teto, $bonus['reator_de_energia'], 'a fonte devolve SEM teto');
        // E quem consome aplica o teto: a produção não pode ter crescido 100x.
        $energia = (int) $c->fresh()->resources()->where('resource_type', 'energia')->value('amount');
        $this->assertLessThan(500_000 * 10, $energia, 'o consumidor limitou o total');
    }

    // ────────────────────────────────────────────── a tela

    /** ⚠️ Rota que ninguém alcança é peça inerte — foi o erro do D-180. */
    public function test_a_arvore_chega_ao_jogador(): void
    {
        $this->ligar();
        $c = $this->colonia();
        $this->tecnologia([]);

        $corpo = $this->actingAs($c->user)->getJson('/pesquisa')->assertOk()->json();

        $this->assertTrue($corpo['ativo']);
        $this->assertNotEmpty($corpo['tecnologias']);
        $this->assertArrayHasKey('meus_efeitos', $corpo);
        $this->assertGreaterThan(0, $corpo['vagas']['total']);
    }

    public function test_desligada_a_tela_diz_que_esta_desligada(): void
    {
        $c = $this->colonia();

        $corpo = $this->actingAs($c->user)->getJson('/pesquisa')->assertOk()->json();

        $this->assertFalse($corpo['ativo']);
    }
}
