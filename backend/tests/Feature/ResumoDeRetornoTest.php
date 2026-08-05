<?php

namespace Tests\Feature;

use App\Domain\Colony\CreateColony;
use App\Models\Colony;
use App\Models\Ledger;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * "Desde sua última visita" (A2.0.3; janela do GDD ALPHA 2 §5.1).
 *
 * A regra da janela tem três armadilhas, e cada uma tem teste próprio: a primeira visita não mostra
 * nada, o piso de uma hora silencia quem recarrega a página, e **abrir não pode consumir a janela**.
 */
class ResumoDeRetornoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\ResourceTypeSeeder::class);
        $this->seed(\Database\Seeders\BuildingSpecSeeder::class);
    }

    private int $proximoSlot = 3;

    private function colonoComColonia(): array
    {
        $u = User::create([
            'name' => 'Colono', 'nickname' => 'c'.random_int(10000, 99999),
            'email' => 'c'.random_int(10000, 99999).'@fertways.test',
            'password' => Hash::make('segredo-forte-123'),
        ]);

        $c = app(CreateColony::class)->handle($u, 'Colônia', 0, $this->proximoSlot++);

        /*
         * Fundar escreve no ledger — `saldo_inicial` (100 F$) e `kit_inicial` — sempre com `now()`,
         * que cai DENTRO da janela de qualquer teste. Sem empurrar isso para o passado, todo teste
         * de conteúdo mediria o kit inicial junto com o que o teste inseriu, e "janela sem nada"
         * nunca estaria vazia.
         *
         * Pelo query builder de propósito: o `Ledger` é append-only e recusa `update` no modelo. Isto
         * é fixture de teste passando por baixo da trava conscientemente, e não código de produção.
         */
        \DB::table('ledger')->where('colony_id', $c->id)
            ->update(['created_at' => now()->subDays(30)]);

        return [$u->fresh(), $c];
    }

    private function lancar(Colony $c, string $tipo, int $quanto, ?string $recurso, string $quando): void
    {
        Ledger::create([
            'colony_id' => $c->id, 'type' => $tipo, 'amount' => $quanto,
            'resource_type' => $recurso, 'created_at' => $quando,
        ]);
    }

    // ────────────────────────────────────────────────────────────── a janela

    /**
     * O §5.1 é explícito: não há "desde a última visita" quando não houve visita anterior.
     *
     * Mostrar aqui apresentaria a fundação da própria colônia como novidade — para quem acabou de
     * chegar, o pior primeiro contato possível.
     */
    public function test_primeira_vez_nao_mostra_e_planta_o_marcador(): void
    {
        [$u] = $this->colonoComColonia();
        $this->assertNull($u->resumo_visto_em);

        $r = $this->actingAs($u)->getJson('/resumo')->assertOk()->json();

        $this->assertFalse($r['mostrar']);
        $this->assertSame('primeira_vez', $r['motivo']);
        $this->assertNotNull($u->fresh()->resumo_visto_em);
    }

    /** Quem recarrega a página, ou entra três vezes seguidas, não leva um modal a cada visita. */
    public function test_piso_de_uma_hora_silencia(): void
    {
        [$u] = $this->colonoComColonia();
        $u->forceFill(['resumo_visto_em' => now()->subMinutes(59)])->save();

        $r = $this->actingAs($u)->getJson('/resumo')->assertOk()->json();

        $this->assertFalse($r['mostrar']);
        $this->assertSame('piso_de_uma_hora', $r['motivo']);
    }

    public function test_passada_uma_hora_o_resumo_aparece(): void
    {
        [$u] = $this->colonoComColonia();
        $u->forceFill(['resumo_visto_em' => now()->subMinutes(61)])->save();

        $this->actingAs($u)->getJson('/resumo')->assertOk()->assertJson(['mostrar' => true]);
    }

    /**
     * O mesmo caminho, mas com o usuário **recarregado do banco** — e não é redundância.
     *
     * `actingAs($u)` guarda a instância em memória, onde `resumo_visto_em` ainda é um Carbon vindo
     * do `forceFill`. Em produção o usuário chega pelo Sanctum, lido do banco, e sem o cast do
     * modelo o atributo viria como **string**: o `->copy()->addMinutes()` do piso estouraria. Foi
     * assim que o cast faltante apareceu, e este teste é o que impede a volta dele.
     */
    public function test_marcador_lido_do_banco_continua_sendo_data(): void
    {
        [$u] = $this->colonoComColonia();
        $u->forceFill(['resumo_visto_em' => now()->subMinutes(61)])->save();

        $doBanco = User::findOrFail($u->id);
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $doBanco->resumo_visto_em);

        $this->actingAs($doBanco)->getJson('/resumo')->assertOk()->assertJson(['mostrar' => true]);
    }

    /**
     * A invariante mais importante do arquivo.
     *
     * Se o GET movesse o marcador, abrir a tela e fechá-la sem ler já teria consumido a janela — e
     * o que aconteceu enquanto o jogador estava fora sumiria sem nunca ter sido mostrado.
     */
    public function test_abrir_o_resumo_nao_move_o_marcador(): void
    {
        [$u] = $this->colonoComColonia();
        $marca = now()->subHours(5);
        $u->forceFill(['resumo_visto_em' => $marca])->save();

        $this->actingAs($u)->getJson('/resumo')->assertOk();

        $this->assertSame(
            $marca->toDateTimeString(),
            $u->fresh()->resumo_visto_em->toDateTimeString(),
        );
    }

    public function test_fechar_o_resumo_move_o_marcador(): void
    {
        [$u] = $this->colonoComColonia();
        $u->forceFill(['resumo_visto_em' => now()->subHours(5)])->save();

        $this->actingAs($u)->postJson('/resumo/visto')->assertOk();

        $this->assertTrue($u->fresh()->resumo_visto_em->greaterThan(now()->subMinute()));
    }

    /** Fechar duas vezes seguidas não pode estourar nada: é o duplo clique de sempre. */
    public function test_fechar_duas_vezes_e_inofensivo(): void
    {
        [$u] = $this->colonoComColonia();
        $u->forceFill(['resumo_visto_em' => now()->subHours(5)])->save();

        $this->actingAs($u)->postJson('/resumo/visto')->assertOk();
        $this->actingAs($u)->postJson('/resumo/visto')->assertOk();
    }

    // ──────────────────────── reabrir: o botão que não abria nada (2026-08-05)

    /**
     * ⚠️ O defeito, em um teste: fechar o resumo e clicar em "Ver o que aconteceu desde sua última
     * visita" não abria nada.
     *
     * A causa não era a tela. `resumo_visto_em` avança ao FECHAR, então um minuto depois a "última
     * visita" era um minuto atrás — janela vazia — e o piso de uma hora ainda barrava por cima. O
     * botão pedia uma janela que ele mesmo tinha acabado de consumir.
     */
    public function test_reabrir_mostra_a_janela_anterior_mesmo_dentro_do_piso(): void
    {
        [$u] = $this->colonoComColonia();
        $u->forceFill(['resumo_visto_em' => now()->subHours(5)])->save();

        // O jogador viu e fechou: o marcador avança e a janela de 5 h vira a ANTERIOR.
        $this->actingAs($u)->postJson('/resumo/visto')->assertOk();

        // Sem `reabrir`, o piso silencia — e é isso que ele deve continuar fazendo.
        $auto = $this->actingAs($u)->getJson('/resumo')->assertOk()->json();
        $this->assertFalse($auto['mostrar']);
        $this->assertSame('piso_de_uma_hora', $auto['motivo']);

        // Com `reabrir`, a janela de 5 h volta inteira.
        $r = $this->actingAs($u)->getJson('/resumo?reabrir=1')->assertOk()->json();
        $this->assertTrue($r['mostrar']);
        $this->assertTrue(
            Carbon::parse($r['desde'])->lessThan(now()->subHours(4)),
            'reabrir tem de devolver a janela ANTERIOR, não um intervalo de zero minuto',
        );
    }

    /** Reler não consome: reabrir não pode mover marcador nenhum, ou só funcionaria uma vez. */
    public function test_reabrir_nao_move_os_marcadores(): void
    {
        [$u] = $this->colonoComColonia();
        $u->forceFill(['resumo_visto_em' => now()->subHours(5)])->save();
        $this->actingAs($u)->postJson('/resumo/visto')->assertOk();

        $antes = $u->fresh();

        $this->actingAs($u)->getJson('/resumo?reabrir=1')->assertOk();
        $this->actingAs($u)->getJson('/resumo?reabrir=1')->assertOk();

        $depois = $u->fresh();
        $this->assertEquals($antes->resumo_visto_em, $depois->resumo_visto_em);
        $this->assertEquals($antes->resumo_anterior_em, $depois->resumo_anterior_em);
    }

    /** Quem nunca fechou um resumo não tem o que reabrir — e o servidor diz isso, em vez de inventar. */
    public function test_sem_janela_anterior_nao_ha_o_que_reabrir(): void
    {
        [$u] = $this->colonoComColonia();
        $u->forceFill(['resumo_visto_em' => null, 'resumo_anterior_em' => null])->save();

        $r = $this->actingAs($u)->getJson('/resumo?reabrir=1')->assertOk()->json();

        $this->assertFalse($r['mostrar']);
        $this->assertSame('sem_janela_anterior', $r['motivo']);
    }

    // ──────────────────────────────────────────────────────────── o conteúdo

    public function test_producao_da_janela_aparece_agregada_e_ordenada(): void
    {
        [$u, $c] = $this->colonoComColonia();
        $u->forceFill(['resumo_visto_em' => now()->subHours(5)])->save();

        $this->lancar($c, 'producao', 10, 'agua', now()->subHours(4)->toDateTimeString());
        $this->lancar($c, 'producao', 90, 'agua', now()->subHours(3)->toDateTimeString());
        $this->lancar($c, 'producao', 50, 'metal_bruto', now()->subHours(2)->toDateTimeString());

        $r = $this->actingAs($u)->getJson('/resumo')->assertOk()->json();

        $this->assertSame('agua', $r['producao'][0]['recurso']);
        $this->assertSame(100, $r['producao'][0]['quantidade']);
        $this->assertSame('metal_bruto', $r['producao'][1]['recurso']);
    }

    /** O que aconteceu ANTES da marca já foi visto. Repeti-lo seria mentir sobre a janela. */
    public function test_o_que_e_anterior_a_marca_fica_de_fora(): void
    {
        [$u, $c] = $this->colonoComColonia();
        $u->forceFill(['resumo_visto_em' => now()->subHours(2)])->save();

        $this->lancar($c, 'producao', 777, 'agua', now()->subHours(6)->toDateTimeString());

        $r = $this->actingAs($u)->getJson('/resumo')->assertOk()->json();

        $this->assertSame([], $r['producao']);
    }

    /**
     * O Fert$ reusa a arbitragem do D-163: escrow é mudança de lugar, não ganho.
     *
     * Sem isso, o resumo diria ao colono que ele "ganhou" o que apenas prendeu numa ordem de venda.
     */
    public function test_fert_usa_a_direcao_do_ledger_e_ignora_o_indefinido(): void
    {
        [$u, $c] = $this->colonoComColonia();
        $u->forceFill(['resumo_visto_em' => now()->subHours(5)])->save();

        $this->lancar($c, 'venda_mercado', 7_000_000, null, now()->subHours(3)->toDateTimeString());
        // Negativo: é o que o domínio escreve para saída.
        $this->lancar($c, 'tributo', -1_000_000, null, now()->subHours(3)->toDateTimeString());
        $this->lancar($c, 'escrow_mercado', 999_000_000, null, now()->subHours(3)->toDateTimeString());

        $r = $this->actingAs($u)->getJson('/resumo')->assertOk()->json();

        $this->assertSame(7_000_000, $r['fert_ganho_micro']);
        $this->assertSame(1_000_000, $r['fert_gasto_micro']);
    }

    /**
     * "Nada aconteceu" é resultado legítimo e precisa ser dizível.
     *
     * Quem passou dois dias fora com a colônia parada PRECISA ver que não produziu nada. Um resumo
     * que só aparece quando há boa notícia esconde exatamente o que mais importa.
     */
    public function test_janela_sem_nada_e_marcada_como_vazia(): void
    {
        [$u] = $this->colonoComColonia();
        $u->forceFill(['resumo_visto_em' => now()->subHours(5)])->save();

        $r = $this->actingAs($u)->getJson('/resumo')->assertOk()->json();

        $this->assertTrue($r['mostrar']);
        $this->assertTrue($r['vazio']);
    }

    /**
     * A obra concluída sai do `build_queue`, e não de `buildings`.
     *
     * O tick zera `upgrade_finish_at` ao concluir — a construção não guarda QUANDO subiu de nível.
     * A fila guarda, com `status = 'done'`, e ninguém apaga essas linhas.
     */
    public function test_obra_concluida_na_janela_aparece(): void
    {
        [$u, $c] = $this->colonoComColonia();
        $u->forceFill(['resumo_visto_em' => now()->subHours(5)])->save();

        $predio = $c->buildings()->first();

        \DB::table('build_queue')->insert([
            'colony_id' => $c->id,
            'building_id' => $predio->id,
            'target_level' => 3,
            'quoted_cost_json' => json_encode([]),
            'subsidized' => false,
            'enqueued_at' => now()->subHours(4),
            'starts_at' => now()->subHours(4),
            'finishes_at' => now()->subHours(2),
            'status' => 'done',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $r = $this->actingAs($u)->getJson('/resumo')->assertOk()->json();

        $this->assertCount(1, $r['obras_concluidas']);
        $this->assertSame(3, $r['obras_concluidas'][0]['nivel']);
        $this->assertFalse($r['vazio']);
    }

    public function test_sem_colonia_nao_ha_o_que_resumir(): void
    {
        $u = User::create([
            'name' => 'Sem', 'nickname' => 'sem'.random_int(1000, 9999),
            'email' => 'sem'.random_int(1000, 9999).'@fertways.test',
            'password' => Hash::make('segredo-forte-123'),
        ]);

        $r = $this->actingAs($u)->getJson('/resumo')->assertOk()->json();

        $this->assertFalse($r['mostrar']);
        $this->assertSame('sem_colonia', $r['motivo']);
    }

    public function test_resumo_exige_autenticacao(): void
    {
        $this->getJson('/resumo')->assertStatus(401);
    }
}
