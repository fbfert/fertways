<?php

namespace Tests\Feature;

use App\Domain\Colony\Silo;
use App\Models\Colony;
use App\Models\User;
use Database\Seeders\ResourceTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * A curva de proteção do Depósito Local (D-198).
 *
 * ⚠️ Estes testes existem porque `silo_capacidades` foi **plana em 10.000 nos dez níveis** durante
 * meses, e ninguém percebeu: a tabela respondia a consultas, passava nos testes, e só a medição
 * contra a produção mostrou que o nível do prédio nunca protegeu nada.
 *
 * O que eles guardam não é a curva — é a **propriedade**: subir o Depósito tem de proteger mais.
 */
class ProtecaoDoDepositoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ResourceTypeSeeder::class);
    }

    private int $proximo = 0;

    private function colonia(int $nivelDeposito): Colony
    {
        $u = User::create([
            'name' => 'c', 'nickname' => 's'.$this->proximo,
            'email' => 's'.$this->proximo++.'@t.test', 'password' => Hash::make('x'),
        ]);

        $c = Colony::create(['user_id' => $u->id, 'name' => 'C', 'x' => 0, 'y' => $this->proximo]);
        $c->buildings()->create(['type' => 'deposito_local', 'level' => $nivelDeposito]);

        return $c->fresh();
    }

    /**
     * ⚠️ **A propriedade que estava quebrada:** subir o Depósito protege mais.
     *
     * Com a tabela plana, este teste falharia — e é exatamente por isso que ele existe.
     */
    public function test_subir_o_deposito_protege_mais(): void
    {
        $silo = app(Silo::class);

        $n1 = $silo->capacidade($this->colonia(1), 'agua');
        $n5 = $silo->capacidade($this->colonia(5), 'agua');
        $n10 = $silo->capacidade($this->colonia(10), 'agua');

        $this->assertGreaterThan($n1, $n5, 'o nível 5 protege mais que o 1');
        $this->assertGreaterThan($n5, $n10, 'e o 10 protege mais que o 5');
    }

    /** O protegido é o que CABE; o exposto é o resto. Nunca negativo, nunca sobreposto. */
    public function test_protegido_e_exposto_somam_o_estoque(): void
    {
        $c = $this->colonia(1);
        $silo = app(Silo::class);
        $teto = $silo->capacidade($c, 'agua');

        foreach ([0, intdiv($teto, 2), $teto, $teto * 3] as $quanto) {
            $this->assertSame(
                $quanto,
                $silo->protegido($c, 'agua', $quanto) + $silo->exposto($c, 'agua', $quanto),
                "protegido + exposto tem de dar o estoque ({$quanto})",
            );
        }
    }

    /**
     * ⚠️ A base decidida no D-198, e o que ela significa.
     *
     * Não é o número que importa — é a consequência: quem tem menos que a base **não tem nada
     * exposto**. Com os 10.000 de antes, a mediana de oxigênio do mundo (90.201) deixava 80 mil ao
     * relento por colônia.
     */
    public function test_quem_esta_abaixo_da_base_nao_tem_nada_exposto(): void
    {
        $c = $this->colonia(1);

        $this->assertSame(0, app(Silo::class)->exposto($c, 'agua', 49_000));
        $this->assertGreaterThan(0, app(Silo::class)->exposto($c, 'agua', 60_000));
    }

    /** Recurso fora da tabela não protege nada — e isso tem de ser explícito, não acidente. */
    public function test_recurso_sem_linha_nao_protege(): void
    {
        DB::table('silo_capacidades')->where('resource_type', 'agua')->delete();

        $this->assertSame(0, app(Silo::class)->capacidade($this->colonia(1), 'agua'));
    }
}
