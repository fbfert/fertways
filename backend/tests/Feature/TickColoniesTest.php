<?php

namespace Tests\Feature;

use App\Domain\Colony\CreateColony;
use App\Domain\Production\ColonyTick;
use App\Models\BuildQueue;
use App\Models\Ledger;
use App\Models\NeutralZone;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TickColoniesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\ResourceTypeSeeder::class);
        $this->seed(\Database\Seeders\ComponentRecipeSeeder::class);
        $this->seed(\Database\Seeders\BuildingSpecSeeder::class);
    }

    private function colono(): User
    {
        $user = User::factory()->create();
        app(CreateColony::class)->handle($user, 'Nova Aurora');

        return $user->fresh();
    }

    private function erguer(User $user, string $tipo, int $nivel): void
    {
        $user->colony->buildings()->where('type', $tipo)->update(['level' => $nivel]);
    }

    private function estoque(User $user, string $recurso): int
    {
        return $user->colony->resources()->where('resource_type', $recurso)->value('amount');
    }

    private function tick(User $user, Carbon $agora): void
    {
        app(ColonyTick::class)->handle($user->colony()->first(), $agora);
    }

    // ---- Produção por delta ----

    public function test_produz_pela_taxa_do_gdd_em_uma_hora(): void
    {
        $user = $this->colono();
        $this->erguer($user, 'gerador_de_atmosfera', 1);   // 100 oxigênio/h, consome 25 energia/h
        $this->erguer($user, 'reator_de_energia', 1);      // 150 energia/h

        $colony = $user->colony;
        $colony->update(['last_tick_at' => now()->subHour()]);

        $this->tick($user, now());

        $this->assertSame(100, $this->estoque($user, 'oxigenio'));
        // Energia líquida: 150 produzidos − 25 consumidos pelo Gerador.
        $this->assertSame(125, $this->estoque($user, 'energia'));
    }

    /**
     * Sem carregar o resto, 100/h num tick de 1 minuto viraria floor(1,67) = 1 unidade,
     * e sessenta ticks renderiam 60 em vez de 100. Perda silenciosa de 40% da economia.
     */
    public function test_resto_fracionario_nao_perde_producao_em_ticks_de_um_minuto(): void
    {
        $user = $this->colono();
        $this->erguer($user, 'gerador_de_atmosfera', 1);   // 100/h
        $this->erguer($user, 'reator_de_energia', 1);

        $t0 = now()->startOfSecond();
        $user->colony->update(['last_tick_at' => $t0]);

        for ($i = 1; $i <= 60; $i++) {
            $this->tick($user, $t0->copy()->addMinutes($i));
        }

        // Exatamente uma hora de produção, sem truncamento acumulado.
        $this->assertSame(100, $this->estoque($user, 'oxigenio'));
    }

    public function test_energia_nunca_fica_negativa(): void
    {
        $user = $this->colono();
        // Consome energia e não produz nenhuma.
        $this->erguer($user, 'gerador_de_atmosfera', 1);   // -25/h
        $this->erguer($user, 'laboratorio', 1);            // -20/h

        $user->colony->update(['last_tick_at' => now()->subHours(10)]);
        $this->tick($user, now());

        $this->assertSame(0, $this->estoque($user, 'energia'));
    }

    /**
     * D-19: Ligas Metálicas e Compostos Químicos têm taxa publicada em §19.3 e nenhuma
     * receita de insumo em lugar nenhum do GDD. Creditá-las seria criar recurso do nada.
     *
     * Componentes Eletrônicos NÃO estão nesse caso — §24.5 dá as receitas, e eles são
     * fabricados. Aqui a colônia não tem minerais, então também não saem; a cobertura da
     * fabricação está em ComponentRecipesTest.
     */
    public function test_ligas_e_compostos_nunca_sao_produzidos(): void
    {
        $user = $this->colono();
        $this->erguer($user, 'oficina', 1);
        $this->erguer($user, 'refinaria_quimica', 1);
        $this->erguer($user, 'reator_de_energia', 5);

        // Minerais de sobra: se as Ligas tivessem receita implícita, sairiam aqui.
        $user->colony->resources()
            ->whereIn('resource_type', ['estanho', 'cobre', 'silicio', 'aluminio', 'agua', 'metal_bruto'])
            ->update(['amount' => 100_000]);

        $user->colony->update(['last_tick_at' => now()->subHours(5)]);
        $this->tick($user, now());

        $this->assertSame(0, $this->estoque($user, 'ligas_metalicas'));
        $this->assertSame(0, $this->estoque($user, 'compostos_quimicos'));

        // Componentes saem: 15/h × 5 h.
        $this->assertSame(75, $this->estoque($user, 'componentes_eletronicos'));
    }

    /** "2 Biomassas + 3 Energias em 1 Biocombustível" (§18.2), limitado pelo insumo. */
    public function test_destilaria_converte_na_receita_do_gdd(): void
    {
        $user = $this->colono();
        $this->erguer($user, 'destilaria', 1);   // 20 biocombustível/h
        $user->colony->resources()->whereIn('resource_type', ['biomassa', 'energia'])
            ->update(['amount' => 1000]);

        $user->colony->update(['last_tick_at' => now()->subHour()]);
        $this->tick($user, now());

        $this->assertSame(20, $this->estoque($user, 'biocombustivel'));
        $this->assertSame(1000 - 40, $this->estoque($user, 'biomassa'));
        $this->assertSame(1000 - 60, $this->estoque($user, 'energia'));
    }

    public function test_destilaria_para_quando_falta_insumo(): void
    {
        $user = $this->colono();
        $this->erguer($user, 'destilaria', 1);
        $user->colony->resources()->where('resource_type', 'biomassa')->update(['amount' => 10]);
        $user->colony->resources()->where('resource_type', 'energia')->update(['amount' => 1000]);

        $user->colony->update(['last_tick_at' => now()->subHour()]);
        $this->tick($user, now());

        // 10 biomassa dão no máximo 5 biocombustíveis.
        $this->assertSame(5, $this->estoque($user, 'biocombustivel'));
        $this->assertSame(0, $this->estoque($user, 'biomassa'));
    }

    // ---- Conclusão de upgrades ----

    public function test_conclui_upgrade_e_lanca_subsidio_no_ledger(): void
    {
        $user = $this->colono();
        $gerador = $user->colony->buildings->firstWhere('type', 'gerador_de_atmosfera');

        $this->actingAs($user)->postJson("/buildings/{$gerador->id}/upgrade")->assertCreated();

        // Gerador n1 leva 4 min (GDD). Avança 5.
        $this->tick($user, now()->addMinutes(5));

        $this->assertSame(1, $gerador->fresh()->level);
        $this->assertNull($gerador->fresh()->upgrade_finish_at);
        $this->assertSame('done', BuildQueue::first()->status);

        // §24.7: subsídio registrado no momento de concluir, um lançamento por recurso.
        $subsidio = Ledger::where('type', 'subsidio_governo')->get();
        $this->assertCount(4, $subsidio);   // água, biomassa, energia, oxigênio
        $this->assertSame(50, $subsidio->firstWhere('resource_type', 'agua')->amount);
    }

    /**
     * O nível muda no meio do delta. Produzir tudo com o nível antigo — ou tudo com o novo —
     * falsearia a economia. O tick fatia o delta na conclusão.
     *
     * O upgrade precisa ser de nível 1 para 2, não de 0 para 1: com a construção parada no
     * nível 0 ela não produz durante a obra, e o teste não distinguiria fatiar de não fatiar.
     */
    public function test_fatia_o_delta_na_conclusao_do_upgrade(): void
    {
        $user = $this->colono();
        $this->erguer($user, 'reator_de_energia', 5);
        $this->erguer($user, 'gerador_de_atmosfera', 1);   // já produzindo 100 oxigênio/h
        $gerador = $user->colony->buildings->firstWhere('type', 'gerador_de_atmosfera');

        $t0 = now()->startOfSecond();
        Carbon::setTestNow($t0);
        $this->actingAs($user)->postJson("/buildings/{$gerador->id}/upgrade")->assertCreated();
        $user->colony->update(['last_tick_at' => $t0]);

        // Gerador n2: 5 min de obra, e depois 150 oxigênio/h (§19.2).
        // 65 min = 5 min a 100/h + 60 min a 150/h.
        Carbon::setTestNow($t0->copy()->addMinutes(65));
        $this->tick($user, now());
        Carbon::setTestNow();

        $this->assertSame(2, $gerador->fresh()->level);

        // (100 × 300s + 150 × 3600s) / 3600 = 158,33 -> 158, com resto.
        $esperado = intdiv(100 * 300 + 150 * 3600, 3600);
        $this->assertSame(158, $esperado);
        $this->assertSame($esperado, $this->estoque($user, 'oxigenio'));

        // Sem fatiar, seriam só os 60 min a 150/h = 150. O teste distingue os dois.
        $this->assertNotSame(150, $this->estoque($user, 'oxigenio'));
    }

    public function test_promove_o_proximo_item_da_fila_ao_concluir(): void
    {
        $user = $this->colono();
        $g = $user->colony->buildings->firstWhere('type', 'gerador_de_atmosfera');
        $f = $user->colony->buildings->firstWhere('type', 'fazenda');

        $this->actingAs($user)->postJson("/buildings/{$g->id}/upgrade")->assertCreated();
        $this->actingAs($user)->postJson("/buildings/{$f->id}/upgrade")->assertCreated();

        $this->assertSame('queued', BuildQueue::where('building_id', $f->id)->value('status'));

        $this->tick($user, now()->addMinutes(5));   // conclui o Gerador (4 min)

        $fila = BuildQueue::where('building_id', $f->id)->first();
        $this->assertSame('building', $fila->status);
        $this->assertNotNull($fila->finishes_at);
        // Fazenda n1 leva 4 min a partir da conclusão do Gerador, não do enfileiramento.
        $this->assertSame(0, $f->fresh()->level);
    }

    public function test_upgrade_nao_subsidiado_nao_lanca_subsidio(): void
    {
        $user = $this->colono();
        $user->colony->resources()->update(['amount' => 5000]);
        $mina = $user->colony->buildings->firstWhere('type', 'mina_local');

        $this->actingAs($user)->postJson("/buildings/{$mina->id}/upgrade")->assertCreated();
        $this->tick($user, now()->addMinutes(20));

        $this->assertSame(1, $mina->fresh()->level);
        $this->assertSame(0, Ledger::where('type', 'subsidio_governo')->count());
    }

    // ---- Idempotência e comando ----

    public function test_tick_repetido_no_mesmo_instante_nao_produz_duas_vezes(): void
    {
        $user = $this->colono();
        $this->erguer($user, 'gerador_de_atmosfera', 1);
        $this->erguer($user, 'reator_de_energia', 1);

        $agora = now()->addHour();
        $this->tick($user, $agora);
        $primeiro = $this->estoque($user, 'oxigenio');

        $this->tick($user, $agora);   // mesmo instante
        $this->assertSame($primeiro, $this->estoque($user, 'oxigenio'));
    }

    public function test_comando_expira_protecao_de_zona_neutra_vencida(): void
    {
        $vencida = NeutralZone::create([
            'coordinates' => 'K-14', 'status' => 'protegida',
            'occupied_at' => now()->subDays(9), 'protected_until' => now()->subDay(),
        ]);
        $vigente = NeutralZone::create([
            'coordinates' => 'K-15', 'status' => 'protegida',
            'occupied_at' => now()->subDay(), 'protected_until' => now()->addDays(7),
        ]);

        $this->artisan('fertways:tick')->assertSuccessful();

        $this->assertSame('vulneravel', $vencida->fresh()->status);
        $this->assertSame('protegida', $vigente->fresh()->status);
    }

    public function test_comando_processa_todas_as_colonias(): void
    {
        $a = $this->colono();
        $b = User::factory()->create();
        app(CreateColony::class)->handle($b, 'Segunda');

        foreach ([$a, $b->fresh()] as $u) {
            $this->erguer($u, 'gerador_de_atmosfera', 1);
            $this->erguer($u, 'reator_de_energia', 1);
            $u->colony->update(['last_tick_at' => now()->subHour()]);
        }

        $this->artisan('fertways:tick')->assertSuccessful();

        $this->assertSame(100, $this->estoque($a, 'oxigenio'));
        $this->assertSame(100, $this->estoque($b->fresh(), 'oxigenio'));
    }
}
