<?php

namespace Tests\Feature;

use App\Domain\Telemetria\DirecaoDoLedger;
use App\Domain\Telemetria\RegistrarEvento;
use App\Models\Colony;
use App\Models\Ledger;
use App\Models\TelemetryDaily;
use App\Models\TelemetryEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

/**
 * Telemetria de gameplay (A2.0.1 e A2.0.1.1).
 *
 * O que estes testes guardam não é o caminho feliz — é o conjunto de invariantes sem as quais a
 * telemetria vira um número bonito em que ninguém pode confiar.
 */
class TelemetriaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\ResourceTypeSeeder::class);
        $this->seed(\Database\Seeders\BuildingSpecSeeder::class);
    }

    /**
     * Os testes do retrato diário usam um dia de **2020**, e não uma data próxima de hoje.
     *
     * Fundar uma colônia escreve no ledger (`saldo_inicial`, `kit_inicial`), sempre com `now()`. Se
     * o dia agregado fosse perto de hoje, esses lançamentos entrariam na conta e os números do teste
     * mudariam conforme a data em que a suíte roda — o pior tipo de teste, o que passa hoje e
     * reprova em janeiro sem ninguém ter mexido em nada.
     */
    private const DIA = '2020-01-15';

    private function colono(string $email = 'colono@fertways.test'): User
    {
        return User::create([
            'name' => 'Colono', 'nickname' => 'colono'.random_int(1000, 9999),
            'email' => $email, 'password' => Hash::make('segredo-forte-123'),
        ]);
    }

    // ───────────────────────────────────────────────── a classificação do ledger

    /**
     * O teste mais importante do arquivo.
     *
     * Todo tipo do ledger precisa ter direção declarada. Sem isto, um tipo novo entraria mudo na
     * agregação e o retrato diário passaria a mentir por omissão — sem erro, sem log, sem sintoma
     * até alguém decidir balanceamento em cima do gráfico.
     */
    public function test_todo_tipo_do_ledger_tem_direcao_declarada(): void
    {
        $pendentes = app(DirecaoDoLedger::class)->naoClassificados();

        $this->assertSame(
            [], $pendentes,
            'Tipos de ledger sem direção em DirecaoDoLedger: '.implode(', ', $pendentes)
        );
    }

    public function test_tipo_desconhecido_explode_em_vez_de_virar_neutro(): void
    {
        $this->expectException(RuntimeException::class);

        app(DirecaoDoLedger::class)->contaNoFluxo('tipo_que_nao_existe');
    }

    /** Nenhum tipo pode estar nos dois lados: contaria duas vezes ou nenhuma. */
    public function test_os_baldes_nao_se_sobrepoem(): void
    {
        $todos = array_merge(DirecaoDoLedger::CONTA, DirecaoDoLedger::NAO_CONTA);

        $this->assertSame(count($todos), count(array_unique($todos)));
    }

    /**
     * A direção sai do SINAL, e não de tabela — foi assim que a primeira versão errou.
     *
     * O ledger escreve saída como negativo (`'amount' => -$qtd`, em dezenove lugares do domínio).
     * O banco de dev não tinha um único negativo, mas só porque não tinha uma única saída, e eu
     * generalizei cedo demais. Este teste fixa o mundo real: mesmo tipo, sinais opostos, baldes
     * opostos.
     */
    public function test_a_direcao_vem_do_sinal_e_nao_do_tipo(): void
    {
        $c = $this->colonia();
        $this->lancar($c, 'producao', 100, 'metal_bruto', self::DIA.' 10:00:00');
        $this->lancar($c, 'producao', -40, 'metal_bruto', self::DIA.' 11:00:00');

        $this->artisan('fertways:telemetria-diaria', ['--dia' => self::DIA])->assertSuccessful();

        $r = TelemetryDaily::firstOrFail();
        $this->assertSame(100, $r->produzido);
        $this->assertSame(40, $r->consumido, 'o consumo é guardado em valor absoluto');
    }

    // ───────────────────────────────────────────────────────────── append-only

    public function test_evento_nao_pode_ser_alterado(): void
    {
        $e = TelemetryEvent::create(['type' => 'login', 'created_at' => now()]);

        $this->expectException(RuntimeException::class);
        $e->update(['type' => 'logout']);
    }

    public function test_evento_nao_pode_ser_apagado_avulso(): void
    {
        $e = TelemetryEvent::create(['type' => 'login', 'created_at' => now()]);

        $this->expectException(RuntimeException::class);
        $e->delete();
    }

    public function test_tipo_invalido_e_recusado(): void
    {
        $this->expectException(RuntimeException::class);

        TelemetryEvent::create(['type' => 'inventei_um_tipo', 'created_at' => now()]);
    }

    /** Bot não é origem: bots são externos e jogam em staging (GDD ALPHA 2 §14). */
    public function test_origem_invalida_e_recusada(): void
    {
        $this->expectException(RuntimeException::class);

        TelemetryEvent::create(['type' => 'login', 'origin' => 'bot', 'created_at' => now()]);
    }

    // ─────────────────────────────────────────── medir não pode derrubar a jogada

    /**
     * A invariante que dá forma ao `RegistrarEvento`: se a telemetria falhar, a jogada segue.
     *
     * Um tipo inválido faria o modelo lançar. O serviço tem que engolir e devolver null — nunca
     * propagar para o despacho de carga que só queria ser observado.
     */
    public function test_falha_de_telemetria_nao_propaga(): void
    {
        $evento = app(RegistrarEvento::class)->handle('tipo_que_nao_existe', $this->colono());

        $this->assertNull($evento);
        $this->assertSame(0, TelemetryEvent::count());
    }

    public function test_evento_de_sistema_nao_tem_dono_e_e_marcado(): void
    {
        app(RegistrarEvento::class)->sistema('evento_global', null, ['qual' => 'tempestade']);

        $e = TelemetryEvent::firstOrFail();
        $this->assertSame('sistema', $e->origin);
        $this->assertNull($e->user_id);
        $this->assertSame('tempestade', $e->payload['qual']);
    }

    // ───────────────────────────────────────────────────────── login e logout

    public function test_login_registra_evento(): void
    {
        $this->colono('entra@fertways.test');

        $this->postJson('/login', [
            'email' => 'entra@fertways.test',
            'password' => 'segredo-forte-123',
        ])->assertOk();

        $this->assertSame(1, TelemetryEvent::where('type', 'login')->count());
    }

    /** Tentativa barrada não é sessão: contá-la inflaria o DAU com quem não entrou. */
    public function test_senha_errada_nao_registra_login(): void
    {
        $this->colono('erra@fertways.test');

        $this->postJson('/login', [
            'email' => 'erra@fertways.test',
            'password' => 'senha-errada-mesmo',
        ])->assertStatus(422);

        $this->assertSame(0, TelemetryEvent::count());
    }

    // ────────────────────────────────────────────────────── o retrato diário

    private function lancar(Colony $c, string $tipo, int $quanto, string $recurso, string $quando): void
    {
        Ledger::create([
            'colony_id' => $c->id, 'type' => $tipo, 'amount' => $quanto,
            'resource_type' => $recurso, 'created_at' => $quando,
        ]);
    }

    /**
     * Pelo `CreateColony` do domínio, e não por `Colony::create`.
     *
     * Uma colônia montada à mão passa por cima do kit inicial, dos recursos e das construções
     * essenciais — e o ledger tem chave estrangeira para `colonies`, então a linha nem entra. Vale a
     * regra geral: teste que monta estado por fora do domínio testa um mundo que o jogo não produz.
     */
    private function colonia(): Colony
    {
        $dono = $this->colono('dono'.random_int(1000, 9999).'@fertways.test');

        return app(\App\Domain\Colony\CreateColony::class)
            ->handle($dono, 'Colônia', 0, $this->proximoSlot++);
    }

    private int $proximoSlot = 3;

    public function test_agrega_entrada_e_saida_separadas(): void
    {
        $c = $this->colonia();
        $this->lancar($c, 'producao', 100, 'metal_bruto', self::DIA.' 10:00:00');
        $this->lancar($c, 'producao', 50, 'metal_bruto', self::DIA.' 14:00:00');
        // Negativo, como `EnqueueUpgrade` de fato escreve.
        $this->lancar($c, 'custo_construcao', -30, 'metal_bruto', self::DIA.' 15:00:00');

        $this->artisan('fertways:telemetria-diaria', ['--dia' => self::DIA])->assertSuccessful();

        $r = TelemetryDaily::where('colony_id', $c->id)->where('resource_type', 'metal_bruto')->firstOrFail();
        $this->assertSame(150, $r->produzido);
        $this->assertSame(30, $r->consumido);
    }

    /**
     * Rodar duas vezes o mesmo dia tem que chegar ao mesmo número.
     *
     * Sem a chave única mais o upsert, uma execução repetida por engano dobraria a produção de um
     * dia inteiro — e o erro só apareceria num gráfico, semanas depois.
     */
    public function test_agregacao_e_idempotente(): void
    {
        $c = $this->colonia();
        $this->lancar($c, 'producao', 100, 'metal_bruto', self::DIA.' 10:00:00');

        $this->artisan('fertways:telemetria-diaria', ['--dia' => self::DIA])->assertSuccessful();
        $this->artisan('fertways:telemetria-diaria', ['--dia' => self::DIA])->assertSuccessful();

        $this->assertSame(1, TelemetryDaily::count());
        $this->assertSame(100, TelemetryDaily::firstOrFail()->produzido);
    }

    /** Escrow é mudança de lugar, não produção. Contá-lo inflaria a economia com dinheiro parado. */
    public function test_tipo_que_nao_conta_fica_fora(): void
    {
        $c = $this->colonia();
        $this->lancar($c, 'escrow_mercado', 999, 'metal_bruto', self::DIA.' 10:00:00');

        $this->artisan('fertways:telemetria-diaria', ['--dia' => self::DIA])->assertSuccessful();

        $this->assertSame(0, TelemetryDaily::count());
    }

    public function test_o_dia_pedido_nao_puxa_lancamento_do_dia_seguinte(): void
    {
        $c = $this->colonia();
        $this->lancar($c, 'producao', 10, 'agua', self::DIA.' 23:59:59');
        $this->lancar($c, 'producao', 777, 'agua', '2020-01-16 00:00:01');

        $this->artisan('fertways:telemetria-diaria', ['--dia' => self::DIA])->assertSuccessful();

        $this->assertSame(10, TelemetryDaily::firstOrFail()->produzido);
    }

    // ──────────────────────────────────────────────────────────── a retenção

    public function test_retencao_descarta_o_velho_e_preserva_o_novo(): void
    {
        TelemetryEvent::create(['type' => 'login', 'created_at' => now()->subDays(100)]);
        TelemetryEvent::create(['type' => 'login', 'created_at' => now()->subDays(10)]);

        $this->artisan('fertways:telemetria-limpar', ['--aplicar' => true])->assertSuccessful();

        $this->assertSame(1, TelemetryEvent::count());
    }

    /** Sem `--aplicar` é só relatório: nada some por engano. */
    public function test_retencao_sem_aplicar_nao_apaga(): void
    {
        TelemetryEvent::create(['type' => 'login', 'created_at' => now()->subDays(100)]);

        $this->artisan('fertways:telemetria-limpar')->assertSuccessful();

        $this->assertSame(1, TelemetryEvent::count());
    }

    public function test_retencao_recusa_janela_zero(): void
    {
        $this->artisan('fertways:telemetria-limpar', ['--dias' => 0, '--aplicar' => true])
            ->assertFailed();
    }
}
