<?php

namespace Tests\Feature;

use App\Domain\Eventos\Modificadores;
use App\Domain\Federacao\Aliancas;
use App\Domain\Federacao\ContribuirParaOFundo;
use App\Domain\Federacao\Diplomacia;
use App\Domain\GuerraFederativa\DeclararGuerra;
use App\Domain\GuerraFederativa\EncerrarGuerras;
use App\Exceptions\DomainRuleException;
use App\Models\Colony;
use App\Models\Federation;
use App\Models\FederationWar;
use App\Models\GameEvent;
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
}
