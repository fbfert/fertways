<?php

namespace Tests\Feature;

use App\Domain\Eventos\Modificadores;
use App\Domain\Federacao\Aliancas;
use App\Domain\Federacao\ContribuirParaOFundo;
use App\Domain\Federacao\Diplomacia;
use App\Domain\GuerraFederativa\Capitulacao;
use App\Domain\GuerraFederativa\DeclararGuerra;
use App\Domain\GuerraFederativa\EncerrarGuerras;
use App\Domain\GuerraFederativa\Neutralidade;
use App\Domain\GuerraFederativa\RatingFederativo;
use App\Domain\GuerraFederativa\TratadoDePaz;
use App\Exceptions\DomainRuleException;
use App\Models\Colony;
use App\Models\Combat;
use App\Models\Federation;
use App\Models\FederationLedger;
use App\Models\FederationWar;
use App\Models\FederationWarProposal;
use App\Models\GameEvent;
use App\Models\NeutralZone;
use App\Models\Unit;
use App\Models\User;
use App\Models\WarSetting;
use Database\Seeders\ResourceTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Guerra federativa — o esqueleto (A2.10, primeira fatia).
 *
 * Cada teste guarda uma das doze decisões do D-193. Os números são parâmetros; o que não pode mudar
 * sem decisão nova são as **regras**.
 */
class GuerraFederativaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ResourceTypeSeeder::class);
    }

    private int $proximo = 0;

    /** @return array{0:Federation,1:Colony} */
    private function federacao(string $cargo = Federation::LIDER, bool $rica = true): array
    {
        $f = Federation::create(['name' => 'Fed'.$this->proximo, 'tag' => 'G'.$this->proximo++]);

        $u = User::create([
            'name' => 'c', 'nickname' => 'g'.$this->proximo,
            'email' => 'g'.$this->proximo++.'@t.test', 'password' => Hash::make('x'),
        ]);

        $c = Colony::create([
            'user_id' => $u->id, 'name' => 'C', 'x' => 0, 'y' => $this->proximo,
            'federation_id' => $f->id, 'federation_role' => $cargo,
        ]);

        if ($rica) {
            $this->abastecer($f);
        }

        return [$f->fresh(), $c];
    }

    private function abastecer(Federation $f): void
    {
        $config = WarSetting::singleton();

        $f->update(['fert_micro' => (int) $config->federativa_custo_fert_micro * 10]);

        DB::table('federation_holdings')->insert([
            'federation_id' => $f->id, 'resource_type' => 'niobio_alienigena',
            'amount' => (int) $config->federativa_custo_niobio * 10,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ─────────────────────────────── declaração (decisões 3, 4 e 5)

    public function test_declarar_abre_uma_guerra_com_prazo(): void
    {
        [$a, $autor] = $this->federacao();
        [$b] = $this->federacao();

        $guerra = app(DeclararGuerra::class)->handle($autor, $b);

        $this->assertSame('ativa', $guerra->status);
        $this->assertSame(
            (int) WarSetting::singleton()->federativa_duracao_horas,
            (int) $guerra->comeca_em->diffInHours($guerra->termina_em),
            'sete dias, a decisão 5',
        );
        $this->assertSame($a->id, (int) $guerra->declarante_id);
    }

    /** ⚠️ Decisão 4: não há recusa. Quem é declarado ESTÁ em guerra — não existe caminho para negar. */
    public function test_o_alvo_nao_tem_como_recusar(): void
    {
        [, $autor] = $this->federacao();
        [$b] = $this->federacao();

        app(DeclararGuerra::class)->handle($autor, $b);

        $this->assertTrue(
            FederationWar::where('alvo_id', $b->id)->where('status', 'ativa')->exists(),
            'a guerra existe do lado de quem foi declarado, sem ele ter aceitado nada',
        );
    }

    /** ⚠️ Decisão 3: o custo sai do FUNDO, não do bolso de quem declara. */
    public function test_o_custo_sai_do_fundo_da_federacao(): void
    {
        [$a, $autor] = $this->federacao();
        [$b] = $this->federacao();

        $fertAntes = (int) $a->fert_micro;
        $niobioAntes = (int) DB::table('federation_holdings')
            ->where('federation_id', $a->id)->value('amount');
        /*
         * ⚠️ `fresh()` também no ANTES: o modelo em memória foi criado sem `fert_micro` e não
         * enxerga o saldo inicial que o banco dá à colônia. Ler a memória em vez do banco daria zero
         * e o teste compararia dois números errados — a mesma armadilha do D-166.
         */
        $bolsoAntes = (int) $autor->fresh()->fert_micro;

        app(DeclararGuerra::class)->handle($autor, $b);

        $this->assertLessThan($fertAntes, (int) $a->fresh()->fert_micro, 'o fundo pagou');
        $this->assertLessThan(
            $niobioAntes,
            (int) DB::table('federation_holdings')->where('federation_id', $a->id)->value('amount'),
            'e o Nióbio também',
        );
        $this->assertSame($bolsoAntes, (int) $autor->fresh()->fert_micro, 'o bolso de quem declarou, não');
    }

    public function test_sem_fundo_nao_declara(): void
    {
        [, $autor] = $this->federacao(rica: false);
        [$b] = $this->federacao();

        $this->expectException(DomainRuleException::class);
        app(DeclararGuerra::class)->handle($autor, $b);
    }

    public function test_so_lider_ou_diplomata_declaram(): void
    {
        [, $autor] = $this->federacao(Federation::INTENDENTE);
        [$b] = $this->federacao();

        $this->expectException(DomainRuleException::class);
        app(DeclararGuerra::class)->handle($autor, $b);
    }

    public function test_nao_ha_duas_guerras_entre_o_mesmo_par(): void
    {
        [, $autor] = $this->federacao();
        [$b, $autorB] = $this->federacao();

        app(DeclararGuerra::class)->handle($autor, $b);

        // Do outro lado: a pergunta "estas duas estão em guerra?" não tem sentido de ida e volta.
        $this->expectException(DomainRuleException::class);
        app(DeclararGuerra::class)->handle($autorB, Federation::find($autor->federation_id));
    }

    // ─────────────────────────────── ⚠️ decisão 8: declarar rompe a aliança

    public function test_declarar_a_uma_aliada_rompe_a_alianca(): void
    {
        [$a, $autorA] = $this->federacao();
        [$b, $autorB] = $this->federacao();

        app(Diplomacia::class)->propor($autorA, $b);
        app(Diplomacia::class)->aceitar($autorB, $a);
        $this->assertTrue(app(Aliancas::class)->saoAliadas($a->id, $b->id));

        app(DeclararGuerra::class)->handle($autorA, $b);

        $this->assertFalse(
            app(Aliancas::class)->saoAliadas($a->id, $b->id),
            'aliada e inimiga não são a mesma coisa ao mesmo tempo',
        );
    }

    /**
     * ⚠️ E a aliança só cai se a guerra REALMENTE acontecer.
     *
     * Se uma conferência recusasse a declaração depois de a aliança ter sido rompida, a federação
     * teria perdido a aliada por uma guerra que não houve.
     */
    public function test_declaracao_recusada_nao_rompe_a_alianca(): void
    {
        [$a, $autorA] = $this->federacao(rica: false);
        [$b, $autorB] = $this->federacao();

        app(Diplomacia::class)->propor($autorA, $b);
        app(Diplomacia::class)->aceitar($autorB, $a);

        try {
            app(DeclararGuerra::class)->handle($autorA, $b);
        } catch (DomainRuleException) {
            // sem fundo: a declaração morre antes de existir
        }

        $this->assertTrue(
            app(Aliancas::class)->saoAliadas($a->id, $b->id),
            'a aliança sobreviveu à declaração que não aconteceu',
        );
    }

    // ─────────────────────────────── prazo e cooldown

    public function test_o_tick_encerra_a_guerra_vencida(): void
    {
        [, $autor] = $this->federacao();
        [$b] = $this->federacao();
        $guerra = app(DeclararGuerra::class)->handle($autor, $b);

        app(EncerrarGuerras::class)->handle(now()->addDays(30));

        $this->assertSame('encerrada', $guerra->fresh()->status);
        $this->assertSame('prazo', $guerra->fresh()->motivo_fim);
    }

    /**
     * ⚠️ O cooldown é do PAR (GDD §10).
     *
     * Sem ele, uma federação forte mantém outra em guerra permanente, declarando de novo assim que o
     * prazo acaba. É assédio, e o remédio é relógio.
     */
    public function test_o_cooldown_impede_declarar_de_novo_ao_mesmo_par(): void
    {
        [, $autor] = $this->federacao();
        [$b] = $this->federacao();

        app(DeclararGuerra::class)->handle($autor, $b);
        app(EncerrarGuerras::class)->handle(now()->addDays(8));

        $this->travel(8)->days();

        $this->expectException(DomainRuleException::class);
        app(DeclararGuerra::class)->handle($autor, $b);
    }

    /** E ele NÃO congela a geopolítica: declarar a um terceiro continua livre. */
    public function test_o_cooldown_nao_impede_declarar_a_outro(): void
    {
        [, $autor] = $this->federacao();
        [$b] = $this->federacao();
        [$c] = $this->federacao();

        app(DeclararGuerra::class)->handle($autor, $b);
        app(EncerrarGuerras::class)->handle(now()->addDays(8));
        $this->travel(8)->days();

        $guerra = app(DeclararGuerra::class)->handle($autor, $c);

        $this->assertSame('ativa', $guerra->status);
    }

    // ─────────────────────────────── ⚠️ o Motor de Eventos manda aqui

    /** A trégua do Governo fecha o portão — primeiro consumidor do modificador do D-194. */
    public function test_a_tregua_impede_a_declaracao(): void
    {
        [, $autor] = $this->federacao();
        [$b] = $this->federacao();

        GameEvent::create([
            'slug' => 'tregua', 'nome' => 'Trégua',
            'comeca_em' => now()->subHour(), 'termina_em' => now()->addDay(),
            'status' => 'ativo', 'modificador' => Modificadores::GUERRA_DECLARACAO,
            'efeito_bps' => -10_000,
        ]);

        $this->expectException(DomainRuleException::class);
        app(DeclararGuerra::class)->handle($autor, $b);
    }

    /** E o custo de mobilização multiplica o preço da declaração. */
    public function test_o_evento_de_custo_encarece_a_declaracao(): void
    {
        $normal = app(DeclararGuerra::class)->custo();

        GameEvent::create([
            'slug' => 'escassez', 'nome' => 'Escassez de Nióbio',
            'comeca_em' => now()->subHour(), 'termina_em' => now()->addDay(),
            'status' => 'ativo', 'modificador' => Modificadores::GUERRA_CUSTO,
            'efeito_bps' => 5_000,
        ]);

        $caro = app(DeclararGuerra::class)->custo();

        $this->assertGreaterThan($normal['fert'], $caro['fert']);
        $this->assertGreaterThan($normal['niobio'], $caro['niobio']);
    }

    // ─────────────────────────────── ⚠️ neutralidade declarada (decisão 12)

    /** A neutra não pode ser alvo. É a proteção que a decisão 12 escolheu — e a única que há. */
    public function test_a_neutra_nao_pode_ser_declarada(): void
    {
        [, $autor] = $this->federacao();
        [$b, $autorB] = $this->federacao();

        app(Neutralidade::class)->declarar($autorB);

        $this->expectException(DomainRuleException::class);
        app(DeclararGuerra::class)->handle($autor, $b);
    }

    /**
     * ⚠️ E a neutra também não DECLARA — a simetria é o custo que paga a proteção.
     *
     * Sem ela, a neutralidade seria um abrigo de onde se ataca: declarar-se neutro e sair batendo
     * seria a jogada certa sempre, e a guerra deixaria de existir.
     */
    public function test_a_neutra_tambem_nao_declara(): void
    {
        [, $autor] = $this->federacao();
        [$b] = $this->federacao();

        app(Neutralidade::class)->declarar($autor);

        $this->expectException(DomainRuleException::class);
        app(DeclararGuerra::class)->handle($autor, $b);
    }

    /**
     * ⚠️ **A carência é o que impede o escudo de ser largado na hora do ataque.**
     *
     * Pedir para sair NÃO tira a proteção na hora: ela vale até a carência acabar. Sem isso, largar o
     * abrigo no instante de declarar seria sempre a melhor jogada — e a neutralidade viraria o estado
     * padrão do mundo.
     */
    public function test_pedir_para_sair_nao_tira_a_protecao_na_hora(): void
    {
        [$a, $autor] = $this->federacao();
        [$b] = $this->federacao();

        app(Neutralidade::class)->declarar($autor);
        app(Neutralidade::class)->encerrar($autor);

        $this->assertTrue(
            app(Neutralidade::class)->vigente($a->fresh()),
            'continua neutra durante a carência',
        );

        $this->expectException(DomainRuleException::class);
        app(DeclararGuerra::class)->handle($autor, $b);
    }

    /** Passada a carência, a proteção acaba e a federação volta a poder declarar. */
    public function test_depois_da_carencia_a_neutralidade_acaba(): void
    {
        [$a, $autor] = $this->federacao();
        [$b] = $this->federacao();

        app(Neutralidade::class)->declarar($autor);
        app(Neutralidade::class)->encerrar($autor);

        $this->travel((int) WarSetting::singleton()->neutralidade_carencia_horas + 1)->hours();

        $this->assertFalse(app(Neutralidade::class)->vigente($a->fresh()));

        $guerra = app(DeclararGuerra::class)->handle($autor, $b);
        $this->assertSame('ativa', $guerra->status);
    }

    /** Não se declara neutralidade no meio de uma guerra: a saída de lá é a capitulação. */
    public function test_nao_se_declara_neutralidade_em_guerra(): void
    {
        [, $autor] = $this->federacao();
        [$b, $autorB] = $this->federacao();

        app(DeclararGuerra::class)->handle($autor, $b);

        $this->expectException(DomainRuleException::class);
        app(Neutralidade::class)->declarar($autorB);
    }

    public function test_so_lider_ou_diplomata_declaram_neutralidade(): void
    {
        [, $autor] = $this->federacao(Federation::INTENDENTE);

        $this->expectException(DomainRuleException::class);
        app(Neutralidade::class)->declarar($autor);
    }

    /** O tick limpa quem cumpriu a carência, para o DADO não mentir. */
    public function test_o_tick_limpa_a_neutralidade_vencida(): void
    {
        [$a, $autor] = $this->federacao();

        app(Neutralidade::class)->declarar($autor);
        app(Neutralidade::class)->encerrar($autor);

        app(Neutralidade::class)->limparVencidas(
            now()->addHours((int) WarSetting::singleton()->neutralidade_carencia_horas + 1),
        );

        $this->assertNull($a->fresh()->neutra_desde, 'o dado deixou de dizer que ela é neutra');
    }

    // ─────────────────────────────── o fundo em Fert$

    /** ⚠️ Sem este caminho, o custo decidido no D-193 seria impagável por construção. */
    public function test_membro_contribui_com_fert_para_o_fundo(): void
    {
        [$f, $c] = $this->federacao(rica: false);
        $c->update(['fert_micro' => 10 * Colony::MICRO_POR_FERT]);

        app(ContribuirParaOFundo::class)->handle($c, 4 * Colony::MICRO_POR_FERT);

        $this->assertSame(4 * Colony::MICRO_POR_FERT, (int) $f->fresh()->fert_micro);
        $this->assertSame(6 * Colony::MICRO_POR_FERT, (int) $c->fresh()->fert_micro);
    }

    public function test_nao_contribui_alem_do_saldo(): void
    {
        [, $c] = $this->federacao(rica: false);
        $c->update(['fert_micro' => 1]);

        $this->expectException(DomainRuleException::class);
        app(ContribuirParaOFundo::class)->handle($c, 999_999_999);
    }
    // ─────────────────────────────── capitulação e tratado (decisões 8 e 9, D-206)

    /** Duas federações com uma guerra ativa entre elas. Devolve [guerra, fedA, colA, fedB, colB]. */
    private function guerraEntreDuas(): array
    {
        [$fa, $ca] = $this->federacao();
        [$fb, $cb] = $this->federacao();

        $guerra = FederationWar::create([
            'declarante_id' => $fa->id, 'alvo_id' => $fb->id,
            'comeca_em' => now()->subDay(), 'termina_em' => now()->addDays(6),
            'status' => 'ativa', 'declarada_por_colony_id' => $ca->id,
        ]);

        return [$guerra, $fa->fresh(), $ca->fresh(), $fb->fresh(), $cb->fresh()];
    }

    private int $proximaCelulaDeZona = 0;

    private function zonaDe(Colony $dono): NeutralZone
    {
        $cel = [[47, 47], [48, 48], [49, 49]][$this->proximaCelulaDeZona++];

        return NeutralZone::create([
            'x' => $cel[0], 'y' => $cel[1], 'district' => 'NE', 'mineral' => 'metal_bruto',
            'level' => 1, 'owner_colony_id' => $dono->id, 'status' => 'ocupada',
            'occupied_at' => now()->subDays(10), 'productive_at' => now()->subDays(9),
            'last_extraction_at' => now(),
        ]);
    }

    public function test_o_vencedor_escolhe_uma_zona_e_a_guerra_acaba(): void
    {
        [$guerra, , $ca, , $cb] = $this->guerraEntreDuas();

        $zona = $this->zonaDe($cb);            // B é quem se rende
        Unit::create([
            'zone_id' => $zona->id, 'type' => 'robo_minerador',
            'level' => 1, 'hp_bps' => Unit::INTEIRA, 'status' => 'na_zona',
        ]);

        app(Capitulacao::class)->propor($cb, $guerra->id);
        app(Capitulacao::class)->aceitar($ca, $guerra->id, 'zona', $zona->id);

        $this->assertSame('capitulada', $guerra->fresh()->status);
        $this->assertSame('capitulacao', $guerra->fresh()->motivo_fim);
        $this->assertSame($ca->id, (int) $zona->fresh()->owner_colony_id);

        /*
         * ⚠️ A guarnição VOLTA PARA CASA — não morre como na conquista. Entrega negociada não é
         * batalha, e apagar os robôs cobraria um segundo preço que ninguém combinou.
         */
        $robo = Unit::where('type', 'robo_minerador')->first();
        $this->assertNotNull($robo, 'o robô da zona cedida não pode ser destruído');
        $this->assertSame($cb->id, (int) $robo->colony_id);
        $this->assertNull($robo->zone_id);
    }

    /** O espólio em Fert$: sai do fundo de quem se rendeu, entra no do vencedor, e os dois extratos registram. */
    public function test_o_vencedor_pode_escolher_fert_do_fundo(): void
    {
        [$guerra, $fa, $ca, $fb, $cb] = $this->guerraEntreDuas();

        $preco = (int) WarSetting::singleton()->capitulacao_fert_micro;
        $fb->update(['fert_micro' => $preco * 3]);
        $fa->update(['fert_micro' => 0]);

        app(Capitulacao::class)->propor($cb, $guerra->id);
        app(Capitulacao::class)->aceitar($ca, $guerra->id, 'fert');

        $this->assertSame($preco, (int) $fa->fresh()->fert_micro);
        $this->assertSame($preco * 2, (int) $fb->fresh()->fert_micro);

        $this->assertSame(-$preco, (int) FederationLedger::where('federation_id', $fb->id)
            ->where('type', 'capitulacao')->value('amount'));
        $this->assertSame($preco, (int) FederationLedger::where('federation_id', $fa->id)
            ->where('type', 'capitulacao')->value('amount'));
    }

    /**
     * ⚠️ Fundo mais pobre que o preço: leva-se o que há, e a guerra acaba do mesmo jeito.
     *
     * Bloquear por pobreza prenderia o derrotado na derrota — que é o que o §9 existe para encurtar.
     */
    public function test_fundo_menor_que_o_preco_nao_impede_a_capitulacao(): void
    {
        [$guerra, $fa, $ca, $fb, $cb] = $this->guerraEntreDuas();

        $fb->update(['fert_micro' => 7]);
        $fa->update(['fert_micro' => 0]);

        app(Capitulacao::class)->propor($cb, $guerra->id);
        app(Capitulacao::class)->aceitar($ca, $guerra->id, 'fert');

        $this->assertSame('capitulada', $guerra->fresh()->status);
        $this->assertSame(7, (int) $fa->fresh()->fert_micro);
        $this->assertSame(0, (int) $fb->fresh()->fert_micro);
        $this->assertSame(7, (int) FederationWarProposal::first()->preco_fert_micro);
    }

    /** Quem propõe não aceita: senão o derrotado escolheria o próprio preço. */
    public function test_quem_se_rende_nao_escolhe_o_proprio_preco(): void
    {
        [$guerra, , , , $cb] = $this->guerraEntreDuas();

        app(Capitulacao::class)->propor($cb, $guerra->id);

        $this->expectException(DomainRuleException::class);
        app(Capitulacao::class)->aceitar($cb, $guerra->id, 'fert');
    }

    /** Não se exige a zona de um terceiro que não está nesta guerra. */
    public function test_nao_se_exige_zona_de_quem_nao_se_rendeu(): void
    {
        [$guerra, , $ca, , $cb] = $this->guerraEntreDuas();
        [, $cc] = $this->federacao();

        $zonaDeTerceiro = $this->zonaDe($cc);

        app(Capitulacao::class)->propor($cb, $guerra->id);

        $this->expectException(DomainRuleException::class);
        app(Capitulacao::class)->aceitar($ca, $guerra->id, 'zona', $zonaDeTerceiro->id);
    }

    /**
     * ⚠️ `termina_em` NÃO se antecipa: o cooldown do par (§10) conta a partir dele.
     *
     * Sem esta afirmação, capitular viraria o jeito barato de zerar a proteção contra assédio e ser
     * declarado de novo no dia seguinte.
     */
    public function test_capitular_nao_encurta_o_cooldown_do_par(): void
    {
        [$guerra, , $ca, $fb, $cb] = $this->guerraEntreDuas();

        $antes = $guerra->termina_em->toIso8601String();
        $fb->update(['fert_micro' => 0]);

        app(Capitulacao::class)->propor($cb, $guerra->id);
        app(Capitulacao::class)->aceitar($ca, $guerra->id, 'fert');

        $this->assertSame($antes, $guerra->fresh()->termina_em->toIso8601String());
    }

    public function test_o_tratado_aceito_acaba_a_guerra_sem_espolio(): void
    {
        [$guerra, $fa, $ca, $fb, $cb] = $this->guerraEntreDuas();

        $fundoA = (int) $fa->fert_micro;
        $fundoB = (int) $fb->fert_micro;

        app(TratadoDePaz::class)->propor($ca, $guerra->id);
        app(TratadoDePaz::class)->aceitar($cb, $guerra->id);

        $this->assertSame('tratado', $guerra->fresh()->status);
        $this->assertSame('tratado', $guerra->fresh()->motivo_fim);

        // Nada mudou de mãos — é o que separa a paz da capitulação.
        $this->assertSame($fundoA, (int) $fa->fresh()->fert_micro);
        $this->assertSame($fundoB, (int) $fb->fresh()->fert_micro);
    }

    public function test_o_tratado_recusado_deixa_a_guerra_correndo(): void
    {
        [$guerra, , $ca, , $cb] = $this->guerraEntreDuas();

        app(TratadoDePaz::class)->propor($ca, $guerra->id);
        app(TratadoDePaz::class)->recusar($cb, $guerra->id);

        $this->assertSame('ativa', $guerra->fresh()->status);
        $this->assertSame('recusada', FederationWarProposal::first()->status);

        // E a mesa fica livre: dá para propor de novo.
        app(TratadoDePaz::class)->propor($ca, $guerra->id);
        $this->assertSame(2, FederationWarProposal::count());
    }

    /** Uma pendente por tipo e por guerra, venha de qual lado vier. */
    public function test_nao_ha_duas_propostas_do_mesmo_tipo_na_mesa(): void
    {
        [$guerra, , $ca, , $cb] = $this->guerraEntreDuas();

        app(TratadoDePaz::class)->propor($ca, $guerra->id);

        $this->expectException(DomainRuleException::class);
        app(TratadoDePaz::class)->propor($cb, $guerra->id);
    }

    /** Quem não é Líder nem Diplomata não negocia o fim de nada. */
    public function test_so_lider_ou_diplomata_negociam_o_fim(): void
    {
        [$guerra, , , , $cb] = $this->guerraEntreDuas();
        $cb->update(['federation_role' => Federation::MEMBRO]);

        $this->expectException(DomainRuleException::class);
        app(Capitulacao::class)->propor($cb->fresh(), $guerra->id);
    }

    /** Guerra que já acabou não se negocia. */
    public function test_guerra_encerrada_nao_aceita_proposta(): void
    {
        [$guerra, , , , $cb] = $this->guerraEntreDuas();
        $guerra->update(['status' => 'encerrada']);

        $this->expectException(DomainRuleException::class);
        app(TratadoDePaz::class)->propor($cb, $guerra->id);
    }
    // ─────────────────────────────── o ranking federativo: Elo (decisão 10, D-207)

    private function rating(int $federationId): int
    {
        return (int) Federation::whereKey($federationId)->value('rating_guerra');
    }

    /**
     * A propriedade que decidiu a escolha da fórmula: **soma zero**.
     *
     * ⚠️ É o que torna a guerra encenada da decisão 11 inútil — duas federações amigas guerreando
     * entre si não produzem nada líquido para o par. Se algum dia esta afirmação ficar vermelha, o
     * motivo de o ranking ser Elo terá desaparecido junto.
     */
    public function test_o_rating_e_soma_zero(): void
    {
        [$guerra, $fa, $ca, $fb, $cb] = $this->guerraEntreDuas();

        $antes = $this->rating($fa->id) + $this->rating($fb->id);
        $fb->update(['fert_micro' => 0]);

        app(Capitulacao::class)->propor($cb, $guerra->id);
        app(Capitulacao::class)->aceitar($ca, $guerra->id, 'fert');

        /*
         * ⚠️ O controle vem PRIMEIRO, e não é decoração: "a soma não mudou" é verdade trivial num
         * mundo onde nada aconteceu. Foi assim que uma versão anterior deste bloco ficou verde com
         * o `rating_guerra` fora do `$fillable` — todos os `update()` descartados em silêncio, e a
         * igualdade continuando a valer sobre dois números que ninguém tocou.
         */
        $this->assertNotSame(
            RatingFederativo::INICIAL,
            $this->rating($fa->id),
            'o rating tem de ter se movido — senão a soma zero é verdade sobre coisa nenhuma',
        );

        $this->assertSame($antes, $this->rating($fa->id) + $this->rating($fb->id));
    }

    /** Entre iguais, o esperado é 0,5: vencer move metade do K, para cima e para baixo. */
    public function test_vencer_um_igual_move_metade_do_k(): void
    {
        [$guerra, $fa, $ca, $fb, $cb] = $this->guerraEntreDuas();
        $fb->update(['fert_micro' => 0]);

        app(Capitulacao::class)->propor($cb, $guerra->id);
        app(Capitulacao::class)->aceitar($ca, $guerra->id, 'fert');

        $k = (int) WarSetting::singleton()->rating_k;

        $this->assertSame(RatingFederativo::INICIAL + intdiv($k, 2), $this->rating($fa->id));
        $this->assertSame(RatingFederativo::INICIAL - intdiv($k, 2), $this->rating($fb->id));
        $this->assertSame(intdiv($k, 2), (int) $guerra->fresh()->rating_delta);
    }

    /**
     * ⚠️ O §14 em uma asserção: **vencer um fraco vale menos que vencer um forte.**
     *
     * É a razão declarada de o ranking existir nesta forma — *"premia enfrentar quem é páreo"*. Sem
     * este teste, a fórmula poderia ser trocada por qualquer outra sem que nada reclamasse.
     */
    public function test_vencer_um_mais_forte_rende_mais_que_vencer_um_mais_fraco(): void
    {
        // Cenário 1: o declarante é MUITO mais fraco e vence.
        [$g1, $f1a, $c1a, $f1b, $c1b] = $this->guerraEntreDuas();
        $f1a->update(['rating_guerra' => 800]);
        $f1b->update(['rating_guerra' => 1200, 'fert_micro' => 0]);

        app(Capitulacao::class)->propor($c1b, $g1->id);
        app(Capitulacao::class)->aceitar($c1a, $g1->id, 'fert');

        $ganhoContraForte = $this->rating($f1a->id) - 800;

        // Cenário 2: o declarante é MUITO mais forte e vence.
        [$g2, $f2a, $c2a, $f2b, $c2b] = $this->guerraEntreDuas();
        $f2a->update(['rating_guerra' => 1200]);
        $f2b->update(['rating_guerra' => 800, 'fert_micro' => 0]);

        app(Capitulacao::class)->propor($c2b, $g2->id);
        app(Capitulacao::class)->aceitar($c2a, $g2->id, 'fert');

        $ganhoContraFraco = $this->rating($f2a->id) - 1200;

        $this->assertGreaterThan(
            $ganhoContraFraco,
            $ganhoContraForte,
            'enfrentar quem é páreo tem de render mais — é o §14 inteiro',
        );
    }

    /** O tratado é empate: ninguém venceu, e a paz não move espólio nenhum. */
    public function test_o_tratado_entre_iguais_nao_move_o_rating(): void
    {
        [$guerra, $fa, $ca, $fb, $cb] = $this->guerraEntreDuas();

        app(TratadoDePaz::class)->propor($ca, $guerra->id);
        app(TratadoDePaz::class)->aceitar($cb, $guerra->id);

        $this->assertSame(RatingFederativo::INICIAL, $this->rating($fa->id));
        $this->assertSame(RatingFederativo::INICIAL, $this->rating($fb->id));
    }

    /**
     * A guerra que acaba pelo PRAZO: o resultado sai do saldo, e o saldo sai dos combates marcados
     * com `war_id`. Sem a marca, sete dias de batalhas terminariam como empate técnico.
     */
    public function test_o_prazo_decide_pelo_saldo_de_zonas_tomadas(): void
    {
        [$guerra, $fa, $ca, $fb, $cb] = $this->guerraEntreDuas();

        // O declarante tomou uma zona nesta guerra; o alvo, nenhuma.
        Combat::create([
            'zone_id' => $this->zonaDe($cb)->id,
            'attacker_colony_id' => $ca->id,
            'defender_colony_id' => $cb->id,
            'war_id' => $guerra->id,
            'tipo' => 'invasao',
            'status' => 'vitoria_atacante',
            'rodada' => 3,
            'chega_at' => now()->subHour(),
            'resultado' => ['saque' => 100],
        ]);

        $this->travelTo(now()->addDays(8));
        app(EncerrarGuerras::class)->handle(now());

        $this->assertSame('encerrada', $guerra->fresh()->status);
        $this->assertGreaterThan(RatingFederativo::INICIAL, $this->rating($fa->id));
        $this->assertLessThan(RatingFederativo::INICIAL, $this->rating($fb->id));
    }

    /** Sete dias sem que nenhum dos dois tirasse nada do outro É empate — e não vitória de quem declarou. */
    public function test_prazo_sem_batalha_nenhuma_e_empate(): void
    {
        [$guerra, $fa, , $fb] = $this->guerraEntreDuas();

        $this->travelTo(now()->addDays(8));
        app(EncerrarGuerras::class)->handle(now());

        $this->assertSame(RatingFederativo::INICIAL, $this->rating($fa->id));
        $this->assertSame(RatingFederativo::INICIAL, $this->rating($fb->id));
    }
}
