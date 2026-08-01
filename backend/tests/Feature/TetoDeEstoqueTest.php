<?php

namespace Tests\Feature;

use App\Domain\Colony\CreateColony;
use App\Domain\Colony\TetoDoEstoque;
use App\Domain\Production\ColonyTick;
use App\Models\User;
use Database\Seeders\BuildingSpecSeeder;
use Database\Seeders\ComponentRecipeSeeder;
use Database\Seeders\ResourceTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * O teto de estoque que TRAVA (A2.7 item 4 / BALANCEAMENTO §14).
 *
 * O que estes testes guardam não são os números — a curva é hipótese até a rodada do simulador — e
 * sim a **promessa** do §14: *"o jogador perde oportunidade, nunca estoque"*.
 */
class TetoDeEstoqueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ResourceTypeSeeder::class);
        $this->seed(ComponentRecipeSeeder::class);
        $this->seed(BuildingSpecSeeder::class);
    }

    private int $proximoSlot = 0;

    private function colono(): User
    {
        $user = User::factory()->create();
        $colony = app(CreateColony::class)->handle($user, 'Nova Aurora', 10 + $this->proximoSlot++, 20);
        $colony->resources()->update(['amount' => 0]);

        return $user->fresh();
    }

    private function ligar(array $valores = []): void
    {
        DB::table('estoque_settings')->where('id', 1)->update(['ativo' => true] + $valores);
    }

    private function estoque(User $user, string $recurso): int
    {
        return (int) $user->colony->resources()->where('resource_type', $recurso)->value('amount');
    }

    private function tick(User $user, Carbon $agora): void
    {
        app(ColonyTick::class)->handle($user->colony()->first(), $agora);
    }

    // ─────────────────────────────────── a chave-mestra

    /**
     * ⚠️ Desligado, o teto não existe — nem como número.
     *
     * O par com o teste seguinte é o que impede a chave de virar decoração: já aconteceu nesta base
     * de `population_settings.ativo` não ser lido por ninguém (D-178).
     */
    public function test_desligado_a_producao_passa_do_que_seria_o_teto(): void
    {
        $u = $this->colono();
        $this->erguerPredio($u->colony, 'extrator_de_agua', 5);

        // Um teto minúsculo, que só teria efeito se a chave estivesse ligada.
        DB::table('estoque_settings')->where('id', 1)->update(['capacidade_base' => 5]);

        $this->tick($u, now()->addDays(2));

        $this->assertGreaterThan(5, $this->estoque($u, 'agua'), 'desligado, nada trava');
    }

    public function test_ligado_a_producao_para_no_teto(): void
    {
        $u = $this->colono();
        $this->erguerPredio($u->colony, 'extrator_de_agua', 5);
        $this->ligar(['capacidade_base' => 50]);

        $this->tick($u, now()->addDays(2));

        $this->assertSame(50, $this->estoque($u, 'agua'), 'trava exatamente no teto');
    }

    // ─────────────────────────────────── a promessa do §14

    /**
     * ⚠️ A promessa inteira em um teste: **o estoque acima do teto não é destruído**.
     *
     * *"O jogador perde oportunidade, nunca estoque."* É o que separa este teto do teto que derrama,
     * e é o que torna a ativação possível num mundo que hoje guarda ~35 mil de água por colônia
     * contra um teto de 10.000. Mesma forma do teto habitacional da população (D-178): trava o
     * crescimento, não expulsa ninguém.
     */
    public function test_acima_do_teto_o_estoque_que_ja_existe_nao_e_destruido(): void
    {
        $u = $this->colono();
        $this->erguerPredio($u->colony, 'extrator_de_agua', 5);
        $u->colony->resources()->where('resource_type', 'agua')->update(['amount' => 9_000]);
        $this->ligar(['capacidade_base' => 100]);

        $this->tick($u, now()->addDays(2));

        $this->assertSame(9_000, $this->estoque($u, 'agua'), 'nem um grão a menos');
    }

    /**
     * E o consumo continua passando: o teto limita o GANHO, não o gasto.
     *
     * Sem esta distinção, uma colônia no teto ficaria com o estoque congelado — incapaz de gastar o
     * que tem, que é o oposto de tudo o que a §14 quer.
     */
    public function test_o_teto_nao_trava_o_consumo(): void
    {
        $u = $this->colono();
        // A Destilaria come Biomassa e Energia; o teto cheio de biomassa não pode impedir o gasto.
        $this->erguerPredio($u->colony, 'destilaria', 1);
        // Sem Tanque a Destilaria não converte NADA (§21.9/D-131: capacidade zero), e o teste
        // mediria o silêncio dela em vez do que se propõe a medir.
        $this->erguerPredio($u->colony, 'tanque_de_combustivel', 1);
        $u->colony->resources()->where('resource_type', 'biomassa')->update(['amount' => 5_000]);
        $u->colony->resources()->where('resource_type', 'energia')->update(['amount' => 5_000]);
        $this->ligar(['capacidade_base' => 10]);

        $this->tick($u, now()->addHours(6));

        $this->assertLessThan(5_000, $this->estoque($u, 'biomassa'), 'o gasto acontece mesmo no teto');
    }

    // ─────────────────────────────────── a curva

    public function test_a_capacidade_compoe_por_nivel(): void
    {
        $u = $this->colono();
        $teto = app(TetoDoEstoque::class);
        $this->ligar(['capacidade_base' => 10_000, 'capacidade_fator_milesimos' => 1_250]);

        $this->erguerPredio($u->colony, 'deposito_local', 1);
        $this->assertSame(10_000, $teto->capacidade($u->colony->fresh(), 'agua'));

        $this->erguerPredio($u->colony, 'deposito_local', 3);
        // 10.000 × 1,25 × 1,25 = 15.625 — compõe, não soma (somar daria 15.000).
        $this->assertSame(15_625, app(TetoDoEstoque::class)->capacidade($u->colony->fresh(), 'agua'));
    }

    /**
     * O Biocombustível fica fora do teto geral: ele já tem o Tanque (§21.9/D-131).
     *
     * Um recurso não deve responder a dois prédios — o jogador subiria o Depósito Local e não veria
     * efeito nenhum, ou pior, veria efeito pela metade.
     */
    public function test_o_biocombustivel_nao_responde_ao_deposito_local(): void
    {
        $u = $this->colono();
        $this->ligar(['capacidade_base' => 7]);

        $this->assertNull(app(TetoDoEstoque::class)->capacidade($u->colony, 'biocombustivel'));
    }

    /** Sem teto é `null`, e não zero: um sentinela numérico viraria um teto que ninguém escolheu. */
    public function test_desligado_a_capacidade_e_nula(): void
    {
        $u = $this->colono();

        $this->assertNull(app(TetoDoEstoque::class)->capacidade($u->colony, 'agua'));
    }

    // ─────────────────────────────────── o lote indivisível

    /**
     * ⚠️ A Siderúrgica tem SEIS saídas por lote, e o teto trava o lote por inteiro.
     *
     * Creditar cinco e descartar a sexta seria derramar — o caso exato que a §14 proíbe. O teste
     * aperta o teto e exige que o Metal Bruto **não** seja consumido em troca de nada.
     */
    public function test_o_lote_da_siderurgica_trava_inteiro_quando_uma_saida_nao_cabe(): void
    {
        $u = $this->colono();
        $this->erguerPredio($u->colony, 'industria_siderurgica', 3);
        $u->colony->resources()->where('resource_type', 'metal_bruto')->update(['amount' => 500_000]);

        // Espaço para nenhum lote: as saídas já estão no teto.
        $this->ligar(['capacidade_base' => 1]);
        foreach (['ligas_metalicas', 'tungstenio'] as $r) {
            $u->colony->resources()->where('resource_type', $r)->update(['amount' => 1]);
        }

        // Três dias, e não um: a Siderúrgica nível 3 processa 34 Metal Bruto/h contra um lote de
        // 1.000, então em 24 h não fecha lote NENHUM — e o teste passaria sem provar coisa alguma.
        $this->tick($u, now()->addDays(3));

        $this->assertSame(1, $this->estoque($u, 'ligas_metalicas'), 'nenhuma saída foi creditada');
    }

    /**
     * ⚠️ O controle do teste acima, e ele não é opcional.
     *
     * "Nada foi creditado" passa igualmente quando o teto trava e quando a Siderúrgica simplesmente
     * não produziu nada no intervalo — e um teste que passa porque **nada aconteceu** não guarda
     * coisa alguma. Já caí nessa nesta base, no grandfathering do D-178.
     *
     * Cenário idêntico, teto desligado: se aqui não crescer, o teste do lote não prova nada.
     */
    public function test_controle_a_mesma_siderurgica_produz_quando_nao_ha_teto(): void
    {
        $u = $this->colono();
        $this->erguerPredio($u->colony, 'industria_siderurgica', 3);
        $u->colony->resources()->where('resource_type', 'metal_bruto')->update(['amount' => 500_000]);

        foreach (['ligas_metalicas', 'tungstenio'] as $r) {
            $u->colony->resources()->where('resource_type', $r)->update(['amount' => 1]);
        }

        $this->tick($u, now()->addDays(3));

        $this->assertGreaterThan(1, $this->estoque($u, 'ligas_metalicas'), 'sem teto, o lote fecha');
    }
}
