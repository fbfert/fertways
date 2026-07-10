<?php

namespace Tests\Feature;

use App\Domain\Colony\CreateColony;
use App\Domain\Logistics\ConcluirTrechos;
use App\Domain\Logistics\DespacharVeiculo;
use App\Domain\Market\ColocarOrdem;
use App\Domain\Trade\AcordoSpecs;
use App\Domain\Trade\ConfirmarAcordo;
use App\Domain\Trade\ExpirarAcordos;
use App\Domain\Trade\ProporAcordo;
use App\Exceptions\DomainRuleException;
use App\Models\Colony;
use App\Models\ResourceType;
use App\Models\TradeAgreement;
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
 * Acordo de Troca — GDD §26.5, e as arbitragens D-40 a D-43.
 *
 * O que se testa aqui, antes de tudo, é que o Acordo **não protege ninguém**: propor e aceitar não
 * movem um grama. Se um dia alguém introduzir escrow "para ajudar", estes testes caem.
 */
class AcordoDeTrocaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ResourceTypeSeeder::class);
        $this->seed(ComponentRecipeSeeder::class);
        $this->seed(BuildingSpecSeeder::class);
    }

    /** Vizinhas a 30 slots: o Caminhão leva 1200 s, então o prazo mínimo é 1200 s + 12 h. */
    private function duasColonias(): array
    {
        return [
            $this->colonia('a@t.test', 'alfa', 10, 10),
            $this->colonia('b@t.test', 'beta', 40, 10),
        ];
    }

    private function colonia(string $email, string $nick, int $x, int $y): Colony
    {
        $user = User::factory()->create(['email' => $email, 'nickname' => $nick]);
        // O colono escolhe a célula (D-51). As coords dos testes são de periferia — fundáveis —
        // e a distância entre colônias, que é o que importa aqui, independe de onde fica a Capital.
        $colony = app(CreateColony::class)->handle($user, "Colônia {$nick}", $x, $y);

        return $colony->fresh();
    }

    private function abastecer(Colony $c, array $recursos): void
    {
        foreach ($recursos as $recurso => $qtd) {
            $c->resources()->where('resource_type', $recurso)->update(['amount' => $qtd]);
        }
    }

    private function furgao(Colony $c): Vehicle
    {
        return $c->vehicles()->where('type', 'furgao_de_comercio')->firstOrFail();
    }

    private function estoque(Colony $c, string $recurso): int
    {
        return (int) $c->resources()->where('resource_type', $recurso)->value('amount');
    }

    private function confianca(Colony $c): int
    {
        return (int) $c->fresh()->user->confianca_comercial;
    }

    private function prazoValido(Colony $a, Colony $b): Carbon
    {
        return now()->addSeconds(AcordoSpecs::prazoMinimoSegundos(30))->addMinute();
    }

    /**
     * Um acordo caro o bastante para cruzar o piso de 500 F$ do §26.3.
     *
     * Precisa de recurso raro: Bioenergia Curativa custa 0,506 F$ a unidade, e 1.000 unidades já
     * valem 506 F$. Água a 0,0062 exigiria 80 mil unidades — mais que a capacidade do Furgão.
     */
    private function acordoGordo(Colony $a, Colony $b): TradeAgreement
    {
        return app(ProporAcordo::class)->handle(
            $a, $b,
            ['bioenergia_curativa' => 1_000],
            ['agua' => 2_000],
            $this->prazoValido($a, $b),
        );
    }

    #[Test]
    public function propor_e_aceitar_nao_reservam_nada_o_calote_continua_possivel(): void
    {
        [$a, $b] = $this->duasColonias();
        $this->abastecer($a, ['bioenergia_curativa' => 5_000]);

        $antes = $this->estoque($a, 'bioenergia_curativa');
        $acordo = $this->acordoGordo($a, $b);
        app(ConfirmarAcordo::class)->handle($b, $acordo);

        // §26.5: "sem usar bloqueio automático de recursos (escrow)". D-40.
        $this->assertSame($antes, $this->estoque($a->fresh(), 'bioenergia_curativa'));
        $this->assertSame('aceito', $acordo->fresh()->status);
    }

    #[Test]
    public function so_a_contraparte_fecha_o_aperto_de_mao(): void
    {
        [$a, $b] = $this->duasColonias();
        $acordo = $this->acordoGordo($a, $b);

        // §26.5: o proponente já aderiu ao propor. Confirmar sozinho fabricaria evidência.
        $this->expectException(DomainRuleException::class);
        app(ConfirmarAcordo::class)->handle($a, $acordo);
    }

    #[Test]
    public function proposta_nao_confirmada_expira_sem_punir_ninguem(): void
    {
        [$a, $b] = $this->duasColonias();
        $acordo = $this->acordoGordo($a, $b);

        $this->travelTo(now()->addDays(2));
        app(ExpirarAcordos::class)->handle();

        // "Uma proposta registrada mas não confirmada não tem valor de evidência completa" (§26.5).
        $this->assertSame('cancelado', $acordo->fresh()->status);
        $this->assertSame(AcordoSpecs::CONFIANCA_INICIAL, $this->confianca($a));
        $this->assertSame(AcordoSpecs::CONFIANCA_INICIAL, $this->confianca($b));
    }

    #[Test]
    public function prazo_curto_demais_para_a_viagem_e_recusado(): void
    {
        [$a, $b] = $this->duasColonias();

        $this->expectException(DomainRuleException::class);
        app(ProporAcordo::class)->handle($a, $b, ['metal_bruto' => 10], ['agua' => 10], now()->addHour());
    }

    #[Test]
    public function o_prazo_minimo_e_a_viagem_do_caminhao_mais_doze_horas(): void
    {
        // §21.3: Caminhão a 1,5 slot/min. 30 slots = 1200 s. D-42: + 12 h de folga.
        $this->assertSame(1200 + 12 * 3600, AcordoSpecs::prazoMinimoSegundos(30));
    }

    #[Test]
    public function acordo_so_e_abatido_pela_carga_que_o_aponta(): void
    {
        [$a, $b] = $this->duasColonias();
        $this->abastecer($a, ['bioenergia_curativa' => 5_000, 'energia' => 5_000]);

        $acordo = $this->acordoGordo($a, $b);
        app(ConfirmarAcordo::class)->handle($b, $acordo);

        // Despacho SEM apontar o acordo: é um presente, não um pagamento (D-41).
        app(DespacharVeiculo::class)->handle($a, $this->furgao($a), 'colonia', $b->id, ['bioenergia_curativa' => 1_000]);
        $this->travelTo(now()->addHours(1));
        app(ConcluirTrechos::class)->handle();

        $this->assertSame([], $acordo->fresh()->entregue($a->id));
    }

    #[Test]
    public function cumprir_conta_o_liquido_que_chega_nao_o_bruto_despachado(): void
    {
        [$a, $b] = $this->duasColonias();
        $this->abastecer($a, ['bioenergia_curativa' => 5_000, 'energia' => 5_000]);

        $acordo = app(ProporAcordo::class)->handle(
            $a, $b, ['bioenergia_curativa' => 1_000], ['agua' => 1_000], $this->prazoValido($a, $b),
        );
        app(ConfirmarAcordo::class)->handle($b, $acordo);

        // Despacha exatamente o prometido. O tributo do §25.2 come uma fatia na entrega.
        app(DespacharVeiculo::class)->handle($a, $this->furgao($a), 'colonia', $b->id, ['bioenergia_curativa' => 1_000], $acordo->id);
        $this->travelTo(now()->addHours(1));
        app(ConcluirTrechos::class)->handle();

        $bps = (int) ResourceType::find('bioenergia_curativa')->tax_bps;
        $liquido = 1_000 - intdiv(1_000 * $bps, 10_000);

        // Chegou menos do que se prometeu: quem despachou o bruto exato NÃO cumpriu (D-41).
        $this->assertSame($liquido, $acordo->fresh()->entregue($a->id)['bioenergia_curativa']);
        $this->assertFalse($acordo->fresh()->cumpriu($a->id));
    }

    #[Test]
    public function o_bruto_necessario_faz_o_liquido_bater_exatamente(): void
    {
        foreach (['metal_bruto', 'agua', 'bioenergia_curativa'] as $recurso) {
            $bps = (int) ResourceType::find($recurso)->tax_bps;

            foreach ([1, 7, 999, 1_000, 12_345] as $liquido) {
                $bruto = AcordoSpecs::brutoParaLiquido($liquido, $bps);
                $chegou = $bruto - intdiv($bruto * $bps, 10_000);

                $this->assertGreaterThanOrEqual($liquido, $chegou, "{$recurso} {$liquido}");
                // E não exagera: um a menos já não bastaria.
                $menor = $bruto - 1;
                $this->assertLessThan($liquido, $menor - intdiv($menor * $bps, 10_000), "{$recurso} {$liquido}");
            }
        }
    }

    #[Test]
    public function acordo_cumprido_pelos_dois_lados_se_executa_e_premia_ambos(): void
    {
        [$a, $b] = $this->duasColonias();
        $this->abastecer($a, ['bioenergia_curativa' => 9_000, 'energia' => 9_000]);
        $this->abastecer($b, ['agua' => 9_000, 'energia' => 9_000]);

        $acordo = $this->acordoGordo($a, $b);
        app(ConfirmarAcordo::class)->handle($b, $acordo);

        $brutoA = AcordoSpecs::brutoParaLiquido(1_000, (int) ResourceType::find('bioenergia_curativa')->tax_bps);
        $brutoB = AcordoSpecs::brutoParaLiquido(2_000, (int) ResourceType::find('agua')->tax_bps);

        app(DespacharVeiculo::class)->handle($a, $this->furgao($a), 'colonia', $b->id, ['bioenergia_curativa' => $brutoA], $acordo->id);
        app(DespacharVeiculo::class)->handle($b, $this->furgao($b), 'colonia', $a->id, ['agua' => $brutoB], $acordo->id);

        $this->travelTo(now()->addHours(1));
        app(ConcluirTrechos::class)->handle();

        $this->assertSame('executado', $acordo->fresh()->status);
        $this->assertSame(AcordoSpecs::CONFIANCA_INICIAL + AcordoSpecs::GANHO_CUMPRIDO, $this->confianca($a));
        $this->assertSame(AcordoSpecs::CONFIANCA_INICIAL + AcordoSpecs::GANHO_CUMPRIDO, $this->confianca($b));
    }

    #[Test]
    public function quem_nao_entrega_no_prazo_perde_cinquenta_e_quem_entregou_nao_perde(): void
    {
        [$a, $b] = $this->duasColonias();
        $this->abastecer($a, ['bioenergia_curativa' => 9_000, 'energia' => 9_000]);

        $acordo = $this->acordoGordo($a, $b);
        app(ConfirmarAcordo::class)->handle($b, $acordo);

        // A entrega; B calotea.
        $brutoA = AcordoSpecs::brutoParaLiquido(1_000, (int) ResourceType::find('bioenergia_curativa')->tax_bps);
        app(DespacharVeiculo::class)->handle($a, $this->furgao($a), 'colonia', $b->id, ['bioenergia_curativa' => $brutoA], $acordo->id);

        $this->travelTo(now()->addHours(1));
        app(ConcluirTrechos::class)->handle();

        $this->travelTo($acordo->fresh()->deadline_at->copy()->addMinute());
        app(ExpirarAcordos::class)->handle();

        $this->assertSame('quebrado', $acordo->fresh()->status);
        $this->assertSame(AcordoSpecs::CONFIANCA_INICIAL, $this->confianca($a), 'quem entregou não é punido');
        $this->assertSame(AcordoSpecs::CONFIANCA_INICIAL - AcordoSpecs::PERDA_QUEBRADO, $this->confianca($b));
    }

    #[Test]
    public function acordo_abaixo_do_piso_de_500_fert_nao_move_reputacao(): void
    {
        [$a, $b] = $this->duasColonias();

        // 1 unidade de cada lado: valor de mercado muito abaixo dos 500 F$ do §26.3.
        $acordo = app(ProporAcordo::class)->handle(
            $a, $b, ['metal_bruto' => 1], ['agua' => 1], $this->prazoValido($a, $b),
        );
        app(ConfirmarAcordo::class)->handle($b, $acordo);

        $this->assertLessThan(AcordoSpecs::PISO_REPUTACAO_MICRO, $acordo->value_micro);

        $this->travelTo($acordo->deadline_at->copy()->addMinute());
        app(ExpirarAcordos::class)->handle();

        // Registra o calote no histórico, mas não move o índice: senão dois amigos farmariam
        // reputação com microtransações (§26.1, §26.4, D-43).
        $this->assertSame('quebrado', $acordo->fresh()->status);
        $this->assertSame(AcordoSpecs::CONFIANCA_INICIAL, $this->confianca($a));
        $this->assertSame(AcordoSpecs::CONFIANCA_INICIAL, $this->confianca($b));
    }

    #[Test]
    public function expirar_duas_vezes_nao_pune_duas_vezes(): void
    {
        [$a, $b] = $this->duasColonias();
        $acordo = $this->acordoGordo($a, $b);
        app(ConfirmarAcordo::class)->handle($b, $acordo);

        $this->travelTo($acordo->deadline_at->copy()->addMinute());
        app(ExpirarAcordos::class)->handle();
        app(ExpirarAcordos::class)->handle();

        // Dois crons sobrepostos não podem cobrar o mesmo calote duas vezes.
        $this->assertSame(AcordoSpecs::CONFIANCA_INICIAL - AcordoSpecs::PERDA_QUEBRADO, $this->confianca($a));
    }

    #[Test]
    public function confianca_baixa_fecha_o_mercado_central(): void
    {
        [$a, $b] = $this->duasColonias();
        $a->user->forceFill(['confianca_comercial' => AcordoSpecs::LIMIAR_MERCADO - 1])->save();

        $this->expectException(DomainRuleException::class);
        app(ColocarOrdem::class)->handle($a->fresh(), 'buy', 'agua', 10, 1_000);
    }

    #[Test]
    public function colono_nasce_no_meio_da_escala_e_o_mercado_esta_aberto(): void
    {
        [$a] = $this->duasColonias();

        // D-43: nascer em 0 fecharia o Mercado para todo mundo no dia um.
        $this->assertSame(AcordoSpecs::CONFIANCA_INICIAL, $this->confianca($a));
        $this->assertGreaterThanOrEqual(AcordoSpecs::LIMIAR_MERCADO, $this->confianca($a));
    }

    #[Test]
    public function despacho_apontando_acordo_alheio_e_recusado(): void
    {
        [$a, $b] = $this->duasColonias();
        $c = $this->colonia('c@t.test', 'gama', 20, 30);
        $this->abastecer($a, ['bioenergia_curativa' => 5_000, 'energia' => 5_000]);

        $acordo = $this->acordoGordo($a, $b);
        app(ConfirmarAcordo::class)->handle($b, $acordo);

        // A carga de um acordo tem de ir para a contraparte, não para um terceiro.
        $this->expectException(DomainRuleException::class);
        app(DespacharVeiculo::class)->handle($a, $this->furgao($a), 'colonia', $c->id, ['bioenergia_curativa' => 100], $acordo->id);
    }

    #[Test]
    public function acordo_aceito_nao_se_cancela(): void
    {
        [$a, $b] = $this->duasColonias();
        $acordo = $this->acordoGordo($a, $b);
        app(ConfirmarAcordo::class)->handle($b, $acordo);

        // Cancelar depois do aperto de mão seria a saída fácil de quem se arrependeu.
        $this->expectException(DomainRuleException::class);
        app(ConfirmarAcordo::class)->cancelar($a, $acordo->fresh());
    }

    #[Test]
    public function fert_nao_pode_ser_prometido_porque_nao_se_entrega(): void
    {
        [$a, $b] = $this->duasColonias();

        // D-41: o jogo não move Fert$ entre colônias. Prometer o que não se entrega é calote certo.
        $this->expectException(DomainRuleException::class);
        app(ProporAcordo::class)->handle($a, $b, ['fert' => 100], ['agua' => 10], $this->prazoValido($a, $b));
    }
}
