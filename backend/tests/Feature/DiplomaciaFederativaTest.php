<?php

namespace Tests\Feature;

use App\Domain\Federacao\Aliancas;
use App\Domain\Federacao\Concentracao;
use App\Domain\Federacao\Diplomacia;
use App\Domain\Logistics\ConcluirTrechos;
use App\Domain\Logistics\OcuparZonaNeutra;
use App\Exceptions\DomainRuleException;
use App\Models\Colony;
use App\Models\Federation;
use App\Models\FederationSetting;
use App\Models\NeutralZone;
use App\Models\User;
use Database\Seeders\ResourceTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Aliança entre federações (A2.5, item 7 — a interface diplomática).
 *
 * *"Diplomata"* era um **cargo sem sistema**: existia desde o D-114 e só sabia convidar colônia.
 * O que estes testes guardam são as decisões de desenho, não os números — todos parâmetros.
 */
class DiplomaciaFederativaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ResourceTypeSeeder::class);
    }

    private int $proximo = 0;

    private int $proximaZona = 0;

    private function federacao(string $cargo = Federation::LIDER): array
    {
        $f = Federation::create(['name' => 'Fed'.$this->proximo, 'tag' => 'F'.$this->proximo++]);

        return [$f, $this->colonia($f->id, $cargo)];
    }

    private function colonia(?int $federationId, ?string $cargo = null): Colony
    {
        $u = User::create([
            'name' => 'c', 'nickname' => 'd'.$this->proximo,
            'email' => 'd'.$this->proximo++.'@t.test', 'password' => Hash::make('x'),
        ]);

        return Colony::create([
            'user_id' => $u->id, 'name' => 'C', 'x' => 0, 'y' => $this->proximo,
            'federation_id' => $federationId, 'federation_role' => $cargo,
        ]);
    }

    private function aliar(Federation $a, Colony $autorA, Federation $b, Colony $autorB): void
    {
        app(Diplomacia::class)->propor($autorA, $b);
        app(Diplomacia::class)->aceitar($autorB, $a);
    }

    private function zonas(Colony $dona, int $quantas): void
    {
        for ($n = 0; $n < $quantas; $n++) {
            $i = $this->proximaZona++;
            NeutralZone::create([
                'x' => 50 + intdiv($i, 90), 'y' => 50 + ($i % 90), 'name' => 'Z'.$i, 'district' => 'norte',
                'mineral' => 'metal_bruto', 'level' => 1,
                'owner_colony_id' => $dona->id, 'status' => 'ocupada',
            ]);
        }
    }

    // ────────────────────────────────────────────── consentimento mútuo

    public function test_propor_e_aceitar_firma_a_alianca(): void
    {
        [$a, $autorA] = $this->federacao();
        [$b, $autorB] = $this->federacao();

        $this->aliar($a, $autorA, $b, $autorB);

        $this->assertTrue(app(Aliancas::class)->saoAliadas($a->id, $b->id));
        $this->assertSame([$b->id], app(Aliancas::class)->aliadasDe($a->id));
    }

    /** Proposta sozinha não vale: o efeito de jogo nasce do consentimento das DUAS. */
    public function test_proposta_pendente_nao_torna_ninguem_aliado(): void
    {
        [$a, $autorA] = $this->federacao();
        [$b] = $this->federacao();

        app(Diplomacia::class)->propor($autorA, $b);

        $this->assertFalse(app(Aliancas::class)->saoAliadas($a->id, $b->id));
    }

    /**
     * ⚠️ Sem esta trava, um Diplomata proporia e aceitaria sozinho, e o "consentimento mútuo" seria
     * um comentário no código.
     */
    public function test_quem_propos_nao_aceita_a_propria_proposta(): void
    {
        [, $autorA] = $this->federacao();
        [$b] = $this->federacao();

        app(Diplomacia::class)->propor($autorA, $b);

        $this->expectException(DomainRuleException::class);
        app(Diplomacia::class)->aceitar($autorA, $b);
    }

    /** Entrar exige acordo; sair não exige refém. */
    public function test_romper_e_unilateral(): void
    {
        [$a, $autorA] = $this->federacao();
        [$b, $autorB] = $this->federacao();
        $this->aliar($a, $autorA, $b, $autorB);

        app(Diplomacia::class)->romper($autorA, $b);

        $this->assertFalse(app(Aliancas::class)->saoAliadas($a->id, $b->id));
    }

    // ────────────────────────────────────────────── as portas

    public function test_so_lider_ou_diplomata_tratam_de_alianca(): void
    {
        [, $autorA] = $this->federacao(Federation::INTENDENTE);
        [$b] = $this->federacao();

        $this->expectException(DomainRuleException::class);
        app(Diplomacia::class)->propor($autorA, $b);
    }

    public function test_o_diplomata_pode(): void
    {
        [$a, $autorA] = $this->federacao(Federation::DIPLOMATA);
        [$b, $autorB] = $this->federacao();

        $this->aliar($a, $autorA, $b, $autorB);

        $this->assertTrue(app(Aliancas::class)->saoAliadas($a->id, $b->id));
    }

    public function test_o_par_e_unico_nos_dois_sentidos(): void
    {
        [$a, $autorA] = $this->federacao();
        [$b, $autorB] = $this->federacao();

        app(Diplomacia::class)->propor($autorA, $b);

        // A mesma relação, proposta do outro lado: tem de bater na linha que já existe.
        $this->expectException(DomainRuleException::class);
        app(Diplomacia::class)->propor($autorB, $a);
    }

    /**
     * O teto de aliadas, e por que ele existe.
     *
     * Sem teto, todo mundo se alia a todo mundo e o mundo vira um bloco só — diplomacia deixa de ser
     * escolha no instante em que aliar-se não custa nada e não exclui nada.
     */
    public function test_o_teto_de_aliadas_trava(): void
    {
        /*
         * ⚠️ `singleton()` PRIMEIRO. Um `update()` na tabela de parâmetros ainda vazia não afeta
         * linha nenhuma, e o `singleton()` a criaria depois com o padrão — o teste mediria o valor
         * de fábrica achando que mediu o meu.
         */
        FederationSetting::singleton()->update(['max_aliadas' => 1]);

        [$a, $autorA] = $this->federacao();
        [$b, $autorB] = $this->federacao();
        [$c] = $this->federacao();

        $this->aliar($a, $autorA, $b, $autorB);

        $this->expectException(DomainRuleException::class);
        app(Diplomacia::class)->propor($autorA, $c);
    }

    // ────────────────────────────────────────────── ⚠️ o antimonopólio

    /**
     * ⚠️ **A decisão mais importante da fase**, e o teste que a guarda.
     *
     * Uma federação aliada a outra não são 12 colônias: são até 24 operando em conjunto. Se o teto de
     * ocupação de zonas continuasse olhando só a federação, **aliar-se viraria lavanderia de
     * monopólio** — bastaria montar federações aliadas em vez de uma grande, e a regra do §04 seria
     * contornada pela porta da frente.
     *
     * Cenário: cada uma com 1 zona de 10. Sozinhas, 10% — longe do teto de 20%. Aliadas, o bloco tem
     * 2 de 10, e bate.
     */
    public function test_o_teto_antimonopolio_conta_o_bloco_e_nao_so_a_federacao(): void
    {
        [$a, $autorA] = $this->federacao();
        [$b, $autorB] = $this->federacao();

        $donaA = $this->colonia($a->id);
        $donaB = $this->colonia($b->id);
        $this->zonas($donaA, 1);
        $this->zonas($donaB, 1);
        $this->zonas($this->colonia(null), 8);

        $conferir = function (Colony $c) {
            $m = new \ReflectionMethod(OcuparZonaNeutra::class, 'conferirTetoDaFederacao');
            $m->setAccessible(true);
            $m->invoke(app(OcuparZonaNeutra::class), $c->federation_id);
        };

        // Controle: sozinhas passam. Sem ele, o teste abaixo passaria mesmo se o teto travasse tudo.
        $conferir($donaA);
        $this->assertTrue(true, 'sozinha, 1 de 10 não bate no teto de 20%');

        $this->aliar($a, $autorA, $b, $autorB);

        $this->expectException(DomainRuleException::class);
        $conferir($donaA);
    }

    /** E a tela conta o mesmo bloco que o domínio — senão diria "cabem mais" e a ocupação seria negada. */
    public function test_a_tela_de_concentracao_conta_o_mesmo_bloco(): void
    {
        [$a, $autorA] = $this->federacao();
        [$b, $autorB] = $this->federacao();

        $this->zonas($this->colonia($a->id), 1);
        $this->zonas($this->colonia($b->id), 1);
        $this->zonas($this->colonia(null), 8);

        $this->aliar($a, $autorA, $b, $autorB);

        $c = app(Concentracao::class)->de($a->fresh());

        $this->assertSame(2, $c['zonas_da_federacao'], 'conta as duas do bloco');
        $this->assertSame(2, $c['federacoes_no_bloco']);
        $this->assertTrue($c['no_teto']);
    }

    /**
     * O bloco é RASO: aliado de aliado não é aliado.
     *
     * Com transitividade, um teto de 2 aliadas ainda produziria uma corrente ligando o mundo inteiro
     * num bloco só — exatamente o que o teto existe para impedir.
     */
    public function test_o_bloco_nao_e_transitivo(): void
    {
        [$a, $autorA] = $this->federacao();
        [$b, $autorB] = $this->federacao();
        [$c, $autorC] = $this->federacao();

        $this->aliar($a, $autorA, $b, $autorB);
        $this->aliar($b, $autorB, $c, $autorC);

        $this->assertFalse(app(Aliancas::class)->saoAliadas($a->id, $c->id));
        $this->assertNotContains($c->id, app(Aliancas::class)->bloco($a->id));
    }

    // ────────────────────────────────────────────── o desconto

    /**
     * ⚠️ O desconto entre federações aliadas é MENOR que o interno, e essa é a decisão.
     *
     * Se rendesse o mesmo, o teto de 12 membros viraria letra morta: bastaria montar federações
     * aliadas em vez de uma grande. O desconto menor é o que mantém filiar-se melhor do que aliar-se.
     */
    public function test_o_desconto_da_alianca_e_menor_que_o_da_filiacao(): void
    {
        $c = FederationSetting::singleton();

        $this->assertGreaterThan(
            (int) $c->desconto_tributo_aliancas_bps,
            (int) $c->desconto_tributo_aliados_bps,
            'filiar-se tem de valer mais que aliar-se, senão o teto de 12 membros não significa nada',
        );
    }

    /** E ele existe de verdade: a alíquota entre aliadas é menor que entre estranhas. */
    public function test_a_aliquota_cai_entre_federacoes_aliadas(): void
    {
        [$a, $autorA] = $this->federacao();
        [$b, $autorB] = $this->federacao();

        $origem = $this->colonia($a->id);
        $destino = $this->colonia($b->id);

        $aliquota = function (Colony $o, Colony $d) {
            $m = new \ReflectionMethod(ConcluirTrechos::class, 'aliquota');
            $m->setAccessible(true);

            return $m->invoke(app(ConcluirTrechos::class), $o, $d, 1_000);
        };

        $estranhas = $aliquota($origem, $destino);
        $this->aliar($a, $autorA, $b, $autorB);
        $aliadas = $aliquota($origem->fresh(), $destino->fresh());

        $this->assertLessThan($estranhas, $aliadas, 'a aliança tem de valer alguma coisa');
    }

    // ────────────────────────────────────────────── a API

    public function test_a_mesa_diplomatica_lista_relacoes_e_disponiveis(): void
    {
        [$a, $autorA] = $this->federacao();
        [$b] = $this->federacao();

        app(Diplomacia::class)->propor($autorA, $b);

        $corpo = $this->actingAs($autorA->user)->getJson('/federation/diplomacia')->assertOk()->json();

        $this->assertTrue($corpo['tem_federacao']);
        $this->assertTrue($corpo['pode_tratar']);
        $this->assertSame('proposta', $corpo['relacoes'][0]['status']);
        $this->assertTrue($corpo['relacoes'][0]['propus']);
        $this->assertGreaterThan($corpo['desconto_alianca'], $corpo['desconto_interno']);
    }

    public function test_a_api_exige_autenticacao(): void
    {
        [$b] = $this->federacao();

        $this->postJson("/federations/{$b->id}/alianca")->assertStatus(401);
    }

    public function test_a_alianca_some_quando_a_federacao_e_dissolvida(): void
    {
        [$a, $autorA] = $this->federacao();
        [$b, $autorB] = $this->federacao();
        $this->aliar($a, $autorA, $b, $autorB);

        $b->delete();

        $this->assertSame([], app(Aliancas::class)->aliadasDe($a->id));
        $this->assertSame(0, DB::table('federation_alliances')->count(), 'a cascata limpou a linha órfã');
    }
}
