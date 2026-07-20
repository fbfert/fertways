<?php

namespace Tests\Feature;

use App\Domain\Colony\CreateColony;
use App\Domain\Endurance\ComprarPeca;
use App\Domain\Endurance\DescontoDeEndurance;
use App\Domain\Endurance\EnduranceSpecs;
use App\Domain\Logistics\ConcluirTrechos;
use App\Domain\Marco\Curva;
use App\Domain\Market\ColocarOrdem;
use App\Domain\Market\ExecutarOrdem;
use App\Domain\Treasury\Tesouro;
use App\Models\Colony;
use App\Models\ColonyEndurancePiece;
use App\Models\Ledger;
use App\Models\MarketAccount;
use App\Models\User;
use Database\Seeders\BuildingSpecSeeder;
use Database\Seeders\ComponentRecipeSeeder;
use Database\Seeders\ResourceTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A Loja de Peças da Endurance (§05, D-132) — 8 seções × 4 camadas, ligadas ao Marco.
 */
class LojaDaEnduranceTest extends TestCase
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

    private function colonia(string $nick, int $marco = 1): Colony
    {
        $user = User::factory()->create(['email' => "{$nick}@t.test", 'nickname' => $nick]);
        $colony = app(CreateColony::class)->handle($user, "Colônia {$nick}", 10 + 2 * $this->proximoSlot++, 10);
        $colony->forceFill(['xp' => Curva::xpDoMarco($marco)])->save();

        return $colony->fresh();
    }

    private function fert(Colony $c): int
    {
        return (int) DB::table('colonies')->where('id', $c->id)->value('fert_micro');
    }

    /** As peças custam mais do que o saldo de fundação (D-85) cobre para vários testes. */
    private function creditar(Colony $c, int $fert): void
    {
        DB::table('colonies')->where('id', $c->id)->increment('fert_micro', $fert * 1_000_000);
    }

    #[Test]
    public function o_catalogo_tem_32_pecas_8_secoes_vezes_4_camadas(): void
    {
        $this->assertCount(32, EnduranceSpecs::catalogo());
    }

    #[Test]
    public function compra_exige_o_marco_certo(): void
    {
        $a = $this->colonia('a', 1);

        $this->expectExceptionMessage('exige o marco 10');
        app(ComprarPeca::class)->handle($a, 'anel_habitacional:comum');
    }

    #[Test]
    public function a_compra_debita_fert_e_credita_o_tesouro(): void
    {
        $a = $this->colonia('a', 10);
        $antes = $this->fert($a);
        $tesouroAntes = app(Tesouro::class)->saldoFertMicro();

        $registro = app(ComprarPeca::class)->handle($a, 'anel_habitacional:comum');

        $this->assertSame('anel_habitacional:comum', $registro->peca_key);
        $this->assertSame($antes - 20_000_000, $this->fert($a));
        $this->assertSame($tesouroAntes + 20_000_000, app(Tesouro::class)->saldoFertMicro());
        $this->assertSame(1, Ledger::where('type', 'compra_peca_endurance')->count());
    }

    #[Test]
    public function nao_se_compra_a_mesma_peca_duas_vezes(): void
    {
        $a = $this->colonia('a', 10);
        app(ComprarPeca::class)->handle($a, 'anel_habitacional:comum');

        $this->expectExceptionMessage('Você já tem esta peça');
        app(ComprarPeca::class)->handle($a->fresh(), 'anel_habitacional:comum');
    }

    #[Test]
    public function peca_unica_esgota_depois_da_primeira_compra(): void
    {
        $a = $this->colonia('a', 100);
        $b = $this->colonia('b', 100);
        $this->creditar($a, 500);
        $this->creditar($b, 500);

        app(ComprarPeca::class)->handle($a, 'anel_habitacional:unica');

        $this->expectExceptionMessage('outra colônia já a arrematou');
        app(ComprarPeca::class)->handle($b, 'anel_habitacional:unica');
    }

    #[Test]
    public function duas_colonias_diferentes_podem_ter_a_mesma_camada_em_secoes_diferentes(): void
    {
        $a = $this->colonia('a', 100);
        $b = $this->colonia('b', 100);
        $this->creditar($a, 500);
        $this->creditar($b, 500);

        app(ComprarPeca::class)->handle($a, 'anel_habitacional:unica');
        // Outra SEÇÃO única — não é a mesma peça, então não esgotou.
        $registro = app(ComprarPeca::class)->handle($b, 'baia_criogenica:unica');

        $this->assertSame('baia_criogenica:unica', $registro->peca_key);
    }

    #[Test]
    public function fert_insuficiente_e_recusado(): void
    {
        $a = $this->colonia('a', 100);
        DB::table('colonies')->where('id', $a->id)->update(['fert_micro' => 0]);

        $this->expectExceptionMessage('Faltam');
        app(ComprarPeca::class)->handle($a->fresh(), 'anel_habitacional:unica');
    }

    #[Test]
    public function o_desconto_soma_as_pecas_possuidas_e_respeita_o_teto(): void
    {
        $a = $this->colonia('a', 100);
        $this->creditar($a, 3000);
        $desconto = app(DescontoDeEndurance::class);

        $this->assertSame(0, $desconto->desconto($a));

        app(ComprarPeca::class)->handle($a, 'anel_habitacional:comum'); // 100 bps
        $this->assertSame(100, $desconto->desconto($a->fresh()));

        app(ComprarPeca::class)->handle($a->fresh(), 'baia_criogenica:reputacao_2'); // +500 bps
        $this->assertSame(600, $desconto->desconto($a->fresh()));

        // Estourar o teto: compra várias únicas (1000 bps cada) até passar de 3000.
        foreach (['comando', 'matriz_comunicacao', 'modulo_medico', 'nucleo_propulsao'] as $secao) {
            app(ComprarPeca::class)->handle($a->fresh(), "{$secao}:unica");
        }

        // 600 + 4×1000 = 4600, capado em 3000 (EnduranceSpecs::TETO_DESCONTO_BPS).
        $this->assertSame(EnduranceSpecs::TETO_DESCONTO_BPS, $desconto->desconto($a->fresh()));
    }

    #[Test]
    public function o_desconto_reduz_o_tributo_da_entrega_por_transporte(): void
    {
        [$a, $b] = [$this->colonia('a', 100), $this->colonia('b', 1)];
        app(ComprarPeca::class)->handle($a, 'anel_habitacional:comum'); // 100 bps = 1%

        $a->resources()->where('resource_type', 'metal_bruto')->update(['amount' => 1000]);

        $veiculo = $a->vehicles()->create([
            'type' => 'furgao_de_comercio', 'level' => 1, 'status' => 'ocioso',
            'capacity' => \App\Models\Vehicle::CAPACIDADE['furgao_de_comercio'],
        ]);

        app(\App\Domain\Transport\Placas::class)->registrar($veiculo);

        app(\App\Domain\Logistics\DespacharVeiculo::class)->handle(
            $a, $veiculo, 'colonia', $b->id, ['metal_bruto' => 1000],
        );

        $veiculo->refresh();
        $veiculo->update(['arrives_at' => now()->subMinute(), 'departs_at' => now()->subHour()]);

        app(ConcluirTrechos::class)->handle();

        // Metal Bruto é primário: 3% cheio = 300 bps. Com 1% de desconto da Endurance, sobra 2,97%.
        $taxa = DB::table('tax_events')->where('kind', 'transporte_entrega')->first();
        $this->assertSame(297, (int) $taxa->tax_bps);
    }

    #[Test]
    public function o_desconto_reduz_o_tributo_da_venda_no_mercado_central(): void
    {
        $a = $this->colonia('a', 100);
        $b = $this->colonia('b', 1);
        app(ComprarPeca::class)->handle($a, 'anel_habitacional:comum'); // 1% de desconto

        MarketAccount::create(['colony_id' => $a->id, 'resource_type' => 'metal_bruto', 'amount' => 100]);
        $venda = app(ColocarOrdem::class)->handle($a->fresh(), 'sell', 'metal_bruto', 100, 50_000);
        app(ExecutarOrdem::class)->handle($b, $venda->id, 100);

        $taxa = DB::table('tax_events')->where('kind', 'mercado_venda')->first();
        // 3% cheio, com 1% de desconto: 2,97% = 297 bps.
        $this->assertSame(297, (int) $taxa->tax_bps);
    }

    #[Test]
    public function a_rota_lista_o_catalogo_com_o_estado_certo_para_a_colonia(): void
    {
        $a = $this->colonia('a', 20);
        app(ComprarPeca::class)->handle($a, 'anel_habitacional:comum');

        $resposta = $this->actingAs($a->user)->getJson('/endurance');
        $resposta->assertOk();

        $pecas = collect($resposta->json('pecas'))->keyBy('chave');

        $this->assertSame('possuida', $pecas['anel_habitacional:comum']['estado']);
        $this->assertSame('disponivel', $pecas['baia_criogenica:comum']['estado']);
        $this->assertSame('bloqueada', $pecas['anel_habitacional:reputacao_1']['estado']);
    }
}
