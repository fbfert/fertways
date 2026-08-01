<?php

namespace Tests\Feature;

use App\Domain\Endurance\EfeitosDaEndurance;
use App\Domain\Pesquisa\ConcluirPesquisa;
use App\Domain\Pesquisa\EfeitosDaPesquisa;
use App\Domain\Pesquisa\Pesquisar;
use App\Domain\Pesquisa\Vagas;
use App\Exceptions\DomainRuleException;
use App\Models\Colony;
use App\Models\Ledger;
use App\Models\Technology;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Pesquisa (A2.3).
 *
 * ⚠️ Nada aqui afirma que um NÚMERO está certo — o GDD não publica árvore, custo nem tempo, e todos
 * os valores do seeder são HIPÓTESE. O que estes testes guardam são as **regras**: o que trava, o
 * que só conta quando conclui, e o que não pode ser burlado iniciando e não terminando.
 */
class PesquisaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\ResourceTypeSeeder::class);
        $this->seed(\Database\Seeders\TechnologySeeder::class);
    }

    private int $proximo = 0;

    private function colonia(int $nivelLab = 0, array $recursos = []): Colony
    {
        $u = User::create([
            'name' => 'c', 'nickname' => 'p'.$this->proximo,
            'email' => 'p'.$this->proximo++.'@t.test', 'password' => Hash::make('x'),
        ]);

        // Posição distinta por colônia: `colonies` tem índice único em (x, y) — duas no mesmo
        // ponto do mapa não existem, e um teste que cria duas precisa respeitar isso.
        $c = Colony::create(['user_id' => $u->id, 'name' => 'C', 'x' => 0, 'y' => $this->proximo]);

        if ($nivelLab > 0) {
            $c->buildings()->create(['type' => 'laboratorio', 'level' => $nivelLab]);
        }

        foreach ($recursos as $tipo => $qtd) {
            $c->resources()->create(['resource_type' => $tipo, 'amount' => $qtd]);
        }

        return $c->fresh(['buildings']);
    }

    private function ligar(): void
    {
        DB::table('research_settings')->where('id', 1)->update(['ativo' => true]);
    }

    private function tec(string $chave): Technology
    {
        return Technology::where('chave', $chave)->firstOrFail();
    }

    private function farta(int $nivelLab = 3): Colony
    {
        return $this->colonia($nivelLab, [
            'metal_bruto' => 99999, 'componentes_eletronicos' => 99999, 'energia' => 99999,
            'biomassa' => 99999, 'ligas_metalicas' => 99999, 'niobio_alienigena' => 9999,
            'quartzo_piezoeletrico' => 9999,
        ]);
    }

    // ────────────────────────────────────────────── a chave-mestra

    /**
     * Nasce desligada, pela mesma razão da população (D-167).
     *
     * Todo número desta fase é palpite, o mundo não tem reset, e o ledger é append-only: uma árvore
     * com custo inventado mexeria de verdade na economia de um jogo que está no ar.
     */
    public function test_a_pesquisa_nasce_desligada(): void
    {
        $this->expectException(DomainRuleException::class);

        app(Pesquisar::class)->handle($this->farta(), $this->tec('tec_energia_1'));
    }

    // ────────────────────────────────────────────── as portas

    public function test_sem_laboratorio_nao_ha_vaga_nenhuma(): void
    {
        $this->assertSame(0, app(Vagas::class)->total($this->colonia(0)));
    }

    public function test_o_nivel_minimo_de_laboratorio_trava(): void
    {
        $this->ligar();

        $this->expectException(DomainRuleException::class);
        // `tec_ciencia_1` exige Laboratório 3.
        app(Pesquisar::class)->handle($this->farta(1), $this->tec('tec_ciencia_1'));
    }

    public function test_o_pre_requisito_trava(): void
    {
        $this->ligar();
        $c = $this->farta();

        $dependente = Technology::create([
            'chave' => 'tec_dependente', 'nome' => 'Depende', 'descricao' => 'x',
            'trilha' => 'energia', 'requer_technology_id' => $this->tec('tec_energia_1')->id,
            'custo_json' => ['metal_bruto' => 1], 'duracao_segundos' => 60,
        ]);

        $this->expectException(DomainRuleException::class);
        app(Pesquisar::class)->handle($c, $dependente);
    }

    public function test_a_vaga_trava_a_segunda_pesquisa(): void
    {
        $this->ligar();
        // vagas_base 1 + intdiv(3, 5) = 1 vaga.
        $c = $this->farta(3);

        app(Pesquisar::class)->handle($c, $this->tec('tec_energia_1'));
        $this->assertSame(0, app(Vagas::class)->livres($c));

        $this->expectException(DomainRuleException::class);
        app(Pesquisar::class)->handle($c, $this->tec('tec_biosfera_1'));
    }

    /** O paralelismo vem do nível do Laboratório — o Observatório não existe (§7.2). */
    public function test_o_laboratorio_alto_abre_mais_vagas(): void
    {
        $this->assertSame(1, app(Vagas::class)->total($this->colonia(3)));
        $this->assertSame(2, app(Vagas::class)->total($this->colonia(5)));
    }

    /**
     * O mecanismo de vagas nasce como SOMA DE FONTES, e o roadmap pede isso por escrito.
     *
     * Hoje há uma fonte; o Observatório entraria como outra, sem refazer o modelo. Este teste
     * guarda a forma, não o número.
     */
    public function test_as_vagas_saem_de_fontes_nomeadas(): void
    {
        $this->assertSame(['laboratorio'], array_keys(app(Vagas::class)->fontes($this->colonia(3))));
    }

    // ────────────────────────────────────────────── o custo

    public function test_o_custo_e_debitado_e_vai_ao_ledger_como_saida(): void
    {
        $this->ligar();
        $c = $this->farta();
        $antes = (int) $c->resources()->where('resource_type', 'metal_bruto')->first()->amount;

        app(Pesquisar::class)->handle($c, $this->tec('tec_energia_1'));

        $custo = (int) $this->tec('tec_energia_1')->custo_json['metal_bruto'];
        $this->assertSame($antes - $custo, (int) $c->resources()->where('resource_type', 'metal_bruto')->first()->amount);

        $lancamento = Ledger::where('colony_id', $c->id)->where('type', 'custo_pesquisa')
            ->where('resource_type', 'metal_bruto')->firstOrFail();

        // Negativo, como todo custo escreve — foi a lição do D-164.
        $this->assertSame(-$custo, (int) $lancamento->amount);
    }

    public function test_sem_recurso_a_pesquisa_nao_comeca(): void
    {
        $this->ligar();
        $c = $this->colonia(3, ['metal_bruto' => 1]);

        $this->expectException(DomainRuleException::class);
        app(Pesquisar::class)->handle($c, $this->tec('tec_energia_1'));
    }

    // ────────────────────────────────────────────── conclusão e efeito

    /**
     * O nível sobe na CONCLUSÃO, não no início.
     *
     * Se subisse ao iniciar, uma pesquisa começada e nunca terminada já daria o efeito do nível
     * novo — e valeria a pena iniciar tudo e não concluir nada.
     */
    public function test_o_nivel_sobe_ao_concluir_e_nao_ao_comecar(): void
    {
        $this->ligar();
        $c = $this->farta();

        app(Pesquisar::class)->handle($c, $this->tec('tec_energia_1'));

        $linha = DB::table('colony_technologies')->where('colony_id', $c->id)->first();
        $this->assertSame(0, (int) $linha->nivel);
        $this->assertSame('pesquisando', $linha->status);

        DB::table('colony_technologies')->where('id', $linha->id)
            ->update(['finishes_at' => now()->subMinute()]);

        $this->assertSame(1, app(ConcluirPesquisa::class)->handle($c));
        $this->assertSame(1, (int) DB::table('colony_technologies')->where('id', $linha->id)->first()->nivel);
    }

    /** Pesquisa em andamento não dá bônus nenhum — senão bastaria começar. */
    public function test_pesquisa_em_andamento_nao_da_efeito(): void
    {
        $this->ligar();
        $c = $this->farta();

        app(Pesquisar::class)->handle($c, $this->tec('tec_energia_1'));

        $this->assertSame(0, app(EfeitosDaPesquisa::class)->bonusDeProducao($c, 'reator_de_energia'));
    }

    public function test_o_efeito_aparece_depois_de_concluir(): void
    {
        $this->ligar();
        $c = $this->farta();

        app(Pesquisar::class)->handle($c, $this->tec('tec_energia_1'));
        DB::table('colony_technologies')->where('colony_id', $c->id)
            ->update(['finishes_at' => now()->subMinute()]);
        app(ConcluirPesquisa::class)->handle($c);

        $this->assertSame(300, app(EfeitosDaPesquisa::class)->bonusDeProducao($c, 'reator_de_energia'));
    }

    /** O efeito é do alvo certo: pesquisar reator não melhora a fazenda. */
    public function test_o_efeito_nao_vaza_para_outro_alvo(): void
    {
        $this->ligar();
        $c = $this->farta();

        app(Pesquisar::class)->handle($c, $this->tec('tec_energia_1'));
        DB::table('colony_technologies')->where('colony_id', $c->id)
            ->update(['finishes_at' => now()->subMinute(), 'status' => 'concluida', 'nivel' => 1]);

        $this->assertSame(0, app(EfeitosDaPesquisa::class)->bonusDeProducao($c, 'fazenda'));
    }

    /** O efeito escala pelo nível ATUAL — não pela soma da escada percorrida. */
    public function test_o_efeito_escala_pelo_nivel_atual(): void
    {
        $c = $this->farta();

        DB::table('colony_technologies')->insert([
            'colony_id' => $c->id, 'technology_id' => $this->tec('tec_energia_1')->id,
            'nivel' => 3, 'status' => 'concluida', 'versao' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // 300 bps por nível × 3 = 900, e não 300+600+900.
        $this->assertSame(900, app(EfeitosDaPesquisa::class)->bonusDeProducao($c, 'reator_de_energia'));
    }

    /**
     * O teto agregado é o MESMO da Endurance.
     *
     * Um vocabulário paralelo faria duas fontes de bônus com regras diferentes para a mesma coisa,
     * e o teto de uma não conheceria a outra.
     */
    public function test_o_efeito_respeita_o_teto_da_endurance(): void
    {
        $c = $this->farta();
        $teto = EfeitosDaEndurance::tetoBps(EfeitosDaEndurance::PRODUCAO_BONUS);

        $absurda = Technology::create([
            'chave' => 'tec_absurda', 'nome' => 'Absurda', 'descricao' => 'x', 'trilha' => 'energia',
            'custo_json' => ['metal_bruto' => 1], 'duracao_segundos' => 60, 'nivel_maximo' => 9,
            'efeitos_json' => [['tipo' => EfeitosDaEndurance::PRODUCAO_BONUS,
                'alvo' => 'reator_de_energia', 'valor_bps' => 99999]],
        ]);

        DB::table('colony_technologies')->insert([
            'colony_id' => $c->id, 'technology_id' => $absurda->id,
            'nivel' => 9, 'status' => 'concluida', 'versao' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame($teto, app(EfeitosDaPesquisa::class)->bonusDeProducao($c, 'reator_de_energia'));
    }

    public function test_nao_se_pesquisa_a_mesma_coisa_duas_vezes_ao_mesmo_tempo(): void
    {
        $this->ligar();
        $c = $this->farta(10); // Laboratório alto: há vaga sobrando, então o que trava é outra coisa.

        app(Pesquisar::class)->handle($c, $this->tec('tec_energia_1'));

        $this->expectException(DomainRuleException::class);
        app(Pesquisar::class)->handle($c, $this->tec('tec_energia_1'));
    }

    // ────────────────────────────────────────────── o catálogo

    /** As oito trilhas da fase existem. "Espacial" fica preparada e não entra nesta entrega. */
    public function test_as_oito_trilhas_iniciais_estao_no_catalogo(): void
    {
        $noCatalogo = Technology::distinct()->pluck('trilha')->sort()->values()->all();
        $declaradas = collect(array_keys(Technology::TRILHAS))->sort()->values()->all();

        $this->assertSame($declaradas, $noCatalogo);
    }

    // ────────────────────────────────────────────── a trilha A2.S

    /**
     * O simulador não deixa rastro — e aqui isso é mais grave do que no de população.
     *
     * Ele **liga a pesquisa** (`research_settings.ativo = true`) dentro do mundo descartável, porque
     * precisa exercitar o `Pesquisar` de verdade. Se o rollback falhasse, a produção acordaria com a
     * pesquisa ABERTA e números de palpite valendo. É o pior estrago possível desta fase, e é este
     * teste que fecha a porta.
     */
    public function test_o_simulador_nao_deixa_a_pesquisa_ligada(): void
    {
        $this->assertFalse((bool) DB::table('research_settings')->find(1)->ativo);

        $this->artisan('fertways:simular-pesquisa', ['--passos' => 1])->assertSuccessful();

        $this->assertFalse(
            (bool) DB::table('research_settings')->find(1)->ativo,
            'o simulador ligou a pesquisa e não desligou — a produção ficaria aberta',
        );
    }

    public function test_o_simulador_nao_deixa_colonia_nem_pesquisa_para_tras(): void
    {
        $coloniasAntes = Colony::count();
        $pesquisasAntes = DB::table('colony_technologies')->count();

        $this->artisan('fertways:simular-pesquisa', ['--passos' => 2])->assertSuccessful();

        $this->assertSame($coloniasAntes, Colony::count());
        $this->assertSame($pesquisasAntes, DB::table('colony_technologies')->count());
    }

    public function test_toda_tecnologia_do_seeder_usa_efeito_do_vocabulario_conhecido(): void
    {
        foreach (Technology::all() as $t) {
            foreach ($t->efeitos_json ?? [] as $e) {
                $this->assertContains(
                    $e['tipo'], EfeitosDaEndurance::TIPOS,
                    "A tecnologia {$t->chave} usa um efeito fora do vocabulário da casa.",
                );
            }
        }
    }
}
