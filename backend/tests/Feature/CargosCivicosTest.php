<?php

namespace Tests\Feature;

use App\Console\Commands\TickColonies;
use App\Domain\Cargos\CargosCivicosSpecs;
use App\Domain\Cargos\ConfirmarSinalizacao;
use App\Domain\Cargos\GerirCargoCivico;
use App\Domain\Cargos\PagarCargosCivicos;
use App\Domain\Cargos\PublicarMateria;
use App\Domain\Cargos\SinalizarCargo;
use App\Domain\Colony\CreateColony;
use App\Exceptions\DomainRuleException;
use App\Models\CivicFlag;
use App\Models\Colony;
use App\Models\Ledger;
use App\Models\News;
use App\Models\User;
use Database\Seeders\BuildingSpecSeeder;
use Database\Seeders\ComponentRecipeSeeder;
use Database\Seeders\ResourceTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Cargos Públicos, §14.2 (D-130) — os 3 que sobravam depois do Conciliador: Repórter, Fiscal de
 * Mercado, Auxiliar de Tesouro. O Atendente do Espaçoporto fica de fora (100% dependente do
 * Espaçoporto, que não existe).
 */
class CargosCivicosTest extends TestCase
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

    private function colonia(string $nick): Colony
    {
        $user = User::factory()->create(['email' => "{$nick}@t.test", 'nickname' => $nick]);
        $colony = app(CreateColony::class)->handle($user, "Colônia {$nick}", 10 + 2 * $this->proximoSlot++, 10);

        return $colony->fresh();
    }

    private function nomear(Colony $c, string $kind): User
    {
        app(GerirCargoCivico::class)->nomear($c->user, $kind);

        return $c->user;
    }

    #[Test]
    public function nomear_dois_cargos_diferentes_para_o_mesmo_colono_e_permitido(): void
    {
        $c = $this->colonia('fb');

        $this->assertNotNull(app(GerirCargoCivico::class)->nomear($c->user, CargosCivicosSpecs::REPORTER));
        $this->assertNotNull(app(GerirCargoCivico::class)->nomear($c->user, CargosCivicosSpecs::FISCAL_DE_MERCADO));

        // Nomear o MESMO cargo duas vezes não tem efeito (devolve null).
        $this->assertNull(app(GerirCargoCivico::class)->nomear($c->user, CargosCivicosSpecs::REPORTER));
    }

    #[Test]
    public function o_cargo_paga_50_fert_por_dia_ate_o_tick_seguinte(): void
    {
        $c = $this->nomear($this->colonia('gama'), CargosCivicosSpecs::REPORTER)->colony;
        $antes = $c->fresh()->fert_micro;

        $this->assertSame(1, app(PagarCargosCivicos::class)->handle());
        $this->assertSame($antes + CargosCivicosSpecs::SALARIO_DIARIO_MICRO, $c->fresh()->fert_micro);

        // Duas vezes no mesmo dia, não.
        $this->assertSame(0, app(PagarCargosCivicos::class)->handle());
        $this->assertSame($antes + CargosCivicosSpecs::SALARIO_DIARIO_MICRO, $c->fresh()->fert_micro);

        Carbon::setTestNow(now()->addDay()->addMinute());
        $this->assertSame(1, app(PagarCargosCivicos::class)->handle());
        Carbon::setTestNow();

        $this->assertSame($antes + 2 * CargosCivicosSpecs::SALARIO_DIARIO_MICRO, $c->fresh()->fert_micro);
        $this->assertSame(2, Ledger::where('type', 'salario_cargo_civico')->count());
    }

    #[Test]
    public function cargo_suspenso_nao_recebe_salario(): void
    {
        $colono = $this->nomear($this->colonia('gama'), CargosCivicosSpecs::AUXILIAR_DE_TESOURO);
        app(GerirCargoCivico::class)->suspender($colono, CargosCivicosSpecs::AUXILIAR_DE_TESOURO);

        $this->assertSame(0, app(PagarCargosCivicos::class)->handle());
    }

    #[Test]
    public function reintegrar_volta_a_receber(): void
    {
        $colono = $this->nomear($this->colonia('gama'), CargosCivicosSpecs::AUXILIAR_DE_TESOURO);
        app(GerirCargoCivico::class)->suspender($colono, CargosCivicosSpecs::AUXILIAR_DE_TESOURO);
        app(GerirCargoCivico::class)->reintegrar($colono, CargosCivicosSpecs::AUXILIAR_DE_TESOURO);

        $this->assertSame(1, app(PagarCargosCivicos::class)->handle());
    }

    #[Test]
    public function demitir_encerra_o_cargo_de_verdade(): void
    {
        $colono = $this->nomear($this->colonia('gama'), CargosCivicosSpecs::REPORTER);
        app(GerirCargoCivico::class)->demitir($colono, CargosCivicosSpecs::REPORTER);

        $this->assertSame(0, app(PagarCargosCivicos::class)->handle());
        $this->expectException(DomainRuleException::class);
        app(PublicarMateria::class)->handle($colono->fresh(), 'Título', 'Corpo qualquer.');
    }

    #[Test]
    public function o_reporter_publica_no_mural_como_boletim_e_ganha_o_bonus(): void
    {
        $colono = $this->nomear($this->colonia('k14'), CargosCivicosSpecs::REPORTER);
        $antes = $colono->colony->fresh()->fert_micro;

        $noticia = app(PublicarMateria::class)->handle($colono, 'Achado no Gagarin', 'Um sinal novo.');

        $this->assertSame('boletim', $noticia->kind);
        $this->assertSame('k14', $noticia->author);
        $this->assertTrue(News::noMural()->whereKey($noticia->id)->exists());
        $this->assertSame(
            $antes + CargosCivicosSpecs::BONUS_MICRO,
            $colono->colony->fresh()->fert_micro,
        );
        $this->assertSame(1, Ledger::where('type', 'bonus_cargo_civico')->count());
    }

    #[Test]
    public function quem_nao_e_reporter_nao_publica(): void
    {
        $colono = $this->colonia('sem_cargo')->user;

        $this->expectExceptionMessage('Você não é Repórter');
        app(PublicarMateria::class)->handle($colono, 'Título', 'Corpo.');
    }

    #[Test]
    public function o_fiscal_sinaliza_e_so_recebe_bonus_quando_a_equipe_confirma(): void
    {
        $colono = $this->nomear($this->colonia('fiscal'), CargosCivicosSpecs::FISCAL_DE_MERCADO);
        $antes = $colono->colony->fresh()->fert_micro;

        $flag = app(SinalizarCargo::class)->handle($colono, CargosCivicosSpecs::FISCAL_DE_MERCADO, 'Preço muito acima do de referência, repetido.');

        $this->assertNull($flag->confirmado_em);
        // Sinalizar sozinho não paga nada — só a confirmação da equipe paga.
        $this->assertSame($antes, $colono->colony->fresh()->fert_micro);

        app(ConfirmarSinalizacao::class)->handle($flag->id);

        $this->assertSame($antes + CargosCivicosSpecs::BONUS_MICRO, $colono->colony->fresh()->fert_micro);
        $this->assertNotNull($flag->fresh()->confirmado_em);
    }

    #[Test]
    public function nao_se_confirma_a_mesma_sinalizacao_duas_vezes(): void
    {
        $colono = $this->nomear($this->colonia('fiscal'), CargosCivicosSpecs::FISCAL_DE_MERCADO);
        $flag = app(SinalizarCargo::class)->handle($colono, CargosCivicosSpecs::FISCAL_DE_MERCADO, 'Motivo.');
        app(ConfirmarSinalizacao::class)->handle($flag->id);

        $this->expectExceptionMessage('já foi confirmada');
        app(ConfirmarSinalizacao::class)->handle($flag->id);
    }

    #[Test]
    public function quem_nao_ocupa_o_cargo_nao_sinaliza(): void
    {
        $colono = $this->colonia('ninguem')->user;

        $this->expectExceptionMessage('Você não ocupa este cargo');
        app(SinalizarCargo::class)->handle($colono, CargosCivicosSpecs::FISCAL_DE_MERCADO, 'Motivo.');
    }

    #[Test]
    public function o_reporter_nao_sinaliza_o_teto_semanal_barra_o_bonus_alem_de_400(): void
    {
        $colono = $this->nomear($this->colonia('k14'), CargosCivicosSpecs::REPORTER);

        // 1 salário (50) já pago pelo tick + o resto em bônus até estourar o teto de 400.
        app(PagarCargosCivicos::class)->handle();

        // 116 matérias a 3 Fert$ cada passariam de 400 - 50 = 350; a de número 117 fica sem bônus.
        for ($i = 0; $i < 117; $i++) {
            app(PublicarMateria::class)->handle($colono, "Matéria {$i}", 'Corpo.');
        }

        $ganhoTotal = (int) Ledger::where('type', 'bonus_cargo_civico')
            ->where('ref', 'like', 'cargo:reporter:%')
            ->sum('amount');

        $this->assertLessThanOrEqual(CargosCivicosSpecs::TETO_SEMANAL_MICRO - CargosCivicosSpecs::SALARIO_DIARIO_MICRO, $ganhoTotal);
        $this->assertGreaterThan(0, $ganhoTotal, 'pelo menos as primeiras matérias pagaram bônus');
    }

    #[Test]
    public function o_tick_paga_os_cargos_civicos_junto_com_o_resto(): void
    {
        $this->nomear($this->colonia('k14'), CargosCivicosSpecs::REPORTER);

        $this->artisan(TickColonies::class)->assertSuccessful();

        $this->assertSame(1, Ledger::where('type', 'salario_cargo_civico')->count());
    }
}
