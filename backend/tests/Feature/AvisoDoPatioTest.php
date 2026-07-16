<?php

namespace Tests\Feature;

use App\Domain\Capital\Patio;
use App\Domain\Chat\ContaSistema;
use App\Domain\Colony\CreateColony;
use App\Domain\Logistics\ConcluirTrechos;
use App\Domain\Logistics\DespacharVeiculo;
use App\Models\ChatMessage;
use App\Models\Colony;
use App\Models\User;
use App\Models\Vehicle;
use Database\Seeders\BuildingSpecSeeder;
use Database\Seeders\ComponentRecipeSeeder;
use Database\Seeders\ResourceTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A Capital avisa pelo rádio quando um veículo fica parado no Pátio (D-91).
 *
 * Pedido do usuário: sem isto, um veículo podia ficar semanas ali, comendo Fert$ hora a hora,
 * sem que nada avisasse quem não abrisse a tela do Mercado por conta própria.
 */
class AvisoDoPatioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ResourceTypeSeeder::class);
        $this->seed(ComponentRecipeSeeder::class);
        $this->seed(BuildingSpecSeeder::class);
    }

    private function colonia(string $nick = 'aviso', int $x = 30, int $y = 0): Colony
    {
        $user = User::factory()->create(['email' => "{$nick}@t.test", 'nickname' => $nick]);

        return app(CreateColony::class)->handle($user, "Colônia {$nick}", $x, $y)->fresh();
    }

    private function abastecer(Colony $c, array $recursos): void
    {
        foreach ($recursos as $recurso => $qtd) {
            $c->resources()->where('resource_type', $recurso)->update(['amount' => $qtd]);
        }
    }

    private function ultimoAvisoPara(User $destinatario): ?ChatMessage
    {
        return ChatMessage::where('channel', 'privada')
            ->where('recipient_user_id', $destinatario->id)
            ->orderByDesc('id')
            ->first();
    }

    /** A conta reservada pela migration existe, sem colônia, e não é jogável. */
    #[Test]
    public function a_conta_capital_existe_e_nao_tem_colonia(): void
    {
        $capital = ContaSistema::capital();

        $this->assertSame('Capital', $capital->nickname);
        $this->assertNull($capital->colony()->first());
    }

    #[Test]
    public function ao_estacionar_a_capital_avisa_a_tarifa_por_hora(): void
    {
        $c = $this->colonia();
        $this->abastecer($c, ['metal_bruto' => 500, 'energia' => 100]);

        app(DespacharVeiculo::class)->handle($c, $c->vehicles()->where('type', 'furgao_de_comercio')->firstOrFail(), 'mercado_central', null, ['metal_bruto' => 500]);

        Carbon::setTestNow(now()->addMinutes(8));
        app(ConcluirTrechos::class)->handle();

        $aviso = $this->ultimoAvisoPara($c->user);

        $this->assertNotNull($aviso, 'a Capital mandou um aviso ao estacionar');
        $this->assertSame(ContaSistema::capital()->id, $aviso->user_id);
        $this->assertStringContainsString('0,005', $aviso->body);
        $this->assertStringContainsString('Pátio', $aviso->body);

        Carbon::setTestNow();
    }

    #[Test]
    public function antes_de_24h_nao_ha_lembrete_novo(): void
    {
        $c = $this->colonia();
        $this->abastecer($c, ['metal_bruto' => 500, 'energia' => 100]);
        $v = $c->vehicles()->where('type', 'furgao_de_comercio')->firstOrFail();

        app(DespacharVeiculo::class)->handle($c, $v, 'mercado_central', null, ['metal_bruto' => 500]);

        Carbon::setTestNow(now()->addMinutes(8));
        app(ConcluirTrechos::class)->handle();
        $primeiro = $this->ultimoAvisoPara($c->user);

        Carbon::setTestNow(now()->addHours(23));
        app(Patio::class)->handle();

        $this->assertSame($primeiro->id, $this->ultimoAvisoPara($c->user)->id, 'ainda o mesmo aviso, antes das 24h');

        Carbon::setTestNow();
    }

    #[Test]
    public function apos_24h_a_capital_manda_um_lembrete(): void
    {
        $c = $this->colonia();
        $c->forceFill(['fert_micro' => 1_000_000_000])->save(); // paga a tarifa sem ser rebocado
        $this->abastecer($c, ['metal_bruto' => 500, 'energia' => 100]);
        $v = $c->vehicles()->where('type', 'furgao_de_comercio')->firstOrFail();

        app(DespacharVeiculo::class)->handle($c, $v, 'mercado_central', null, ['metal_bruto' => 500]);

        Carbon::setTestNow(now()->addMinutes(8));
        app(ConcluirTrechos::class)->handle();
        $primeiro = $this->ultimoAvisoPara($c->user);

        Carbon::setTestNow(now()->addHours(25));
        app(Patio::class)->handle();

        $segundo = $this->ultimoAvisoPara($c->user);
        $this->assertNotSame($primeiro->id, $segundo->id, 'um aviso novo depois de 24h parado');
        $this->assertStringContainsString('continua parado', $segundo->body);

        Carbon::setTestNow();
    }

    /** Rebocado por falta de Fert$ não recebe lembrete — já está a caminho de casa, de graça. */
    #[Test]
    public function rebocado_nao_recebe_lembrete(): void
    {
        $c = $this->colonia();
        $c->forceFill(['fert_micro' => 0])->save();
        $this->abastecer($c, ['metal_bruto' => 500, 'energia' => 100]);
        $v = $c->vehicles()->where('type', 'furgao_de_comercio')->firstOrFail();

        app(DespacharVeiculo::class)->handle($c, $v, 'mercado_central', null, ['metal_bruto' => 500]);

        Carbon::setTestNow(now()->addMinutes(8));
        app(ConcluirTrechos::class)->handle();
        $primeiro = $this->ultimoAvisoPara($c->user);

        // 25h sem Fert$: a primeira cobrança da hora já reboca, antes de qualquer lembrete de 24h.
        Carbon::setTestNow(now()->addHours(25));
        $fora = app(Patio::class)->handle();

        $this->assertSame(1, $fora['rebocados']);
        $this->assertSame($primeiro->id, $this->ultimoAvisoPara($c->user)->id, 'nenhum aviso novo — foi rebocado, não lembrado');

        Carbon::setTestNow();
    }
}
