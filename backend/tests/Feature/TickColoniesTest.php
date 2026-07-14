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

    private int $proximoSlot = 0;

    private function colono(): User
    {
        $user = User::factory()->create();
        // Célula de periferia, uma por colônia (D-51: o colono escolhe; aqui o teste escolhe por ele).
        app(CreateColony::class)->handle($user, 'Nova Aurora', 10 + $this->proximoSlot++, 20);

        return $user->fresh();
    }

    /**
     * Desde o D-59 a construção de progressão não existe até ser erguida num slot, e as cinco
     * essenciais já nascem no nível 1. O helper do TestCase cria ou promove, conforme o caso.
     */
    private function erguer(User $user, string $tipo, int $nivel): void
    {
        $this->erguerPredio($user->colony, $tipo, $nivel);
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

    /**
     * A colônia recém-fundada já tem as cinco essenciais no nível 1 (D-59), então esta é a
     * produção de uma hora de uma colônia recém-nascida — sem erguer nada.
     *
     * O saldo de energia é a confirmação mais bonita do desenho: 150 produzidos pelo Reator menos
     * o consumo das outras quatro dá **88 kW/h**, que é exatamente o número que o §19.8 publica —
     * "o Reator (150 kW/h) cobre com folga o consumo das construções essenciais (~88 kW/h de saldo
     * positivo), permitindo que o jogador construa 2-3 estruturas adicionais antes de precisar do
     * primeiro upgrade". O miolo erguido do D-59 reproduz o balanço que o GDD sempre descreveu.
     */
    public function test_produz_pela_taxa_do_gdd_em_uma_hora(): void
    {
        $user = $this->colono();

        $colony = $user->colony;
        $colony->update(['last_tick_at' => now()->subHour()]);

        $this->tick($user, now());

        // As taxas do §19.2, no nível 1.
        $this->assertSame(100, $this->estoque($user, 'oxigenio'));   // Gerador
        $this->assertSame(80, $this->estoque($user, 'agua'));        // Captação
        $this->assertSame(60, $this->estoque($user, 'biomassa'));    // Fazenda
        $this->assertSame(88, $this->estoque($user, 'energia'));     // §19.8, verbatim
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
        // Sem o miolo não há Reator, e portanto nenhuma fonte de energia: é o que este teste quer.
        $this->zerarMiolo($user->colony);
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
    /**
     * A Oficina nunca produz Ligas Metálicas — desde o D-83, só a Indústria Siderúrgica as
     * produz (D-82). Fartura de minério não muda isso: não é falta de receita, é a fonte ter
     * mudado de construção.
     */
    public function test_ligas_metalicas_nunca_saem_da_oficina(): void
    {
        $user = $this->colono();
        $this->erguer($user, 'oficina', 1);
        $this->erguer($user, 'reator_de_energia', 5);

        $user->colony->resources()
            ->whereIn('resource_type', ['estanho', 'cobre', 'silicio', 'aluminio', 'agua', 'metal_bruto'])
            ->update(['amount' => 100_000]);

        $user->colony->update(['last_tick_at' => now()->subHours(5)]);
        $this->tick($user, now());

        $this->assertSame(0, $this->estoque($user, 'ligas_metalicas'));

        // Componentes continuam saindo normalmente: 15/h × 5 h.
        $this->assertSame(75, $this->estoque($user, 'componentes_eletronicos'));
    }

    /**
     * A Refinaria Química converte pela receita do D-83: 1 Metal Bruto + 10 Água + 5 Biomassa +
     * 6 Energia por Composto. Com insumo de sobra, produz na taxa cheia do nível.
     */
    public function test_refinaria_quimica_converte_pela_receita_do_d83(): void
    {
        $user = $this->colono();
        // Sem o miolo, ninguém mais mexe em Metal Bruto/Água/Biomassa/Energia neste tick —
        // senão a Captação, a Fazenda e o Reator repunham por fora e o delta exato não bateria.
        $this->zerarMiolo($user->colony);
        $this->erguer($user, 'refinaria_quimica', 1);

        $user->colony->resources()
            ->whereIn('resource_type', ['metal_bruto', 'agua', 'biomassa', 'energia'])
            ->update(['amount' => 100_000]);

        $user->colony->update(['last_tick_at' => now()->subHour()]);
        $this->tick($user, now());

        // 2 Compostos/h no nível 1 (D-83) — 1 Metal Bruto + 10 Água + 5 Biomassa + 6 Energia cada.
        $this->assertSame(2, $this->estoque($user, 'compostos_quimicos'));
        $this->assertSame(100_000 - 2, $this->estoque($user, 'metal_bruto'));
        $this->assertSame(100_000 - 20, $this->estoque($user, 'agua'));
        $this->assertSame(100_000 - 10, $this->estoque($user, 'biomassa'));
    }

    /**
     * Faltando um dos quatro insumos, a Refinaria Química produz o MÁXIMO que ele permite —
     * mesmo padrão da Destilaria (`converter()` é a mesma função para as duas).
     */
    public function test_refinaria_quimica_para_quando_falta_insumo(): void
    {
        $user = $this->colono();
        $this->zerarMiolo($user->colony);   // sem a Fazenda repondo Biomassa por fora
        $this->erguer($user, 'refinaria_quimica', 1);
        $user->colony->resources()->where('resource_type', 'metal_bruto')->update(['amount' => 100_000]);
        $user->colony->resources()->where('resource_type', 'agua')->update(['amount' => 100_000]);
        $user->colony->resources()->where('resource_type', 'energia')->update(['amount' => 100_000]);
        // 15 Biomassa ÷ 5 por Composto = no máximo 3 — bem menos que os 20 que 10 h a 2/h dariam.
        $user->colony->resources()->where('resource_type', 'biomassa')->update(['amount' => 15]);

        $user->colony->update(['last_tick_at' => now()->subHours(10)]);
        $this->tick($user, now());

        $this->assertSame(3, $this->estoque($user, 'compostos_quimicos'));
        $this->assertSame(0, $this->estoque($user, 'biomassa'));
    }

    /** "2 Biomassas + 3 Energias em 1 Biocombustível" (§18.2), limitado pelo insumo. */
    public function test_destilaria_converte_na_receita_do_gdd(): void
    {
        $user = $this->colono();
        $this->zerarMiolo($user->colony);        // a Fazenda do miolo produziria biomassa por fora
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
        $this->zerarMiolo($user->colony);        // senão a Fazenda repõe a biomassa que deve faltar
        $this->erguer($user, 'destilaria', 1);
        $user->colony->resources()->where('resource_type', 'biomassa')->update(['amount' => 10]);
        $user->colony->resources()->where('resource_type', 'energia')->update(['amount' => 1000]);

        $user->colony->update(['last_tick_at' => now()->subHour()]);
        $this->tick($user, now());

        // 10 biomassa dão no máximo 5 biocombustíveis.
        $this->assertSame(5, $this->estoque($user, 'biocombustivel'));
        $this->assertSame(0, $this->estoque($user, 'biomassa'));
    }

    // ---- Indústria Siderúrgica (D-82) ----

    /**
     * A um lote exato: 15 Metal Bruto/h (nível 1) × 240.000 s = exatos 1.000 processados.
     * As seis saídas simultâneas do D-82, e nada de resto — o lote fechou redondo.
     */
    public function test_a_siderurgica_processa_um_lote_exato_de_1000(): void
    {
        $user = $this->colono();
        $this->erguer($user, 'industria_siderurgica', 1);
        $user->colony->resources()->where('resource_type', 'metal_bruto')->update(['amount' => 2000]);

        $user->colony->update(['last_tick_at' => now()->subSeconds(240_000)]);
        $this->tick($user, now());

        $this->assertSame(1000, $this->estoque($user, 'metal_bruto'));
        $this->assertSame(350, $this->estoque($user, 'ligas_metalicas'));
        $this->assertSame(35, $this->estoque($user, 'aluminio'));
        $this->assertSame(30, $this->estoque($user, 'cobre'));
        $this->assertSame(20, $this->estoque($user, 'estanho'));
        $this->assertSame(4, $this->estoque($user, 'ouro'));
        $this->assertSame(1, $this->estoque($user, 'tungstenio'));
        $this->assertSame(0, $user->colony->fresh()->siderurgica_lote_remainder);
    }

    /**
     * Um tick de 1 minuto processa só 0,25 Metal Bruto — bem menos que um lote. NADA é creditado
     * ainda, mas o progresso não se perde: fica em `siderurgica_lote_remainder`, não descartado.
     * Sem isto, uma taxa de 0,015 Tungstênio/h nunca acumularia Tungstênio nenhum.
     */
    public function test_a_siderurgica_guarda_o_progresso_sem_lote_fechado(): void
    {
        $user = $this->colono();
        $this->erguer($user, 'industria_siderurgica', 1);
        $user->colony->resources()->where('resource_type', 'metal_bruto')->update(['amount' => 5000]);

        $user->colony->update(['last_tick_at' => now()->subMinute()]);
        $this->tick($user, now());

        $this->assertSame(0, $this->estoque($user, 'ligas_metalicas'));
        $this->assertSame(0, $this->estoque($user, 'tungstenio'));
        // 15/h × 60s = 0,25 Metal Bruto, em numerador de 1/3600: 900.
        $this->assertSame(900, $user->colony->fresh()->siderurgica_lote_remainder);
        $this->assertSame(4999, $this->estoque($user, 'metal_bruto'));
    }

    /** Sem Metal Bruto no estoque, a Siderúrgica fica ociosa — não inventa insumo do nada. */
    public function test_a_siderurgica_para_sem_metal_bruto(): void
    {
        $user = $this->colono();
        $this->erguer($user, 'industria_siderurgica', 1);
        $user->colony->resources()->where('resource_type', 'metal_bruto')->update(['amount' => 0]);

        $user->colony->update(['last_tick_at' => now()->subSeconds(240_000)]);
        $this->tick($user, now());

        $this->assertSame(0, $this->estoque($user, 'metal_bruto'));
        $this->assertSame(0, $this->estoque($user, 'ligas_metalicas'));
        $this->assertSame(0, $user->colony->fresh()->siderurgica_lote_remainder);
    }

    /** Repetível como a Mina (D-59): duas cópias somam a taxa de processamento. */
    public function test_duas_siderurgicas_somam_a_taxa(): void
    {
        $user = $this->colono();
        $this->erguer($user, 'industria_siderurgica', 1);
        $this->erguer($user, 'industria_siderurgica', 1); // uma segunda cópia — é repetível (D-59)
        $user->colony->resources()->where('resource_type', 'metal_bruto')->update(['amount' => 5000]);

        // 30 Metal Bruto/h (15+15) × 120.000 s = exatos 1.000.
        $user->colony->update(['last_tick_at' => now()->subSeconds(120_000)]);
        $this->tick($user, now());

        $this->assertSame(350, $this->estoque($user, 'ligas_metalicas'));
    }

    // ---- Conclusão de upgrades ----

    public function test_conclui_upgrade_e_lanca_subsidio_no_ledger(): void
    {
        $user = $this->colono();
        $gerador = $user->colony->buildings->firstWhere('type', 'gerador_de_atmosfera');

        // A fundação já lançou o subsídio do nível 1, porque o miolo nasce erguido (D-59). O que
        // se testa aqui é o lançamento da CONCLUSÃO — o do nível 2.
        $this->assertSame(4, Ledger::where('ref', 'build:gerador_de_atmosfera:n1')->count());

        $this->actingAs($user)->postJson("/buildings/{$gerador->id}/upgrade")->assertCreated();

        // Gerador n2 leva 5 min (GDD). Avança 6.
        $this->tick($user, now()->addMinutes(6));

        $this->assertSame(2, $gerador->fresh()->level);
        $this->assertNull($gerador->fresh()->upgrade_finish_at);
        $this->assertSame('done', BuildQueue::first()->status);

        // §24.7: subsídio registrado no momento de concluir, um lançamento por recurso.
        $subsidio = Ledger::where('type', 'subsidio_governo')
            ->where('ref', 'build:gerador_de_atmosfera:n2')->get();
        $this->assertCount(4, $subsidio);   // água, biomassa, energia, oxigênio
        $this->assertSame(83, $subsidio->firstWhere('resource_type', 'agua')->amount);
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

        $this->tick($user, now()->addMinutes(6));   // conclui o Gerador (n2 leva 5 min)

        $fila = BuildQueue::where('building_id', $f->id)->first();
        $this->assertSame('building', $fila->status);
        $this->assertNotNull($fila->finishes_at);
        // A Fazenda só COMEÇA a subir agora: continua no nível 1, que é o de fundação (D-59).
        $this->assertSame(1, $f->fresh()->level);
    }

    public function test_upgrade_nao_subsidiado_nao_lanca_subsidio(): void
    {
        $user = $this->colono();
        $user->colony->resources()->update(['amount' => 5000]);
        // A Mina é de progressão: não existe até o colono escolher o slot dela (D-59).
        $mina = $this->predioDe($user->colony, 'mina_local');

        $this->actingAs($user)->postJson("/buildings/{$mina->id}/upgrade")->assertCreated();
        $this->tick($user, now()->addMinutes(20));

        $this->assertSame(1, $mina->fresh()->level);
        // Nenhum subsídio PARA A MINA. Os do miolo, lançados na fundação, não contam aqui.
        $this->assertSame(0, Ledger::where('ref', 'like', 'build:mina_local%')
            ->where('type', 'subsidio_governo')->count());
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
            'x' => 45, 'y' => 46, 'district' => 'nordeste', 'mineral' => 'metal_bruto',
            'status' => 'protegida',
            'occupied_at' => now()->subDays(9), 'protected_until' => now()->subDay(),
        ]);
        $vigente = NeutralZone::create([
            'x' => 45, 'y' => 47, 'district' => 'nordeste', 'mineral' => 'metal_bruto',
            'status' => 'protegida',
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
        app(CreateColony::class)->handle($b, 'Segunda', 10 + $this->proximoSlot++, 20);

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
