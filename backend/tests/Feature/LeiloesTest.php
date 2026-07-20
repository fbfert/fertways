<?php

namespace Tests\Feature;

use App\Domain\Colony\CreateColony;
use App\Domain\Leilao\CancelarLeilao;
use App\Domain\Leilao\DarLance;
use App\Domain\Leilao\FecharLeiloes;
use App\Domain\Leilao\ListarLeilao;
use App\Domain\Treasury\Tesouro;
use App\Models\Auction;
use App\Models\Colony;
use App\Models\Ledger;
use App\Models\MarketAccount;
use App\Models\User;
use App\Models\XpEntry;
use Database\Seeders\BuildingSpecSeeder;
use Database\Seeders\ComponentRecipeSeeder;
use Database\Seeders\ResourceTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Leilões (D-129) — sistema sem seção no GDD, desenhado sobre o Mercado Central: lote único em
 * escrow ao anunciar, lance em escrow na hora, fechamento por prazo no tick.
 */
class LeiloesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ResourceTypeSeeder::class);
        $this->seed(ComponentRecipeSeeder::class);
        $this->seed(BuildingSpecSeeder::class);
    }

    private function colonia(string $nick, int $x, int $y): Colony
    {
        $user = User::factory()->create(['email' => "{$nick}@t.test", 'nickname' => $nick]);
        $colony = app(CreateColony::class)->handle($user, "Colônia {$nick}", $x, $y);
        $colony->resources()->update(['amount' => 0]);

        return $colony->fresh();
    }

    /** Vendedor com carga já na doca, dois lançadores com Fert$ do onboarding. */
    private function cenario(int $naDoca = 100): array
    {
        $a = $this->colonia('vendedor', 10, 10);
        $b = $this->colonia('lancador1', 20, 20);
        $c = $this->colonia('lancador2', 30, 30);
        MarketAccount::create(['colony_id' => $a->id, 'resource_type' => 'metal_bruto', 'amount' => $naDoca]);

        return [$a, $b, $c];
    }

    private function fert(Colony $c): int
    {
        return (int) DB::table('colonies')->where('id', $c->id)->value('fert_micro');
    }

    private function naDoca(Colony $c, string $recurso = 'metal_bruto'): int
    {
        return (int) MarketAccount::where('colony_id', $c->id)->where('resource_type', $recurso)->value('amount');
    }

    #[Test]
    public function nao_se_leiloa_o_que_nao_esta_na_doca(): void
    {
        $a = $this->colonia('sozinho', 10, 10);

        $this->expectExceptionMessage('Entregue a carga na doca primeiro');
        app(ListarLeilao::class)->handle($a, 'metal_bruto', 100, 50_000, 24);
    }

    #[Test]
    public function anunciar_reserva_o_lote_em_escrow(): void
    {
        [$a] = $this->cenario();

        $leilao = app(ListarLeilao::class)->handle($a, 'metal_bruto', 60, 5_000_000, 24);

        $this->assertSame('aberto', $leilao->status);
        $this->assertSame(40, $this->naDoca($a), 'saiu da doca e entrou no escrow');
        $this->assertSame(-60, Ledger::where('type', 'escrow_leilao')->where('resource_type', 'metal_bruto')->value('amount'));
    }

    #[Test]
    public function duracao_fora_da_faixa_e_recusada(): void
    {
        [$a] = $this->cenario();

        $this->expectExceptionMessage('entre 1 e 72 horas');
        app(ListarLeilao::class)->handle($a, 'metal_bruto', 10, 5_000_000, 200);
    }

    #[Test]
    public function o_primeiro_lance_tem_de_alcancar_o_minimo(): void
    {
        [$a, $b] = $this->cenario();
        $leilao = app(ListarLeilao::class)->handle($a, 'metal_bruto', 10, 5_000_000, 24);

        $this->expectExceptionMessage('lance mínimo agora é');
        app(DarLance::class)->handle($b, $leilao->id, 4_000_000);
    }

    #[Test]
    public function o_lance_escrowa_fert_e_devolve_quem_foi_superado(): void
    {
        [$a, $b, $c] = $this->cenario();
        $leilao = app(ListarLeilao::class)->handle($a, 'metal_bruto', 10, 5_000_000, 24);

        app(DarLance::class)->handle($b, $leilao->id, 5_000_000);
        $this->assertSame(Colony::SALDO_INICIAL_MICRO - 5_000_000, $this->fert($b));

        app(DarLance::class)->handle($c, $leilao->id, 6_000_000);
        $this->assertSame(Colony::SALDO_INICIAL_MICRO, $this->fert($b), 'o lance superado voltou inteiro');
        $this->assertSame(Colony::SALDO_INICIAL_MICRO - 6_000_000, $this->fert($c));

        $leilao = $leilao->fresh();
        $this->assertSame(6_000_000, $leilao->lance_atual_micro);
        $this->assertSame($c->id, $leilao->lance_colony_id);
    }

    #[Test]
    public function um_lance_abaixo_do_atual_e_recusado(): void
    {
        [$a, $b, $c] = $this->cenario();
        $leilao = app(ListarLeilao::class)->handle($a, 'metal_bruto', 10, 5_000_000, 24);
        app(DarLance::class)->handle($b, $leilao->id, 6_000_000);

        $this->expectExceptionMessage('lance mínimo agora é');
        app(DarLance::class)->handle($c, $leilao->id, 5_500_000);
    }

    #[Test]
    public function ninguem_da_lance_no_proprio_leilao(): void
    {
        [$a] = $this->cenario();
        $leilao = app(ListarLeilao::class)->handle($a, 'metal_bruto', 10, 5_000_000, 24);

        $this->expectExceptionMessage('não pode dar lance no próprio leilão');
        app(DarLance::class)->handle($a, $leilao->id, 6_000_000);
    }

    #[Test]
    public function cancelar_sem_lance_devolve_o_lote(): void
    {
        [$a] = $this->cenario();
        $leilao = app(ListarLeilao::class)->handle($a, 'metal_bruto', 60, 5_000_000, 24);

        $cancelado = app(CancelarLeilao::class)->handle($a, $leilao);

        $this->assertSame('cancelado', $cancelado->status);
        $this->assertSame(100, $this->naDoca($a));
    }

    #[Test]
    public function cancelar_com_lance_e_recusado(): void
    {
        [$a, $b] = $this->cenario();
        $leilao = app(ListarLeilao::class)->handle($a, 'metal_bruto', 10, 5_000_000, 24);
        app(DarLance::class)->handle($b, $leilao->id, 5_000_000);

        $this->expectExceptionMessage('já tem lance e não pode mais ser cancelado');
        app(CancelarLeilao::class)->handle($a, $leilao->fresh());
    }

    #[Test]
    public function o_tick_fecha_um_leilao_sem_lance_devolvendo_o_lote(): void
    {
        [$a] = $this->cenario();
        $leilao = app(ListarLeilao::class)->handle($a, 'metal_bruto', 60, 5_000_000, 24);
        $leilao->forceFill(['deadline_at' => now()->subMinute()])->save();

        $resultado = app(FecharLeiloes::class)->handle();

        $this->assertSame(['arrematados' => 0, 'sem_lance' => 1], $resultado);
        $this->assertSame('sem_lance', $leilao->fresh()->status);
        $this->assertSame(100, $this->naDoca($a));
    }

    #[Test]
    public function o_tick_fecha_um_leilao_arrematado_pagando_o_vendedor_liquido_de_tributo(): void
    {
        [$a, $b] = $this->cenario();
        $leilao = app(ListarLeilao::class)->handle($a, 'metal_bruto', 100, 1_000_000, 24);
        app(DarLance::class)->handle($b, $leilao->id, 5_000_000);
        $leilao->forceFill(['deadline_at' => now()->subMinute()])->save();

        $tesouroAntes = app(Tesouro::class)->saldoFertMicro();

        $resultado = app(FecharLeiloes::class)->handle();

        $this->assertSame(['arrematados' => 1, 'sem_lance' => 0], $resultado);
        $this->assertSame('arrematado', $leilao->fresh()->status);

        // Metal Bruto é primário: 3% (§8.3). Valor 5 Fert$, taxa 150.000, líquido 4.850.000.
        $this->assertSame(Colony::SALDO_INICIAL_MICRO + 4_850_000, $this->fert($a));
        $this->assertSame(Colony::SALDO_INICIAL_MICRO - 5_000_000, $this->fert($b), 'o lance já estava em escrow');
        $this->assertSame(100, $this->naDoca($b), 'o lote foi para o depósito do arrematante');
        $this->assertSame(0, $this->naDoca($a));

        $this->assertSame($tesouroAntes + 150_000, app(Tesouro::class)->saldoFertMicro());

        $taxa = DB::table('tax_events')->where('kind', 'leilao_venda')->first();
        $this->assertSame($a->id, (int) $taxa->colony_id);
        $this->assertSame(5_000_000, (int) $taxa->base_amount);
        $this->assertSame(300, (int) $taxa->tax_bps);

        // Acima do piso do D-43/D-117: os dois lados ganham XP pela trilha de mercado.
        $this->assertSame(1, XpEntry::where('colony_id', $a->id)->where('acao', 'mercado_executado')->count());
        $this->assertSame(1, XpEntry::where('colony_id', $b->id)->where('acao', 'mercado_executado')->count());
    }

    #[Test]
    public function um_leilao_abaixo_do_piso_nao_rende_xp(): void
    {
        [$a, $b] = $this->cenario();
        // 1 unidade a 1 micro-Fert$: bem abaixo do piso de 5 Fert$ (D-43/D-117).
        $leilao = app(ListarLeilao::class)->handle($a, 'metal_bruto', 1, 1, 24);
        app(DarLance::class)->handle($b, $leilao->id, 1);
        $leilao->forceFill(['deadline_at' => now()->subMinute()])->save();

        app(FecharLeiloes::class)->handle();

        $this->assertSame(0, XpEntry::where('colony_id', $a->id)->where('acao', 'mercado_executado')->count());
    }

    #[Test]
    public function o_tick_nao_fecha_o_que_ainda_nao_venceu(): void
    {
        [$a] = $this->cenario();
        app(ListarLeilao::class)->handle($a, 'metal_bruto', 60, 5_000_000, 24);

        $resultado = app(FecharLeiloes::class)->handle();

        $this->assertSame(['arrematados' => 0, 'sem_lance' => 0], $resultado);
    }
}
