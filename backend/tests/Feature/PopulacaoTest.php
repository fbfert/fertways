<?php

namespace Tests\Feature;

use App\Domain\Populacao\Ciclo;
use App\Domain\Populacao\Parametros;
use App\Domain\Populacao\Populacao;
use App\Domain\Production\ColonyTick;
use App\Models\Colony;
use App\Models\User;
use Database\Seeders\ResourceTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * População (A2.2) — o modelo, ainda desligado no jogo.
 *
 * ⚠️ Nada aqui afirma que um NÚMERO está certo: todos os parâmetros são HIPÓTESE, e o critério de
 * saída da fase proíbe promovê-los sem uma rodada registrada do simulador da trilha A2.S. O que
 * estes testes guardam são as **regras de forma** — o que degrada em vez de morrer, o que trava em
 * vez de derramar, o que é derivado em vez de guardado.
 */
class PopulacaoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ResourceTypeSeeder::class);
    }

    private int $proximo = 0;

    private function colonia(int $nivelHabitacao = 0, int $populacao = 0): Colony
    {
        $u = User::create([
            'name' => 'c', 'nickname' => 'c'.$this->proximo,
            'email' => 'c'.$this->proximo++.'@t.test', 'password' => Hash::make('x'),
        ]);

        $c = Colony::create([
            'user_id' => $u->id, 'name' => 'C', 'x' => 0, 'y' => 0, 'populacao' => $populacao,
        ]);

        if ($nivelHabitacao > 0) {
            $c->buildings()->create(['type' => 'estrutura_de_sobrevivencia', 'level' => $nivelHabitacao]);
        }

        return $c->fresh(['buildings']);
    }

    // ────────────────────────────────────────────── a chave-mestra

    /**
     * A invariante mais importante da fase.
     *
     * O mundo não tem reset e os parâmetros são todos palpite. Ligar isto por acidente mexeria na
     * economia de um jogo no ar, e o ledger é append-only: o estrago ficaria registrado para sempre.
     */
    public function test_a_populacao_nasce_desligada(): void
    {
        $this->assertFalse(app(Parametros::class)->ativo());
    }

    // ────────────────────────────────────────────── capacidade

    public function test_sem_estrutura_de_sobrevivencia_nao_ha_capacidade(): void
    {
        $this->assertSame(0, app(Populacao::class)->capacidade($this->colonia(0)));
    }

    public function test_a_capacidade_segue_a_curva_dos_parametros(): void
    {
        $p = app(Parametros::class);
        $base = (int) $p->todos()->capacidade_base;
        $fator = (int) $p->todos()->capacidade_fator_milesimos;

        $this->assertSame($base, $p->capacidade(1));
        $this->assertSame((int) floor($base * $fator / 1000), $p->capacidade(2));
    }

    // ────────────────────────────────────────────── os cinco estados

    /**
     * "Disponível" pode ser NEGATIVO, e não é bug.
     *
     * É o estado de quem foi grandfatherizado com folga curta, ou de quem perdeu população por
     * escassez. Zerar o negativo esconderia exatamente a situação que o jogo precisa mostrar.
     */
    public function test_disponivel_pode_ser_negativo(): void
    {
        $c = $this->colonia(1, 1);
        $c->buildings()->create(['type' => 'fazenda', 'level' => 1]);

        DB::table('building_operator_requirements')->insert([
            'building_type' => 'fazenda', 'level' => 1, 'operadores' => 5,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $estado = app(Populacao::class)->estado($c->fresh(['buildings']));

        $this->assertSame(5, $estado['em_construcoes']);
        $this->assertSame(-4, $estado['disponivel']);
    }

    /**
     * O requisito é o do nível ATUAL, não a soma da escada percorrida.
     *
     * Somar os níveis faria o requisito explodir com o progresso e tornaria a expansão impossível
     * por acidente aritmético — uma Fazenda 5 pediria a equipe de cinco fazendas.
     */
    public function test_o_requisito_e_do_nivel_atual_e_nao_a_soma_da_escada(): void
    {
        $c = $this->colonia(1, 100);
        $c->buildings()->create(['type' => 'fazenda', 'level' => 3]);

        DB::table('building_operator_requirements')->insert([
            ['building_type' => 'fazenda', 'level' => 1, 'operadores' => 2, 'created_at' => now(), 'updated_at' => now()],
            ['building_type' => 'fazenda', 'level' => 2, 'operadores' => 4, 'created_at' => now(), 'updated_at' => now()],
            ['building_type' => 'fazenda', 'level' => 3, 'operadores' => 6, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->assertSame(6, app(Populacao::class)->alocadaEmConstrucoes($c->fresh(['buildings'])));
    }

    /** Tabela esparsa: construção sem linha não exige ninguém. O requisito se afirma. */
    public function test_construcao_sem_requisito_cadastrado_nao_exige_ninguem(): void
    {
        $c = $this->colonia(1, 10);
        $c->buildings()->create(['type' => 'oficina', 'level' => 4]);

        $this->assertSame(0, app(Populacao::class)->alocadaEmConstrucoes($c->fresh(['buildings'])));
    }

    // ────────────────────────────────────────────── o ciclo

    /**
     * A razão de suprimento é a do recurso MAIS ESCASSO, não a média.
     *
     * Média esconderia o caso que interessa: nadando em água e sem oxigênio, a colônia teria razão
     * "boa" e continuaria crescendo rumo à asfixia.
     */
    public function test_o_gargalo_manda_e_nao_a_media(): void
    {
        $c = $this->colonia(3, 10);

        $r = app(Ciclo::class)->avancar($c, [
            'agua' => 999999, 'oxigenio' => 0, 'biomassa' => 999999, 'energia' => 999999,
        ], 1.0);

        $this->assertSame(0, $r['razao_suprimento_bps'], 'sem oxigênio, a razão é zero');
        $this->assertContains('oxigenio', $r['faltou']);
    }

    /** Escassez DEGRADA, não mata — mesma escolha do §6.6 para zona sem operadores. */
    public function test_escassez_degrada_e_nao_mata(): void
    {
        $c = $this->colonia(3, 10);

        $r = app(Ciclo::class)->avancar($c, [], 1.0);

        $this->assertSame(10, $r['populacao_nova'], 'ninguém morre de fome');
        $this->assertSame(
            (int) app(Parametros::class)->todos()->escassez_eficiencia_bps,
            $r['eficiencia_bps'],
            'a eficiência cai ao piso, e é só isso que acontece',
        );
    }

    public function test_sem_suprimento_minimo_a_populacao_nao_cresce(): void
    {
        $c = $this->colonia(3, 10);

        $this->assertFalse(app(Ciclo::class)->avancar($c, [], 1.0)['cresceu']);
    }

    /**
     * O resto fracionário é o que faz colônia pequena crescer.
     *
     * Sem ele, 5 colonos a 0,5%/h dão 5,025 num passo de uma hora, o `floor` devolve 5, e a
     * população fica presa em 5 para sempre. Foi a primeira rodada do simulador da trilha A2.S que
     * mostrou isso — a curva saiu horizontal por 60 dias.
     */
    public function test_o_resto_fracionario_destrava_o_crescimento_da_colonia_pequena(): void
    {
        $c = $this->colonia(3, 5);
        $ciclo = app(Ciclo::class);
        $fartura = ['agua' => 999999, 'oxigenio' => 999999, 'biomassa' => 999999, 'energia' => 999999];

        $cresceuAlgumaVez = false;

        for ($i = 0; $i < 48; $i++) {
            $r = $ciclo->avancar($c, $fartura, 1.0);
            $c->populacao = $r['populacao_nova'];
            $c->populacao_resto_milli = $r['resto_milli'];
            $cresceuAlgumaVez = $cresceuAlgumaVez || $r['cresceu'];
        }

        $this->assertTrue($cresceuAlgumaVez, 'em 48 horas de fartura a população TEM que andar');
        $this->assertGreaterThan(5, $c->populacao);
    }

    /** O teto TRAVA, não derrama — mesma regra que a A2.7 fixou para estoque. */
    public function test_o_teto_habitacional_trava(): void
    {
        $c = $this->colonia(1, 0);
        $capacidade = app(Populacao::class)->capacidade($c);
        $c->populacao = $capacidade;

        $r = app(Ciclo::class)->avancar($c, [
            'agua' => 999999, 'oxigenio' => 999999, 'biomassa' => 999999, 'energia' => 999999,
        ], 24.0);

        $this->assertSame($capacidade, $r['populacao_nova']);
        $this->assertFalse($r['cresceu']);
    }

    /** Delta nulo não pode esvaziar a colônia — foi um bug meu, e este teste o guarda. */
    public function test_intervalo_zero_nao_zera_a_populacao(): void
    {
        $c = $this->colonia(3, 42);

        $this->assertSame(42, app(Ciclo::class)->avancar($c, [], 0.0)['populacao_nova']);
    }

    // ────────────────────────────────────────────── grandfathering (§6.7)

    /**
     * A conta da migração: população para operar tudo o que já existe, mais folga.
     *
     * "Nenhuma colônia existente pode parar de produzir por uma regra que não existia quando ela foi
     * construída."
     */
    public function test_a_conta_do_grandfathering_inclui_a_folga(): void
    {
        $c = $this->colonia(1, 0);
        $c->buildings()->create(['type' => 'fazenda', 'level' => 1]);

        DB::table('building_operator_requirements')->insert([
            'building_type' => 'fazenda', 'level' => 1, 'operadores' => 10,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $folga = (int) app(Parametros::class)->todos()->migracao_folga_bps;
        $esperado = (int) ceil(10 * (10000 + $folga) / 10000);

        $this->assertSame($esperado, app(Populacao::class)->necessariaParaOQueJaTem($c->fresh(['buildings'])));
    }

    // ────────────────────────── a ligação ao tick

    /**
     * ⚠️ A chave-mestra tem de ser mesmo uma chave.
     *
     * Antes desta ligação, `population_settings.ativo` não era lido por ninguém: virá-la seria um
     * no-op, e quem a virasse acreditaria ter ligado a população. Este par de testes é o que impede
     * a chave de voltar a ser decorativa.
     */
    public function test_desligada_o_tick_nao_mexe_na_populacao(): void
    {
        $c = $this->colonia(3, 10);
        $c->update(['last_tick_at' => now()->subHours(48)]);
        $this->comEstoque($c);

        app(ColonyTick::class)->handle($c, now());

        $this->assertSame(10, (int) $c->fresh()->populacao, 'com a chave desligada, nada acontece');
    }

    public function test_ligada_o_tick_faz_crescer_e_consumir(): void
    {
        DB::table('population_settings')->where('id', 1)->update(['ativo' => true]);

        $c = $this->colonia(3, 10);
        $c->update(['last_tick_at' => now()->subHours(48)]);
        $this->comEstoque($c);

        $aguaAntes = (int) $c->resources()->where('resource_type', 'agua')->value('amount');

        app(ColonyTick::class)->handle($c, now());

        $depois = $c->fresh();
        $this->assertGreaterThan(10, (int) $depois->populacao, 'em 48 h de fartura a população anda');
        $this->assertLessThan(
            $aguaAntes,
            (int) $c->resources()->where('resource_type', 'agua')->value('amount'),
            'e os colonos bebem',
        );
    }

    private function comEstoque(Colony $c): void
    {
        foreach (['agua', 'oxigenio', 'biomassa', 'energia'] as $r) {
            $c->resources()->create(['resource_type' => $r, 'amount' => 100000]);
        }
    }

    // ────────────────────────── o grandfathering e a ativação

    /**
     * Sem grandfathering, ligar a população deixaria toda colônia em déficit permanente.
     *
     * `Ciclo::avancar()` devolve cedo quando o total é zero — população zero **não cresce sozinha**,
     * por construção: não há de quem nascer ninguém. É por isso que o comando tem de rodar ANTES da
     * chave, e não depois.
     */
    public function test_populacao_zero_nunca_cresce_sozinha(): void
    {
        $c = $this->colonia(3, 0);

        $r = app(Ciclo::class)->avancar($c, [
            'agua' => 999999, 'oxigenio' => 999999, 'biomassa' => 999999, 'energia' => 999999,
        ], 240.0);

        $this->assertSame(0, $r['populacao_nova']);
        $this->assertFalse($r['cresceu']);
    }

    public function test_o_grandfather_povoa_para_operar_o_que_ja_existe(): void
    {
        $c = $this->colonia(3, 0);
        $c->buildings()->create(['type' => 'fazenda', 'level' => 2]);

        DB::table('building_operator_requirements')->insert([
            'building_type' => 'fazenda', 'level' => 2, 'operadores' => 5,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->artisan('fertways:populacao-grandfather', ['--aplicar' => true])->assertSuccessful();

        // 5 exigidos + 20% de folga (§6.7) = 6.
        $this->assertSame(6, (int) $c->fresh()->populacao);
    }

    /** Quem não exige operador nenhum ainda recebe 1: zero nunca cresceria. */
    public function test_o_grandfather_da_piso_de_um(): void
    {
        $c = $this->colonia(1, 0);

        $this->artisan('fertways:populacao-grandfather', ['--aplicar' => true])->assertSuccessful();

        $this->assertSame(1, (int) $c->fresh()->populacao);
    }

    /** Repetível sem estrago: quem já tem população não é reescrito. */
    public function test_o_grandfather_nao_reescreve_quem_ja_tem(): void
    {
        $c = $this->colonia(3, 42);

        $this->artisan('fertways:populacao-grandfather', ['--aplicar' => true])->assertSuccessful();

        $this->assertSame(42, (int) $c->fresh()->populacao);
    }

    /**
     * O teto NÃO limita a concessão — ver o §6.7 e o docblock do comando.
     *
     * Vinte das 29 colônias de produção têm Estrutura de Sobrevivência nível 1 porque nunca houve
     * razão para subi-la. Limitar ao teto as poria em déficit por prédios erguidos antes da regra,
     * que é justamente o que o §6.7 proíbe.
     */
    public function test_o_grandfather_concede_acima_do_teto_para_operar_o_que_ja_existe(): void
    {
        $c = $this->colonia(1, 0);
        $c->buildings()->create(['type' => 'fazenda', 'level' => 2]);

        DB::table('building_operator_requirements')->insert([
            'building_type' => 'fazenda', 'level' => 2, 'operadores' => 40,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->artisan('fertways:populacao-grandfather', ['--aplicar' => true])->assertSuccessful();

        $c = $c->fresh(['buildings']);
        $capacidade = app(Populacao::class)->capacidade($c);

        $this->assertGreaterThan($capacidade, (int) $c->populacao, 'a concessão deve passar do teto');
        // Exatamente o exigido: a FOLGA, essa sim, não empurra ninguém mais para cima do limite.
        $this->assertSame(40, (int) $c->populacao);
    }

    /** E acima do teto ela não cresce, nem morre: o teto trava o crescimento, não expulsa ninguém. */
    public function test_acima_do_teto_a_populacao_nao_cresce_nem_morre(): void
    {
        $c = $this->colonia(1, 0);
        $c->buildings()->create(['type' => 'fazenda', 'level' => 2]);

        DB::table('building_operator_requirements')->insert([
            'building_type' => 'fazenda', 'level' => 2, 'operadores' => 40,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->artisan('fertways:populacao-grandfather', ['--aplicar' => true])->assertSuccessful();

        $c = $c->fresh(['buildings']);
        $farto = ['agua' => 999999, 'oxigenio' => 999999, 'comida' => 999999];

        $r = app(Ciclo::class)->avancar($c, $farto, 24.0);

        $this->assertSame(40, $r['populacao_nova'], 'não cresce acima do teto, e não perde ninguém');
        $this->assertFalse($r['cresceu']);
    }

    public function test_o_grandfather_sem_aplicar_nao_povoa(): void
    {
        $c = $this->colonia(3, 0);

        $this->artisan('fertways:populacao-grandfather')->assertSuccessful();

        $this->assertSame(0, (int) $c->fresh()->populacao);
    }

    // ────────────────────────────────────────────── a trilha A2.S

    /**
     * O simulador roda e **não deixa rastro** — a regra 3 da trilha.
     *
     * Ele precisa escrever para o domínio real ter em que operar; a transação revertida é o que
     * torna isso seguro. Se um dia esta asserção falhar, o simulador virou uma ferramenta que
     * suja o banco que ele existe para não sujar.
     */
    public function test_o_simulador_nao_deixa_rastro(): void
    {
        $coloniasAntes = Colony::count();
        $usuariosAntes = User::count();

        $this->artisan('fertways:simular-populacao', ['--dias' => 2, '--nivel-habitacao' => 2])
            ->assertSuccessful();

        $this->assertSame($coloniasAntes, Colony::count(), 'nenhuma colônia sobreviveu à simulação');
        $this->assertSame($usuariosAntes, User::count(), 'nenhum usuário sobreviveu à simulação');
    }
}
