<?php

namespace Tests\Feature;

use App\Domain\Federacao\Concentracao;
use App\Domain\Logistics\OcuparZonaNeutra;
use App\Exceptions\DomainRuleException;
use App\Models\Colony;
use App\Models\Federation;
use App\Models\FederationSetting;
use App\Models\NeutralZone;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Concentração da federação e o teto antimonopólio observável (A2.5).
 *
 * O limite existe desde o D-119 e funciona. O que faltava era **poder vê-lo chegando**: até aqui ele
 * bloqueava sem avisar, e o colono descobria o teto depois de já ter levado tropa e material até a
 * zona. O roadmap chama isso de "proteções antimonopólio observáveis".
 */
class ConcentracaoDaFederacaoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\ResourceTypeSeeder::class);
    }

    private int $proximo = 0;

    private int $proximaZona = 0;

    private function federacaoCom(int $zonasDela, int $zonasDeOutros): Federation
    {
        $f = Federation::create(['name' => 'Fed'.$this->proximo, 'tag' => 'F'.$this->proximo++]);

        $dona = $this->colonia($f->id);
        $alheia = $this->colonia(null);

        // Contador de CLASSE: `neutral_zones` tem posição única, e um contador local reiniciaria a
        // cada chamada — dois cenários no mesmo teste colidiriam no mesmo ponto do mapa.
        foreach ([[$zonasDela, $dona], [$zonasDeOutros, $alheia]] as [$quantas, $colonia]) {
            for ($n = 0; $n < $quantas; $n++) {
                $i = $this->proximaZona++;
                NeutralZone::create([
                    'x' => 50 + intdiv($i, 90), 'y' => 50 + ($i % 90), 'name' => 'Z'.$i, 'district' => 'norte',
                    'mineral' => 'metal_bruto', 'level' => 1,
                    'owner_colony_id' => $colonia->id, 'status' => 'ocupada',
                ]);
                $i++;
            }
        }

        return $f->fresh();
    }

    private function colonia(?int $federationId): Colony
    {
        $u = User::create([
            'name' => 'c', 'nickname' => 'f'.$this->proximo,
            'email' => 'f'.$this->proximo++.'@t.test', 'password' => Hash::make('x'),
        ]);

        return Colony::create([
            'user_id' => $u->id, 'name' => 'C', 'x' => 0, 'y' => $this->proximo,
            'federation_id' => $federationId,
        ]);
    }

    // ────────────────────────────────────────────── a conta

    public function test_a_ocupacao_e_a_fatia_de_todas_as_zonas_do_jogo(): void
    {
        $c = app(Concentracao::class)->de($this->federacaoCom(2, 8));

        $this->assertSame(2, $c['zonas_da_federacao']);
        $this->assertSame(10, $c['zonas_do_jogo']);
        $this->assertSame(2000, $c['ocupacao_bps'], '2 de 10 = 20%');
    }

    public function test_no_teto_e_marcado(): void
    {
        // Teto padrão: 2000 bps (20%).
        $this->assertTrue(app(Concentracao::class)->de($this->federacaoCom(2, 8))['no_teto']);
        $this->assertFalse(app(Concentracao::class)->de($this->federacaoCom(1, 9))['no_teto']);
    }

    /**
     * A conta de "quantas ainda cabem" **não é regra de três**, e é isso que a torna útil.
     *
     * Cada zona que a federação ocupa também aumenta o total de zonas ocupadas do jogo — o
     * denominador cresce junto. Uma regra de três simples daria um número errado, e errado para
     * MENOS, o que faria a tela assustar sem motivo.
     */
    public function test_quantas_zonas_ainda_cabem_considera_o_denominador_crescendo(): void
    {
        // 1 de 9 = 11%. Ingenuamente "cabe até 20% de 9", ou seja mais 0,8 → 0. Mas ocupando
        // mais uma fica 2 de 10 = 20%, que já é o teto; logo cabe exatamente UMA.
        $this->assertSame(1, app(Concentracao::class)->de($this->federacaoCom(1, 8))['zonas_ate_o_teto']);
    }

    public function test_no_teto_nao_cabe_mais_nenhuma(): void
    {
        $this->assertSame(0, app(Concentracao::class)->de($this->federacaoCom(3, 7))['zonas_ate_o_teto']);
    }

    public function test_mundo_sem_zonas_ocupadas_nao_divide_por_zero(): void
    {
        $c = app(Concentracao::class)->de($this->federacaoCom(0, 0));

        $this->assertSame(0, $c['ocupacao_bps']);
        $this->assertFalse($c['no_teto']);
    }

    // ────────────────────────── a invariante: uma conta só, em dois lugares

    /**
     * ⚠️ O que a tela diz e o que o domínio faz **têm de concordar**.
     *
     * Duas contas para o mesmo limite divergiriam no primeiro ajuste, e a tela passaria a dizer
     * "você pode" enquanto o domínio diz "você não pode" — o pior tipo de discordância, porque a
     * tela é quem o jogador acredita.
     *
     * Este teste amarra os dois: se a `Concentracao` diz que está no teto, a ocupação real precisa
     * recusar; se diz que não está, precisa passar por essa porta.
     */
    public function test_a_leitura_concorda_com_a_regra_quando_esta_no_teto(): void
    {
        /*
         * ⚠️ UMA federação por teste, e isto foi um erro meu antes: as duas viviam no mesmo mundo,
         * e a segunda acrescentava zonas ao denominador — a primeira deixava de estar no teto por
         * causa do próprio cenário. O mundo é compartilhado; o teste tem de saber disso.
         */
        $noTeto = $this->federacaoCom(2, 8);

        $this->assertTrue(app(Concentracao::class)->de($noTeto)['no_teto']);
        $this->assertTrue($this->dominioRecusa($noTeto), 'no teto, o domínio tem que recusar');
    }

    public function test_a_leitura_concorda_com_a_regra_quando_ha_folga(): void
    {
        $folgada = $this->federacaoCom(1, 20);

        $this->assertFalse(app(Concentracao::class)->de($folgada)['no_teto']);
        $this->assertFalse($this->dominioRecusa($folgada), 'com folga, o domínio tem que deixar passar');
    }

    /**
     * A porta do domínio, chamada por reflexão.
     *
     * É privada de propósito, e o ponto aqui é justamente conferir a MESMA expressão que o jogo usa,
     * não uma cópia dela: duas contas para o mesmo limite divergiriam no primeiro ajuste, e a tela
     * passaria a dizer "você pode" enquanto o domínio diz "você não pode" — o pior tipo de
     * discordância, porque a tela é quem o jogador acredita.
     */
    private function dominioRecusa(Federation $f): bool
    {
        $metodo = new \ReflectionMethod(OcuparZonaNeutra::class, 'conferirTetoDaFederacao');
        $metodo->setAccessible(true);

        try {
            $metodo->invoke(app(OcuparZonaNeutra::class), $f->id);

            return false;
        } catch (DomainRuleException) {
            return true;
        }
    }

    /** O teto é parâmetro do operador: mudá-lo muda a leitura na mesma hora. */
    public function test_mudar_o_teto_muda_a_leitura(): void
    {
        $f = $this->federacaoCom(2, 8);

        $this->assertTrue(app(Concentracao::class)->de($f)['no_teto']);

        FederationSetting::singleton()->update(['teto_ocupacao_zonas_bps' => 5000]);

        $this->assertFalse(app(Concentracao::class)->de($f->fresh())['no_teto']);
    }

    // ────────────────────────────────────────────────────────── a porta

    public function test_a_api_exige_autenticacao(): void
    {
        $this->getJson('/federation/concentracao')->assertStatus(401);
    }

    public function test_sem_federacao_a_api_diz_isso_em_vez_de_estourar(): void
    {
        $c = $this->colonia(null);

        $this->actingAs($c->user)->getJson('/federation/concentracao')
            ->assertOk()->assertJson(['tem_federacao' => false]);
    }
}
