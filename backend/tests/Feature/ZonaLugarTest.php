<?php

namespace Tests\Feature;

use App\Domain\Colony\CreateColony;
use App\Domain\Guerra\Protegido;
use App\Domain\Logistics\ConcluirTrechos;
use App\Domain\Zona\ConcluirObrasDaZona;
use App\Domain\Zona\ConstruirNaZona;
use App\Domain\Zona\Estruturas;
use App\Domain\Zona\ProcessarSiderurgicaNaZona;
use App\Domain\Zona\RefinarNaZona;
use App\Exceptions\DomainRuleException;
use App\Models\Colony;
use App\Models\NeutralZone;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\ZoneMaterial;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A zona neutra vira lugar (GDD §17.4; docs/decisoes.md D-67).
 *
 * **Estes testes existem porque o D-66 abriu um buraco que nada denunciava.** As quatro construções de
 * defesa estavam no catálogo, o motor de combate lia os níveis delas — e **nada as erguia**. A suíte
 * inteira passava, e o bônus do §27.3 era sempre zero em produção.
 *
 * O primeiro teste daqui é o que teria pegado isso.
 */
class ZonaLugarTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\ResourceTypeSeeder::class);
        $this->seed(\Database\Seeders\ComponentRecipeSeeder::class);
        $this->seed(\Database\Seeders\BuildingSpecSeeder::class);
    }

    private function colono(): Colony
    {
        $c = app(CreateColony::class)->handle(User::factory()->create(), 'Base', 20, 20);
        $c->update(['fert_micro' => 10_000 * 1_000_000]);

        foreach (['metal_bruto' => 20000, 'ligas_metalicas' => 20000,
                  'componentes_eletronicos' => 5000, 'energia' => 20000] as $r => $q) {
            $c->resources()->where('resource_type', $r)->update(['amount' => $q]);
        }

        return $c->fresh();
    }

    private function zonaDe(Colony $dono, array $extra = []): NeutralZone
    {
        return NeutralZone::create(array_merge([
            'x' => 47, 'y' => 47, 'district' => 'NE', 'mineral' => 'metal_bruto', 'level' => 1,
            'owner_colony_id' => $dono->id,
            'status' => 'ocupada',
            'occupied_at' => now()->subDays(30),
            'protected_until' => now()->subDays(20),
            'command_post_level' => 1,
            'productive_at' => now()->subDays(20),
            'deposit_level' => 1,
            'deposit_amount' => 0,
            'last_extraction_at' => now(),
        ], $extra));
    }

    /** Enche o canteiro à mão, para os testes de obra não precisarem de uma viagem inteira. */
    private function encherCanteiro(NeutralZone $zona, array $material): void
    {
        foreach ($material as $r => $q) {
            ZoneMaterial::create(['zone_id' => $zona->id, 'resource_type' => $r, 'amount' => $q]);
        }
    }

    // ── o buraco que o D-66 abriu ───────────────────────────────────────────────────────────────

    /**
     * **O teste que teria pegado o buraco.** Uma zona pode ser fortificada.
     *
     * Até o D-67, `wall_level` nascia em zero e **nada no jogo o mudava** — o bônus defensivo do §27.3
     * era sempre 0%, e a Torre nunca detectava um Infiltrador. O catálogo tinha as construções, o
     * motor lia os níveis, e no meio não havia ninguém.
     */
    public function test_a_zona_pode_ser_fortificada(): void
    {
        $colono = $this->colono();
        $zona = $this->zonaDe($colono);

        $this->assertSame(0, $zona->wall_level, 'começa nua — era assim que ficava para sempre');

        $this->encherCanteiro($zona, ['metal_bruto' => 400, 'ligas_metalicas' => 100]);

        app(ConstruirNaZona::class)->handle($colono, $zona, 'muralha_de_perimetro');

        // A obra começou e o canteiro pagou.
        $this->assertTrue($zona->fresh()->obraEmCurso());
        $this->assertSame(0, (int) $zona->materiais()->where('resource_type', 'metal_bruto')->value('amount'));

        // 4 h depois, ela está de pé.
        $this->travelTo(now()->addHours(5));
        app(ConcluirObrasDaZona::class)->handle();

        $zona->refresh();
        $this->assertSame(1, $zona->wall_level);
        $this->assertFalse($zona->obraEmCurso());
    }

    /** E o bônus do §27.3 deixa de ser zero — que era o efeito de tudo isto. */
    public function test_a_muralha_erguida_aumenta_a_forca_defensiva(): void
    {
        $colono = $this->colono();
        $zona = $this->zonaDe($colono);

        $forcas = app(\App\Domain\Guerra\Forcas::class);

        $this->assertSame(0, $forcas->bonusDeConstrucao($zona), 'sem estruturas, o bônus é zero');

        $zona->update(['wall_level' => 1, 'watchtower_level' => 1, 'bastion_level' => 1]);

        // +20 +30 +50 = +100%: as três juntas dobram a defesa (D-66).
        $this->assertSame(10000, $forcas->bonusDeConstrucao($zona->fresh()));
    }

    // ── o canteiro de obras ─────────────────────────────────────────────────────────────────────

    /** Sem material no canteiro, a obra não começa — e a mensagem diz o que falta e quanto. */
    public function test_sem_material_no_canteiro_a_obra_nao_comeca(): void
    {
        $colono = $this->colono();
        $zona = $this->zonaDe($colono);

        $this->expectException(DomainRuleException::class);
        $this->expectExceptionMessageMatches('/canteiro|Despache um veículo/');

        app(ConstruirNaZona::class)->handle($colono, $zona, 'muralha_de_perimetro');
    }

    /**
     * **A entrega é física** (D-67): o material sai do estoque da colônia, viaja de veículo e chega ao
     * canteiro. Isso contradiz a ocupação, que ergue o Posto sem veículo nenhum — de propósito.
     */
    public function test_o_material_chega_de_veiculo_ao_canteiro(): void
    {
        $colono = $this->colono();
        $zona = $this->zonaDe($colono);
        $veiculo = $colono->vehicles()->first();

        $antes = (int) $colono->resources()->where('resource_type', 'metal_bruto')->value('amount');

        app(\App\Domain\Logistics\DespacharVeiculo::class)
            ->entregarMaterialNaZona($colono, $veiculo, $zona, ['metal_bruto' => 400]);

        // O material saiu do estoque NO DESPACHO — está fisicamente no veículo (§25.3).
        $this->assertSame(
            $antes - 400,
            (int) $colono->fresh()->resources()->where('resource_type', 'metal_bruto')->value('amount'),
        );
        // E ainda não chegou.
        $this->assertSame(0, $zona->materiais()->count());

        $this->travelTo(now()->addDays(1));
        app(ConcluirTrechos::class)->handle();

        // Chegou: entrou no canteiro. Sem tributo — não é comércio, é o colono levando o próprio
        // material ao próprio território.
        $this->assertSame(
            400,
            (int) $zona->fresh()->materiais()->where('resource_type', 'metal_bruto')->value('amount'),
        );
    }

    /** A sobra fica no canteiro: quem traz demais não perde nada. */
    public function test_a_sobra_fica_no_canteiro(): void
    {
        $colono = $this->colono();
        $zona = $this->zonaDe($colono);

        $this->encherCanteiro($zona, ['metal_bruto' => 500, 'ligas_metalicas' => 300]);

        app(ConstruirNaZona::class)->handle($colono, $zona, 'muralha_de_perimetro');   // 400 + 100

        $this->assertSame(100, (int) $zona->materiais()->where('resource_type', 'metal_bruto')->value('amount'));
        $this->assertSame(200, (int) $zona->materiais()->where('resource_type', 'ligas_metalicas')->value('amount'));
    }

    /** Uma obra por vez. A zona não tem fila — tem canteiro. */
    public function test_uma_obra_por_vez(): void
    {
        $colono = $this->colono();
        $zona = $this->zonaDe($colono);

        $this->encherCanteiro($zona, ['metal_bruto' => 5000, 'ligas_metalicas' => 5000]);

        app(ConstruirNaZona::class)->handle($colono, $zona, 'muralha_de_perimetro');

        $this->expectException(DomainRuleException::class);
        app(ConstruirNaZona::class)->handle($colono, $zona->fresh(), 'abrigo_de_robos');
    }

    // ── o cerco impede fortificar ───────────────────────────────────────────────────────────────

    /**
     * **Não se constrói sob sítio** — e ninguém planejou isso. Caiu de graça do "nada entra nem sai"
     * do cerco (D-66) somado à entrega física (D-67). Quem cerca impede o cercado de se defender melhor.
     */
    public function test_nao_se_constroi_nem_se_entrega_material_sob_cerco(): void
    {
        $colono = $this->colono();
        $zona = $this->zonaDe($colono, ['sieged_at' => now()->subHour()]);

        $this->encherCanteiro($zona, ['metal_bruto' => 5000, 'ligas_metalicas' => 5000]);

        // Nem construir com o material que já está lá…
        try {
            app(ConstruirNaZona::class)->handle($colono, $zona, 'muralha_de_perimetro');
            $this->fail('a obra não devia começar sob cerco');
        } catch (DomainRuleException $e) {
            $this->assertStringContainsString('cercada', $e->getMessage());
        }

        // …nem mandar mais material.
        $this->expectException(DomainRuleException::class);
        app(\App\Domain\Logistics\DespacharVeiculo::class)->entregarMaterialNaZona(
            $colono, $colono->vehicles()->first(), $zona, ['metal_bruto' => 100],
        );
    }

    // ── a Refinaria de Campo ────────────────────────────────────────────────────────────────────

    /**
     * A **primeira construção do jogo que CONVERTE**. Todas as outras produzem uma taxa fixa por hora
     * sem consumir nada — a Mina rende 15 Metal Bruto/h do ar.
     *
     * 2 primários → 1 secundário (D-67), por distrito. O Nordeste rende Metal Bruto → Ligas Metálicas.
     */
    public function test_a_refinaria_converte_dois_primarios_em_um_secundario(): void
    {
        $colono = $this->colono();
        // Refinaria nv1 processa 50/h (metade da extração da zona, D-67). Em 2 h: 100 primários.
        $zona = $this->zonaDe($colono, [
            'refinery_level' => 1,
            'deposit_amount' => 1000,
            'last_refine_at' => now()->subHours(2),
        ]);

        $this->assertSame(50, $zona->refinoPorHora());
        $this->assertSame('ligas_metalicas', $zona->recursoRefinado());

        app(RefinarNaZona::class)->handle();

        $zona->refresh();
        $this->assertSame(900, $zona->deposit_amount);   // consumiu 100
        $this->assertSame(50, $zona->refined_amount);    // produziu 50 (2:1)
    }

    /** Sem Refinaria, nada converte. */
    public function test_sem_refinaria_nada_converte(): void
    {
        $colono = $this->colono();
        $zona = $this->zonaDe($colono, ['deposit_amount' => 1000, 'last_refine_at' => now()->subHours(5)]);

        app(RefinarNaZona::class)->handle();

        $this->assertSame(1000, $zona->fresh()->deposit_amount);
        $this->assertSame(0, $zona->fresh()->refined_amount);
    }

    /** O refinado ocupa o MESMO Depósito, e é butim como o bruto: refinar não esconde do inimigo. */
    public function test_o_refinado_conta_no_deposito_e_no_saque(): void
    {
        $colono = $this->colono();
        // Depósito nv1 protege 500. Total = 400 bruto + 400 refinado = 800 → 300 expostos.
        $zona = $this->zonaDe($colono, [
            'refinery_level' => 1, 'deposit_amount' => 400, 'refined_amount' => 400,
        ]);

        $p = app(Protegido::class);

        $this->assertSame(800, $zona->estoqueTotal());
        $this->assertSame(500, $p->protegido($zona));
        $this->assertSame(300, $p->exposto($zona));

        // 50% dos 300 expostos = 150, repartidos proporcionalmente (metade e metade).
        $butim = $p->saqueDetalhado($zona, 5000);
        $this->assertSame(150, $butim['total']);
        $this->assertSame(75, $butim['refinado']);
        $this->assertSame(75, $butim['bruto']);
    }

    /** E o refinado pode ser RETIRADO — senão a Refinaria seria uma armadilha. */
    public function test_o_refinado_pode_ser_retirado_da_zona(): void
    {
        $colono = $this->colono();
        $zona = $this->zonaDe($colono, ['refinery_level' => 1, 'refined_amount' => 300]);

        app(\App\Domain\Logistics\DespacharVeiculo::class)->retirarDeZona(
            $colono, $colono->vehicles()->first(), $zona, ['ligas_metalicas' => 100],
        );

        // Reservado no despacho, como toda retirada (D-30).
        $this->assertSame(200, $zona->fresh()->refined_amount);
    }

    /** Os minerais da Indústria Siderúrgica (D-82) também podem ser retirados por veículo. */
    public function test_os_minerais_da_siderurgica_podem_ser_retirados_da_zona(): void
    {
        $colono = $this->colono();
        $zona = $this->zonaDe($colono, ['industry_level' => 1]);
        \App\Models\ZoneMineral::create(['zone_id' => $zona->id, 'resource_type' => 'ouro', 'amount' => 10]);

        app(\App\Domain\Logistics\DespacharVeiculo::class)->retirarDeZona(
            $colono, $colono->vehicles()->first(), $zona, ['ouro' => 6],
        );

        $this->assertSame(4, $zona->fresh()->minerais()->where('resource_type', 'ouro')->value('amount'));
    }

    /** Sem Indústria Siderúrgica na zona, os minerais dela não são um recurso retirável. */
    public function test_nao_se_retira_mineral_sem_a_siderurgica(): void
    {
        $colono = $this->colono();
        $zona = $this->zonaDe($colono);   // industry_level = 0

        $this->expectException(DomainRuleException::class);
        $this->expectExceptionMessageMatches('/não há ouro/');

        app(\App\Domain\Logistics\DespacharVeiculo::class)->retirarDeZona(
            $colono, $colono->vehicles()->first(), $zona, ['ouro' => 1],
        );
    }

    // ── a Indústria Siderúrgica (D-82) — construção nova, não está no GDD ─────────────────────────

    /** Um lote exato: o depósito tinha exatamente 1000, e os seis produtos saem redondos. */
    public function test_a_siderurgica_da_zona_processa_um_lote_exato(): void
    {
        $colono = $this->colono();
        $zona = $this->zonaDe($colono, [
            'industry_level' => 1, 'deposit_amount' => 1000,
            'occupied_at' => now()->subDays(30), 'productive_at' => now()->subDays(20),
        ]);

        app(ProcessarSiderurgicaNaZona::class)->handle(now());

        $zona = $zona->fresh();
        $this->assertSame(0, $zona->deposit_amount);
        $this->assertSame(350, $zona->refined_amount);   // Ligas — o MESMO pote da Refinaria

        $minerais = $zona->minerais->keyBy('resource_type');
        $this->assertSame(35, $minerais['aluminio']->amount);
        $this->assertSame(30, $minerais['cobre']->amount);
        $this->assertSame(20, $minerais['estanho']->amount);
        $this->assertSame(4, $minerais['ouro']->amount);
        $this->assertSame(1, $minerais['tungstenio']->amount);
    }

    /** Só zonas de Metal Bruto (Nordeste) — noutro distrito, fica inerte mesmo com nível. */
    public function test_a_siderurgica_so_processa_zona_de_metal_bruto(): void
    {
        $colono = $this->colono();
        $zona = $this->zonaDe($colono, [
            'district' => 'SE', 'mineral' => 'agua',
            'industry_level' => 1, 'deposit_amount' => 5000,
            'occupied_at' => now()->subDays(30), 'productive_at' => now()->subDays(20),
        ]);

        app(ProcessarSiderurgicaNaZona::class)->handle(now());

        $zona = $zona->fresh();
        $this->assertSame(5000, $zona->deposit_amount);
        $this->assertSame(0, $zona->refined_amount);
        $this->assertCount(0, $zona->minerais);
    }

    /**
     * Convive com a Refinaria de Campo, disputando o MESMO depósito — decisão do usuário. As duas
     * rodam no mesmo tick (`TickColonies`), e a ordem decide quem leva mais.
     */
    public function test_a_siderurgica_convive_com_a_refinaria_no_mesmo_deposito(): void
    {
        $colono = $this->colono();
        $zona = $this->zonaDe($colono, [
            'refinery_level' => 1, 'industry_level' => 1, 'deposit_amount' => 3000,
            'occupied_at' => now()->subDays(30), 'productive_at' => now()->subDays(20),
            'last_refine_at' => now()->subDays(30),
        ]);

        // A Refinaria primeiro (mesma ordem do TickColonies): 50/h × 30 dias satura, limitada pelo
        // depósito. 2 primários por secundário — consome tudo que puder em pares.
        app(RefinarNaZona::class)->handle(now());
        $apósRefinaria = $zona->fresh()->deposit_amount;
        $this->assertLessThan(3000, $apósRefinaria, 'a Refinaria já deve ter consumido parte');

        app(ProcessarSiderurgicaNaZona::class)->handle(now());
        $zona = $zona->fresh();

        // A Siderúrgica só teve o que sobrou depois da Refinaria — o depósito não pode ter subido.
        $this->assertLessThanOrEqual($apósRefinaria, $zona->deposit_amount);
        // Ligas vieram das DUAS: a Refinaria (2:1) e a Siderúrgica (350 por lote) somam no mesmo pote.
        $this->assertGreaterThan(0, $zona->refined_amount);
    }

    /** A Indústria Siderúrgica se ergue pelo canteiro, como qualquer outra estrutura de zona. */
    public function test_a_siderurgica_se_ergue_pelo_canteiro(): void
    {
        $colono = $this->colono();
        $zona = $this->zonaDe($colono);

        $this->encherCanteiro($zona, ['ligas_metalicas' => 38, 'compostos_quimicos' => 13]);

        $this->actingAs($colono->user)
            ->postJson("/zones/{$zona->id}/build", ['structure' => 'industria_siderurgica'])
            ->assertCreated();

        $this->assertTrue($zona->fresh()->obraEmCurso());
    }

    /** Cercada, a Siderúrgica para junto com o depósito — mesma regra da Refinaria de Campo. */
    public function test_a_siderurgica_para_sob_cerco(): void
    {
        $colono = $this->colono();
        $zona = $this->zonaDe($colono, [
            'industry_level' => 1, 'deposit_amount' => 2000,
            'occupied_at' => now()->subDays(30), 'productive_at' => now()->subDays(20),
            'sieged_at' => now()->subHour(),
        ]);

        app(ProcessarSiderurgicaNaZona::class)->handle(now());

        $zona = $zona->fresh();
        $this->assertSame(2000, $zona->deposit_amount);
        $this->assertSame(0, $zona->refined_amount);
    }

    // ── a Torre de Vigia avisa ──────────────────────────────────────────────────────────────────

    /**
     * ⚠️ **Sem Torre de Vigia, o defensor só vê o inimigo quando ele chega** — e isso é uma mudança
     * do D-67, não como era antes.
     *
     * Até aqui o defensor via o ataque desde o despacho, o que tornava a Torre **inútil**: o §17.4 lhe
     * dá o papel de "detectar a aproximação com antecedência", e não há antecedência a ganhar quando
     * já se vê tudo. Agora a notificação do §27.5 é uma coisa que se **constrói**.
     */
    public function test_sem_torre_o_defensor_nao_ve_a_marcha_chegando(): void
    {
        $atacante = app(CreateColony::class)->handle(User::factory()->create(), 'Atacante', 25, 25);
        $defensor = $this->colono();
        $zona = $this->zonaDe($defensor);

        // Guarnição, para o ataque ter contra o que ir.
        \App\Models\Unit::create([
            'zone_id' => $zona->id, 'type' => 'robo_minerador',
            'level' => 1, 'hp_bps' => 10000, 'status' => 'na_zona',
        ]);

        $sentinela = \App\Models\Unit::create([
            'colony_id' => $atacante->id, 'type' => 'sentinela',
            'level' => 1, 'hp_bps' => 10000, 'status' => 'casa',
        ]);

        app(\App\Domain\Guerra\Atacar::class)
            ->handle($atacante, $zona, 'invasao', [$sentinela->id]);

        // O ATACANTE vê o próprio ataque — ele o mandou.
        $this->actingAs($atacante->user)->getJson('/war/combats')
            ->assertOk()->assertJsonCount(1, 'combats');

        // O DEFENSOR, sem Torre, não vê nada: a marcha está a caminho e ele não sabe.
        $this->actingAs($defensor->user)->getJson('/war/combats')
            ->assertOk()->assertJsonCount(0, 'combats');

        // Com uma Torre nível 3, ele passa a ver 30 min antes da chegada.
        $zona->update(['watchtower_level' => 3]);

        $this->actingAs($defensor->user)->getJson('/war/combats')
            ->assertOk()->assertJsonCount(1, 'combats');
    }

    // ── a API da zona ───────────────────────────────────────────────────────────────────────────

    public function test_a_ficha_da_zona_diz_o_que_cada_estrutura_faz(): void
    {
        $colono = $this->colono();
        $zona = $this->zonaDe($colono, ['deposit_amount' => 900]);

        $this->actingAs($colono->user)
            ->getJson("/zones/{$zona->id}")
            ->assertOk()
            ->assertJsonPath('deposito.exposto', 400)      // 900 − 500 de capacidade
            ->assertJsonPath('deposito.protegido', 500)
            // O Cemitério é declarado INERTE pelo próprio GDD, e a tela tem de dizê-lo.
            ->assertJsonPath('estruturas.8.type', 'cemiterio_de_robos')
            ->assertJsonPath('estruturas.8.inerte', true)
            // As três últimas do §17.4 (D-79): custeadas, construíveis, e também INERTES — nenhuma
            // tem sistema que a acione ainda (extração já funciona sem ferramenta; sem Federação;
            // sem Nave de Transporte Planetária).
            ->assertJsonPath('estruturas.9.type', 'estrutura_de_extracao')
            ->assertJsonPath('estruturas.9.inerte', true)
            ->assertJsonPath('estruturas.10.type', 'central_de_comunicacao')
            ->assertJsonPath('estruturas.10.inerte', true)
            ->assertJsonPath('estruturas.11.type', 'plataforma_de_pouso_da_zona')
            ->assertJsonPath('estruturas.11.inerte', true)
            // E não sobrou nada do §17.4 marcado como "buraco" — o D-79 fechou a lista.
            ->assertJsonPath('ausentes', []);
    }

    /** A ficha da zona publica os minerais da Indústria Siderúrgica junto do resto do depósito. */
    public function test_a_ficha_da_zona_publica_os_minerais(): void
    {
        $colono = $this->colono();
        $zona = $this->zonaDe($colono);
        \App\Models\ZoneMineral::create(['zone_id' => $zona->id, 'resource_type' => 'ouro', 'amount' => 7]);
        \App\Models\ZoneMineral::create(['zone_id' => $zona->id, 'resource_type' => 'cobre', 'amount' => 0]);

        $this->actingAs($colono->user)
            ->getJson("/zones/{$zona->id}")
            ->assertOk()
            // Só o que tem alguma coisa aparece — cobre, com zero, fica de fora.
            ->assertJsonCount(1, 'deposito.minerais')
            ->assertJsonPath('deposito.minerais.0.resource_type', 'ouro')
            ->assertJsonPath('deposito.minerais.0.amount', 7);
    }

    public function test_a_zona_alheia_nao_se_abre(): void
    {
        $dono = $this->colono();
        $outro = app(CreateColony::class)->handle(User::factory()->create(), 'Outro', 25, 25);
        $zona = $this->zonaDe($dono);

        $this->actingAs($outro->user)->getJson("/zones/{$zona->id}")->assertForbidden();
    }

    /**
     * O colono pode nomear a zona, como já nomeia a colônia — sem regra no GDD, é conveniência
     * de UI. Vazia nasce, e a ficha mostra `name: null` até que alguém a batize.
     */
    public function test_a_zona_pode_ser_nomeada(): void
    {
        $colono = $this->colono();
        $zona = $this->zonaDe($colono);

        $this->actingAs($colono->user)
            ->getJson("/zones/{$zona->id}")
            ->assertOk()
            ->assertJsonPath('name', null);

        $this->actingAs($colono->user)
            ->patchJson("/zones/{$zona->id}/name", ['name' => 'Posto Sentinela'])
            ->assertOk()
            ->assertJsonPath('name', 'Posto Sentinela');

        $this->assertSame('Posto Sentinela', $zona->fresh()->name);

        // Vazio volta a mostrar as coordenadas — não é erro, é "tirar o nome".
        $this->actingAs($colono->user)
            ->patchJson("/zones/{$zona->id}/name", ['name' => ''])
            ->assertOk()
            ->assertJsonPath('name', null);

        $this->assertNull($zona->fresh()->name);
    }

    /** Nomear a zona de outro colono é tão proibido quanto abrir a ficha dela. */
    public function test_nao_se_nomeia_a_zona_alheia(): void
    {
        $dono = $this->colono();
        $outro = app(CreateColony::class)->handle(User::factory()->create(), 'Outro', 25, 25);
        $zona = $this->zonaDe($dono);

        $this->actingAs($outro->user)
            ->patchJson("/zones/{$zona->id}/name", ['name' => 'Roubada'])
            ->assertForbidden();

        $this->assertNull($zona->fresh()->name);
    }

    /** Mais de 120 caracteres é rejeitado — a mesma regra do nome da colônia. */
    public function test_o_nome_da_zona_tem_teto_de_120_caracteres(): void
    {
        $colono = $this->colono();
        $zona = $this->zonaDe($colono);

        $this->actingAs($colono->user)
            ->patchJson("/zones/{$zona->id}/name", ['name' => str_repeat('a', 121)])
            ->assertUnprocessable();
    }

    /**
     * A entrega de material diz QUAL veículo partiu — tipo e placa — e não só "um veículo". Numa
     * colônia com dois ou três Furgões, "um veículo a caminho" não dizia qual.
     */
    public function test_a_entrega_de_material_diz_qual_veiculo_partiu(): void
    {
        $colono = $this->colono();
        $zona = $this->zonaDe($colono);
        $veiculo = $colono->vehicles()->first();

        $this->actingAs($colono->user)
            ->postJson("/zones/{$zona->id}/material", [
                'vehicle_id' => $veiculo->id,
                'cargo' => ['metal_bruto' => 400],
            ])
            ->assertCreated()
            ->assertJsonPath('type', $veiculo->type)
            ->assertJsonPath('plate', $veiculo->plate)
            ->assertJsonStructure(['id', 'type', 'plate', 'status', 'arrives_at']);
    }

    public function test_o_endpoint_constroi_a_partir_do_canteiro(): void
    {
        $colono = $this->colono();
        $zona = $this->zonaDe($colono);

        $this->encherCanteiro($zona, ['metal_bruto' => 500, 'ligas_metalicas' => 200]);

        $this->actingAs($colono->user)
            ->postJson("/zones/{$zona->id}/build", ['structure' => 'abrigo_de_robos'])
            ->assertCreated();

        $this->assertTrue($zona->fresh()->obraEmCurso());
    }

    /**
     * As três últimas do §17.4 (D-79) — a lacuna nunca foi de função, e continua não sendo: são
     * INERTES de propósito, como o Cemitério, mas agora têm custo e se erguem pelo canteiro.
     */
    public function test_as_tres_ultimas_estruturas_se_erguem_e_sao_inertes(): void
    {
        $colono = $this->colono();
        $zona = $this->zonaDe($colono);

        // Dá para o canteiro pagar as três seguidas (800 MB, 330 Ligas, 70 Componentes ao todo).
        $this->encherCanteiro($zona, [
            'metal_bruto' => 1000, 'ligas_metalicas' => 500, 'componentes_eletronicos' => 200,
        ]);

        foreach (
            ['estrutura_de_extracao', 'central_de_comunicacao', 'plataforma_de_pouso_da_zona']
            as $estrutura
        ) {
            $this->assertContains($estrutura, Estruturas::CONSTRUIVEIS);
            $this->assertTrue(Estruturas::de($estrutura)['inerte']);

            app(ConstruirNaZona::class)->handle($colono, $zona, $estrutura);

            // A mais lenta (Plataforma de Pouso) leva 6 h — 7 h basta para qualquer uma das três.
            $this->travelTo(now()->addHours(7));
            app(ConcluirObrasDaZona::class)->handle();

            $coluna = Estruturas::COLUNA[$estrutura];
            $this->assertSame(1, $zona->fresh()->{$coluna});
        }
    }

    /** O Posto de Comando nasce com a ocupação e **não se ergue** — não está entre as construíveis. */
    public function test_o_posto_de_comando_nao_se_ergue(): void
    {
        $this->assertNotContains('posto_de_comando', Estruturas::CONSTRUIVEIS);

        $colono = $this->colono();
        $zona = $this->zonaDe($colono);

        $this->expectException(DomainRuleException::class);
        app(ConstruirNaZona::class)->handle($colono, $zona, 'posto_de_comando');
    }
}
