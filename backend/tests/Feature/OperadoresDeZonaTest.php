<?php

namespace Tests\Feature;

use App\Domain\Colony\CreateColony;
use App\Domain\Logistics\ExtrairZonasNeutras;
use App\Domain\Logistics\OcuparZonaNeutra;
use App\Domain\Production\ColonyTick;
use App\Domain\Zona\Operadores;
use App\Exceptions\DomainRuleException;
use App\Models\Colony;
use App\Models\NeutralZone;
use App\Models\User;
use Database\Seeders\BuildingSpecSeeder;
use Database\Seeders\ResourceTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Zonas neutras × população (A2.6).
 *
 * O que estes testes guardam é a promessa do §6.6 — **degrada, não se perde** — e o princípio da
 * fase: *"poucos humanos operam muitos robôs"*.
 */
class OperadoresDeZonaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ResourceTypeSeeder::class);
        $this->seed(BuildingSpecSeeder::class);
    }

    private int $proximo = 0;

    private function ligarPopulacao(): void
    {
        DB::table('population_settings')->where('id', 1)->update(['ativo' => true]);
    }

    private function colonia(int $populacao = 50): Colony
    {
        $u = User::create([
            'name' => 'c', 'nickname' => 'z'.$this->proximo,
            'email' => 'z'.$this->proximo++.'@t.test', 'password' => Hash::make('x'),
        ]);

        return Colony::create([
            'user_id' => $u->id, 'name' => 'C', 'x' => 0, 'y' => $this->proximo,
            'populacao' => $populacao,
        ]);
    }

    private function zona(?Colony $dona, int $nivel = 1, int $operadores = 0): NeutralZone
    {
        $i = $this->proximo++;

        return NeutralZone::create([
            'x' => 50 + intdiv($i, 90), 'y' => 50 + ($i % 90), 'name' => 'Z'.$i, 'district' => 'norte',
            'mineral' => 'metal_bruto', 'level' => $nivel,
            'owner_colony_id' => $dona?->id, 'status' => $dona ? 'ocupada' : 'livre',
            'operadores' => $operadores,
            'productive_at' => now()->subDay(), 'last_extraction_at' => now()->subDay(),
        ]);
    }

    // ────────────────────────────────────────────── ⚠️ degrada, não se perde

    /**
     * ⚠️ **A promessa inteira do §6.6 em um teste.**
     *
     * Zona sem a equipe exigida produz MENOS — e não é perdida, nem devolvida, nem destruída. Num
     * jogo persistente sem reset, perder território por ter passado o fim de semana fora não é
     * dificuldade, é hostilidade.
     */
    public function test_zona_sem_equipe_produz_menos_e_continua_sendo_sua(): void
    {
        $this->ligarPopulacao();
        $dona = $this->colonia();

        $vazia = $this->zona($dona, 2, 0);
        $cheia = $this->zona($dona, 2, app(Operadores::class)->exigidos($vazia));

        app(ExtrairZonasNeutras::class)->handle(now());

        $rendeuCheia = (int) $cheia->fresh()->deposit_amount;
        $rendeuVazia = (int) $vazia->fresh()->deposit_amount;

        $this->assertGreaterThan($rendeuVazia, $rendeuCheia, 'a equipe completa rende mais');
        $this->assertGreaterThan(0, $rendeuVazia, 'mas a desfalcada ainda rende: degrada, não para');
        $this->assertSame($dona->id, (int) $vazia->fresh()->owner_colony_id, 'e continua sendo dela');
    }

    /** Com a população desligada, equipe nenhuma é cobrada — e as duas rendem igual. */
    public function test_desligada_a_equipe_nao_e_cobrada(): void
    {
        $dona = $this->colonia();
        $cheia = $this->zona($dona, 2, 99);
        $vazia = $this->zona($dona, 2, 0);

        app(ExtrairZonasNeutras::class)->handle(now());

        $this->assertSame(
            (int) $cheia->fresh()->deposit_amount,
            (int) $vazia->fresh()->deposit_amount,
            'desligada, a equipe não muda nada',
        );
    }

    // ────────────────────────────────────────────── transferência e retorno

    public function test_alocar_tira_do_disponivel_e_devolver_repoe(): void
    {
        $this->ligarPopulacao();
        $dona = $this->colonia(50);
        $zona = $this->zona($dona, 1, 0);

        $antes = app(Operadores::class)->disponivel($dona->fresh());
        app(Operadores::class)->alocar($dona, $zona, 2);

        $this->assertSame($antes - 2, app(Operadores::class)->disponivel($dona->fresh()));

        app(Operadores::class)->devolver($dona, $zona->fresh(), 2);

        $this->assertSame($antes, app(Operadores::class)->disponivel($dona->fresh()));
        $this->assertSame(0, (int) $zona->fresh()->operadores);
    }

    /** Colono a mais na zona não produz nada — mandar seria desperdício silencioso. */
    public function test_nao_aloca_alem_do_exigido(): void
    {
        $this->ligarPopulacao();
        $dona = $this->colonia(50);
        $zona = $this->zona($dona, 1, 0);
        $exigidos = app(Operadores::class)->exigidos($zona);

        $this->expectException(DomainRuleException::class);
        app(Operadores::class)->alocar($dona, $zona, $exigidos + 1);
    }

    public function test_nao_aloca_sem_colono_livre(): void
    {
        $this->ligarPopulacao();
        $dona = $this->colonia(1);
        $zona = $this->zona($dona, 5, 0);

        $this->expectException(DomainRuleException::class);
        app(Operadores::class)->alocar($dona, $zona, 2);
    }

    public function test_zona_de_outro_e_recusada(): void
    {
        $this->ligarPopulacao();
        $dona = $this->colonia();
        $zona = $this->zona($this->colonia(), 1, 0);

        $this->expectException(DomainRuleException::class);
        app(Operadores::class)->alocar($dona, $zona, 1);
    }

    /**
     * Devolver TODOS é permitido, e a zona só degrada.
     *
     * Impedir o retorno prenderia o jogador numa zona que ele já não quer manter — e o §6.6 escolheu
     * degradar em vez de aprisionar.
     */
    public function test_devolver_todos_e_permitido(): void
    {
        $this->ligarPopulacao();
        $dona = $this->colonia(50);
        $zona = $this->zona($dona, 1, 2);

        app(Operadores::class)->devolver($dona, $zona, 2);

        $this->assertSame(0, (int) $zona->fresh()->operadores);
        $this->assertSame($dona->id, (int) $zona->fresh()->owner_colony_id);
    }

    // ────────────────────────────────────────────── o Abrigo de Robôs

    /**
     * ⚠️ O Abrigo de Robôs finalmente faz o que o nome diz.
     *
     * Até aqui ele só servia de defesa contra o Predador, e o próprio catálogo de estruturas admitia
     * que a função de recuperação *"o GDD promete e nunca cronometra"*. Cada nível dispensa um
     * operador humano — é o princípio da fase escrito em código: *"poucos humanos operam muitos
     * robôs"*.
     */
    public function test_o_abrigo_de_robos_dispensa_operadores(): void
    {
        $this->ligarPopulacao();
        $dona = $this->colonia();
        $zona = $this->zona($dona, 5, 0);

        $semAbrigo = app(Operadores::class)->exigidos($zona);

        DB::table('zone_structures')->insert([
            'neutral_zone_id' => $zona->id, 'slot' => 1, 'type' => 'abrigo_de_robos', 'level' => 2,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $comAbrigo = app(Operadores::class)->exigidos($zona->fresh());

        $this->assertSame($semAbrigo - 2, $comAbrigo, 'cada nível do Abrigo poupa um colono');
    }

    /**
     * ⚠️ Piso de 1 enquanto a zona exigir alguém.
     *
     * Zerar o requisito faria uma zona operar sozinha para sempre, e território sem gente nenhuma
     * tira do jogo a decisão que esta fase inteira existe para criar.
     */
    public function test_o_abrigo_nunca_zera_a_equipe(): void
    {
        $this->ligarPopulacao();
        $dona = $this->colonia();
        $zona = $this->zona($dona, 1, 0);

        DB::table('zone_structures')->insert([
            'neutral_zone_id' => $zona->id, 'slot' => 1, 'type' => 'abrigo_de_robos', 'level' => 10,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame(1, app(Operadores::class)->exigidos($zona->fresh()));
    }

    // ────────────────────────────────────────────── impedimento por falta de população

    public function test_ocupar_zona_nova_exige_colono_livre(): void
    {
        $this->ligarPopulacao();
        $dona = $this->colonia(0);
        $livre = $this->zona(null);

        $this->expectException(DomainRuleException::class);
        app(OcuparZonaNeutra::class)->handle($dona, $livre);
    }

    // ────────────────────────────────────────────── a penalidade da colônia

    /**
     * ⚠️ A segunda etapa da ativação da população, que o D-178 adiou.
     *
     * O `Ciclo` calculava `eficiencia_bps` e **ninguém consumia** — o número certo indo para o vazio.
     * Aqui a escassez finalmente custa produção. O par com o controle abaixo é o que impede este
     * teste de passar por nada ter acontecido.
     */
    public function test_em_escassez_a_colonia_produz_menos(): void
    {
        $this->ligarPopulacao();

        $farta = $this->coloniaProdutora(estoque: 100_000);
        $faminta = $this->coloniaProdutora(estoque: 0);

        app(ColonyTick::class)->handle($farta, now()->addHours(6));
        app(ColonyTick::class)->handle($faminta, now()->addHours(6));

        $this->assertGreaterThan(
            $this->estoque($faminta, 'metal_bruto'),
            $this->estoque($farta, 'metal_bruto'),
            'a colônia em escassez produz menos que a suprida',
        );
    }

    /** O controle: sem população ligada, as duas produzem igual. */
    public function test_controle_desligada_a_escassez_nao_custa_producao(): void
    {
        $farta = $this->coloniaProdutora(estoque: 100_000);
        $faminta = $this->coloniaProdutora(estoque: 0);

        app(ColonyTick::class)->handle($farta, now()->addHours(6));
        app(ColonyTick::class)->handle($faminta, now()->addHours(6));

        $this->assertSame(
            $this->estoque($farta, 'metal_bruto'),
            $this->estoque($faminta, 'metal_bruto'),
            'desligada, a despensa vazia não muda a produção',
        );
    }

    private function coloniaProdutora(int $estoque): Colony
    {
        $u = User::factory()->create();
        $c = app(CreateColony::class)->handle($u, 'Prod', 10 + $this->proximo++, 20);

        $c->resources()->update(['amount' => 0]);
        $this->erguerPredio($c, 'mina_local', 5);
        $c->update(['populacao' => 20]);

        // Os essenciais que a população come — cheios ou vazios, conforme o cenário.
        foreach (['agua', 'oxigenio', 'biomassa'] as $r) {
            $c->resources()->where('resource_type', $r)->update(['amount' => $estoque]);
        }

        return $c->fresh();
    }

    private function estoque(Colony $c, string $recurso): int
    {
        return (int) $c->resources()->where('resource_type', $recurso)->value('amount');
    }
}
