<?php

namespace Tests\Feature;

use App\Console\Commands\TickColonies;
use App\Domain\Colony\CreateColony;
use App\Domain\Logistics\DespacharVeiculo;
use App\Domain\Ministry\AbrirDenuncia;
use App\Domain\Ministry\Apelacao;
use App\Domain\Ministry\DecidirCaso;
use App\Domain\Ministry\ExpirarPrazos;
use App\Domain\Ministry\PagarConciliadores;
use App\Domain\Ministry\PunicaoSpecs;
use App\Domain\Trade\ProporAcordo;
use App\Exceptions\DomainRuleException;
use App\Models\Colony;
use App\Models\Ledger;
use App\Models\Punishment;
use App\Models\Report;
use App\Models\TradeAgreement;
use App\Models\User;
use Database\Seeders\BuildingSpecSeeder;
use Database\Seeders\ComponentRecipeSeeder;
use Database\Seeders\ResourceTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Ministério das Reputações — GDD §9.1–9.4 e §26.6–26.8. Arbitragens em D-44, D-47 a D-50.
 *
 * O que se guarda aqui, acima de tudo: **a pena não é do conciliador, é da tabela** (§26.8). Ele
 * decide se a violação ocorreu. Se um dia alguém lhe der a escolha da punição, estes testes caem.
 */
class MinisterioDasReputacoesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ResourceTypeSeeder::class);
        $this->seed(ComponentRecipeSeeder::class);
        $this->seed(BuildingSpecSeeder::class);
    }

    /** Um slot por colônia: `colonies.x/y` é único, e o mapa não empilha vizinhos (§02.1). */
    private int $proximoSlot = 0;

    private function colonia(string $nick, ?int $x = null, ?int $y = null): Colony
    {
        $user = User::factory()->create(['email' => "{$nick}@t.test", 'nickname' => $nick]);
        // Um slot de periferia por colônia (D-51: o colono escolhe a célula). Coords ≥ 10 nunca
        // caem no disco de founders nem no anel, então são sempre fundáveis.
        $colony = app(CreateColony::class)->handle(
            $user, "Colônia {$nick}", $x ?? 10 + 2 * $this->proximoSlot++, $y ?? 10,
        );

        return $colony->fresh();
    }

    /** Um acordo já quebrado entre as duas: é a evidência mínima que o §26.8 exige. */
    private function acordoQuebrado(Colony $a, Colony $b): TradeAgreement
    {
        $acordo = app(ProporAcordo::class)->handle($a, $b, ['metal_bruto' => 10], ['agua' => 10], now()->addDays(2));
        $acordo->forceFill(['status' => 'quebrado'])->save();

        return $acordo->fresh();
    }

    private function denunciar(Colony $de, Colony $contra, string $violacao, ?TradeAgreement $acordo = null): Report
    {
        return app(AbrirDenuncia::class)->handle(
            $de, $contra, $violacao, 'Ele prometeu e não entregou, e depois riu.',
            'acordo_expirado', ($acordo ?? $this->acordoQuebrado($de, $contra))->id,
        );
    }

    private function nomearConciliador(Colony $colonia): User
    {
        $u = $colonia->user;
        $u->forceFill(['conciliador_desde' => now()])->save();

        return $u->fresh();
    }

    #[Test]
    public function todo_colono_nasce_no_meio_da_escala_nos_quatro_indices(): void
    {
        $u = $this->colonia('alfa')->user;

        // §26.2: quatro índices de 0 a 1000. D-48: nenhum deles é uma "reputação geral", e nenhum
        // nasce em zero — zero é o pior colono possível, não um colono novo.
        $this->assertSame(500, $u->confianca_comercial);
        $this->assertSame(500, $u->conduta_social);
        $this->assertSame(500, $u->status_civico);
        $this->assertSame(500, $u->honra_militar_diplomatica);
    }

    #[Test]
    public function denuncia_sem_evidencia_e_rejeitada_na_triagem(): void
    {
        [$a, $b] = [$this->colonia('alfa'), $this->colonia('beta')];

        // §26.8: "Denúncia só é aceita para análise se anexar pelo menos um Acordo de Troca
        // expirado, print de chat, ou log de transação."
        $this->expectException(DomainRuleException::class);
        $this->expectExceptionMessage('Anexe o Acordo de Troca');

        app(AbrirDenuncia::class)->handle($a, $b, 'calote_reincidente', 'Ele me caloteou.', 'acordo_expirado', null);
    }

    #[Test]
    public function acordo_ainda_em_vigor_nao_serve_de_evidencia(): void
    {
        [$a, $b] = [$this->colonia('alfa'), $this->colonia('beta')];
        $vivo = app(ProporAcordo::class)->handle($a, $b, ['metal_bruto' => 10], ['agua' => 10], now()->addDays(2));

        $this->expectException(DomainRuleException::class);
        $this->expectExceptionMessage('exige um Acordo de Troca expirado');

        app(AbrirDenuncia::class)->handle($a, $b, 'calote_reincidente', 'Ele vai me caloteirar.', 'acordo_expirado', $vivo->id);
    }

    #[Test]
    public function acordo_de_terceiros_nao_serve_de_evidencia(): void
    {
        [$a, $b, $c] = [$this->colonia('alfa'), $this->colonia('beta'), $this->colonia('gama')];
        $alheio = $this->acordoQuebrado($b, $c);

        $this->expectException(DomainRuleException::class);
        $this->expectExceptionMessage('não é entre você e o denunciado');

        app(AbrirDenuncia::class)->handle($a, $b, 'calote_reincidente', 'Olha o que ele fez com o gama.', 'acordo_expirado', $alheio->id);
    }

    #[Test]
    public function print_de_chat_e_recusado_enquanto_nao_houver_chat(): void
    {
        [$a, $b] = [$this->colonia('alfa'), $this->colonia('beta')];

        $this->expectException(DomainRuleException::class);
        $this->expectExceptionMessage('Não há chat');

        app(AbrirDenuncia::class)->handle($a, $b, 'abuso_em_chat', 'Ele me xingou.', 'print_de_chat', null);
    }

    #[Test]
    public function sem_conciliador_a_denuncia_sobe_a_equipe(): void
    {
        [$a, $b] = [$this->colonia('alfa'), $this->colonia('beta')];

        // §9.3: "Sem conciliadores disponíveis: equipe do jogo assume automaticamente."
        $this->assertSame('na_equipe', $this->denunciar($a, $b, 'calote_reincidente')->status);
    }

    #[Test]
    public function caso_grave_vai_direto_a_equipe_mesmo_havendo_conciliador(): void
    {
        [$a, $b, $c] = [$this->colonia('alfa'), $this->colonia('beta'), $this->colonia('gama')];
        $this->nomearConciliador($c);

        // §9.2: "Caso grave vai direto para a equipe." Grave = punição tabelada de −250 (D-50).
        $denuncia = $this->denunciar($a, $b, 'fraude_de_avaliacao');

        $this->assertTrue($denuncia->grave);
        $this->assertSame('na_equipe', $denuncia->status);
        $this->assertNull($denuncia->conciliator_user_id);
    }

    #[Test]
    public function caso_simples_e_atribuido_a_um_conciliador_com_48_horas_para_decidir(): void
    {
        [$a, $b, $c] = [$this->colonia('alfa'), $this->colonia('beta'), $this->colonia('gama')];
        $conciliador = $this->nomearConciliador($c);

        $denuncia = $this->denunciar($a, $b, 'calote_reincidente');

        $this->assertSame('atribuido', $denuncia->status);
        $this->assertSame($conciliador->id, $denuncia->conciliator_user_id);
        // §26.8: "Conciliador tem 48 horas para decidir um caso atribuído."
        $this->assertSame(PunicaoSpecs::PRAZO_ANALISE_HORAS, (int) round($denuncia->assigned_at->diffInHours($denuncia->deadline_at)));
    }

    #[Test]
    public function o_conciliador_com_transacao_recente_com_uma_das_partes_esta_impedido(): void
    {
        [$a, $b, $c] = [$this->colonia('alfa'), $this->colonia('beta'), $this->colonia('gama')];
        $this->nomearConciliador($c);

        // §26.8, impedimento: "jogadores com quem teve transação comercial nos últimos 30 dias".
        app(ProporAcordo::class)->handle($c, $a, ['metal_bruto' => 5], ['agua' => 5], now()->addDays(2));

        $denuncia = $this->denunciar($a, $b, 'calote_reincidente');

        $this->assertSame('na_equipe', $denuncia->status, 'o único conciliador estava impedido; o caso sobe à equipe');
    }

    #[Test]
    public function transacao_com_mais_de_30_dias_nao_impede(): void
    {
        [$a, $b, $c] = [$this->colonia('alfa'), $this->colonia('beta'), $this->colonia('gama')];
        $conciliador = $this->nomearConciliador($c);

        $antigo = app(ProporAcordo::class)->handle($c, $a, ['metal_bruto' => 5], ['agua' => 5], now()->addDays(2));
        $antigo->forceFill(['created_at' => now()->subDays(PunicaoSpecs::IMPEDIMENTO_DIAS + 1)])->save();

        $this->assertSame($conciliador->id, $this->denunciar($a, $b, 'calote_reincidente')->conciliator_user_id);
    }

    #[Test]
    public function o_conciliador_na_mesma_federacao_de_uma_das_partes_esta_impedido(): void
    {
        [$a, $b, $c] = [$this->colonia('alfa'), $this->colonia('beta'), $this->colonia('gama')];
        $this->nomearConciliador($c);

        // §26.8, a outra metade do impedimento (D-115): "membros da própria federação" — sem
        // transação nenhuma entre eles, só o vínculo de federação já basta.
        $fed = \App\Models\Federation::create(['name' => 'Aliança']);
        $c->update(['federation_id' => $fed->id, 'federation_role' => \App\Models\Federation::LIDER]);
        $a->update(['federation_id' => $fed->id, 'federation_role' => \App\Models\Federation::MEMBRO]);

        $denuncia = $this->denunciar($a, $b, 'calote_reincidente');

        $this->assertSame('na_equipe', $denuncia->status, 'o único conciliador é da mesma federação de uma das partes');
    }

    #[Test]
    public function federacao_diferente_ou_nenhuma_nao_impede(): void
    {
        [$a, $b, $c] = [$this->colonia('alfa'), $this->colonia('beta'), $this->colonia('gama')];
        $conciliador = $this->nomearConciliador($c);

        $fedA = \App\Models\Federation::create(['name' => 'A']);
        $fedC = \App\Models\Federation::create(['name' => 'C']);
        $a->update(['federation_id' => $fedA->id, 'federation_role' => \App\Models\Federation::LIDER]);
        $c->update(['federation_id' => $fedC->id, 'federation_role' => \App\Models\Federation::LIDER]);

        $this->assertSame($conciliador->id, $this->denunciar($a, $b, 'calote_reincidente')->conciliator_user_id);
    }

    #[Test]
    public function o_conciliador_nao_julga_o_proprio_caso(): void
    {
        [$a, $b] = [$this->colonia('alfa'), $this->colonia('beta')];
        $this->nomearConciliador($a);

        $this->assertSame('na_equipe', $this->denunciar($a, $b, 'calote_reincidente')->status);
    }

    #[Test]
    public function a_pena_sai_da_tabela_fixa_e_nao_do_conciliador(): void
    {
        [$a, $b, $c] = [$this->colonia('alfa'), $this->colonia('beta'), $this->colonia('gama')];
        $conciliador = $this->nomearConciliador($c);

        $denuncia = $this->denunciar($a, $b, 'calote_reincidente');

        // O conciliador só diz "procedente". Tudo o mais vem do §26.8, pela tabela do D-49.
        app(DecidirCaso::class)->porConciliador($conciliador, $denuncia, procedente: true);

        $condenado = $b->user->fresh();
        $this->assertSame(400, $condenado->confianca_comercial, '500 − 100 (grave)');

        // As duas punições do tipo, de uma vez: restrição comercial de 7 dias + redução.
        $kinds = Punishment::where('user_id', $condenado->id)->pluck('kind')->sort()->values()->all();
        $this->assertSame([PunicaoSpecs::REDUCAO, PunicaoSpecs::RESTRICAO_COMERCIAL], $kinds);
    }

    #[Test]
    public function so_a_reducao_carrega_pontos(): void
    {
        [$a, $b, $c] = [$this->colonia('alfa'), $this->colonia('beta'), $this->colonia('gama')];
        $conciliador = $this->nomearConciliador($c);

        app(DecidirCaso::class)->porConciliador($conciliador, $this->denunciar($a, $b, 'calote_reincidente'), true);

        // §9.4: a restrição morde por prazo, não por dedução. Somar pontos nela puniria duas vezes.
        $restricao = Punishment::where('kind', PunicaoSpecs::RESTRICAO_COMERCIAL)->firstOrFail();
        $this->assertSame(0, $restricao->points);
        $this->assertNull($restricao->index_name);
        $this->assertNotNull($restricao->expires_at);
    }

    #[Test]
    public function a_reducao_move_um_indice_so(): void
    {
        [$a, $b, $c] = [$this->colonia('alfa'), $this->colonia('beta'), $this->colonia('gama')];
        $conciliador = $this->nomearConciliador($c);

        // Sonegação atinge o Status Cívico (§26.2), e o §26.9 proíbe respingar nos outros três.
        $denuncia = $this->denunciar($a, $b, 'sonegacao');
        app(DecidirCaso::class)->pelaEquipe($denuncia, true); // é grave: só a equipe julga

        $condenado = $b->user->fresh();
        $this->assertSame(250, $condenado->status_civico, '500 − 250 (gravíssima)');
        $this->assertSame(500, $condenado->confianca_comercial);
        $this->assertSame(500, $condenado->conduta_social);
        $this->assertSame(500, $condenado->honra_militar_diplomatica);
    }

    #[Test]
    public function a_restricao_comercial_impede_enviar_recursos(): void
    {
        [$a, $b, $c] = [$this->colonia('alfa'), $this->colonia('beta'), $this->colonia('gama')];
        $conciliador = $this->nomearConciliador($c);

        $b->resources()->where('resource_type', 'metal_bruto')->update(['amount' => 500]);
        $b->resources()->where('resource_type', 'energia')->update(['amount' => 500]);

        app(DecidirCaso::class)->porConciliador($conciliador, $this->denunciar($a, $b, 'calote_reincidente'), true);

        // §9.4: "Jogador não pode enviar recursos por X dias." É a única punição de prazo que morde.
        $this->expectException(DomainRuleException::class);
        $this->expectExceptionMessage('proibiu você de enviar recursos');

        app(DespacharVeiculo::class)->handle(
            $b->fresh(), $b->vehicles()->first(), 'colonia', $a->id, ['metal_bruto' => 10],
        );
    }

    #[Test]
    public function a_restricao_comercial_caduca_em_sete_dias(): void
    {
        [$a, $b, $c] = [$this->colonia('alfa'), $this->colonia('beta'), $this->colonia('gama')];
        $conciliador = $this->nomearConciliador($c);

        $b->resources()->where('resource_type', 'metal_bruto')->update(['amount' => 500]);
        $b->resources()->where('resource_type', 'energia')->update(['amount' => 500]);

        app(DecidirCaso::class)->porConciliador($conciliador, $this->denunciar($a, $b, 'calote_reincidente'), true);

        Carbon::setTestNow(now()->addDays(PunicaoSpecs::RESTRICAO_DIAS)->addMinute());

        $veiculo = app(DespacharVeiculo::class)->handle(
            $b->fresh(), $b->vehicles()->first(), 'colonia', $a->id, ['metal_bruto' => 10],
        );

        $this->assertSame('em_rota', $veiculo->status);
        Carbon::setTestNow();
    }

    #[Test]
    public function decidir_depois_das_48_horas_e_recusado(): void
    {
        [$a, $b, $c] = [$this->colonia('alfa'), $this->colonia('beta'), $this->colonia('gama')];
        $conciliador = $this->nomearConciliador($c);
        $denuncia = $this->denunciar($a, $b, 'calote_reincidente');

        Carbon::setTestNow(now()->addHours(PunicaoSpecs::PRAZO_ANALISE_HORAS + 1));

        try {
            $this->expectException(DomainRuleException::class);
            $this->expectExceptionMessage('48 horas para decidir venceram');
            app(DecidirCaso::class)->porConciliador($conciliador, $denuncia, true);
        } finally {
            Carbon::setTestNow();
        }
    }

    #[Test]
    public function o_prazo_vencido_reatribui_a_outro_conciliador_e_nao_conta_reversao(): void
    {
        [$a, $b, $c, $d] = [$this->colonia('alfa'), $this->colonia('beta'), $this->colonia('gama'), $this->colonia('delta')];
        $lento = $this->nomearConciliador($c);
        $outro = $this->nomearConciliador($d);

        $denuncia = $this->denunciar($a, $b, 'calote_reincidente');
        $this->assertSame($lento->id, $denuncia->conciliator_user_id);

        Carbon::setTestNow(now()->addHours(PunicaoSpecs::PRAZO_ANALISE_HORAS + 1));
        app(ExpirarPrazos::class)->handle();
        Carbon::setTestNow();

        $denuncia->refresh();
        $this->assertSame('atribuido', $denuncia->status);
        $this->assertSame($outro->id, $denuncia->conciliator_user_id, 'nunca ao mesmo que já não respondeu');
        // §26.7 conta reversão de decisão. Aqui não houve decisão nenhuma.
        $this->assertSame(0, $lento->fresh()->reversoes);
    }

    #[Test]
    public function sem_outro_conciliador_o_prazo_vencido_sobe_o_caso_a_equipe(): void
    {
        [$a, $b, $c] = [$this->colonia('alfa'), $this->colonia('beta'), $this->colonia('gama')];
        $this->nomearConciliador($c);
        $denuncia = $this->denunciar($a, $b, 'calote_reincidente');

        Carbon::setTestNow(now()->addHours(PunicaoSpecs::PRAZO_ANALISE_HORAS + 1));
        app(ExpirarPrazos::class)->handle();
        Carbon::setTestNow();

        $this->assertSame('na_equipe', $denuncia->fresh()->status);
    }

    #[Test]
    public function a_janela_de_apelacao_fecha_e_paga_o_bonus_do_conciliador(): void
    {
        [$a, $b, $c] = [$this->colonia('alfa'), $this->colonia('beta'), $this->colonia('gama')];
        $conciliador = $this->nomearConciliador($c);

        app(DecidirCaso::class)->porConciliador($conciliador, $this->denunciar($a, $b, 'calote_reincidente'), true);

        $antes = $c->fresh()->fert_micro;

        Carbon::setTestNow(now()->addHours(PunicaoSpecs::JANELA_APELACAO_HORAS + 1));
        app(ExpirarPrazos::class)->handle();
        Carbon::setTestNow();

        // §26.7: "+3 Fert$ apenas se a decisão NÃO for revertida em apelação".
        $this->assertSame($antes + PunicaoSpecs::BONUS_MICRO, $c->fresh()->fert_micro);
        $this->assertSame('encerrado', Report::firstOrFail()->status);
        $this->assertDatabaseHas('ledger', ['type' => 'bonus_conciliador', 'colony_id' => $c->id]);
    }

    #[Test]
    public function o_bonus_nao_se_paga_duas_vezes_se_o_tick_repetir(): void
    {
        [$a, $b, $c] = [$this->colonia('alfa'), $this->colonia('beta'), $this->colonia('gama')];
        $conciliador = $this->nomearConciliador($c);
        app(DecidirCaso::class)->porConciliador($conciliador, $this->denunciar($a, $b, 'calote_reincidente'), true);

        $antes = $c->fresh()->fert_micro;

        Carbon::setTestNow(now()->addHours(PunicaoSpecs::JANELA_APELACAO_HORAS + 1));
        app(ExpirarPrazos::class)->handle();
        app(ExpirarPrazos::class)->handle();
        Carbon::setTestNow();

        $this->assertSame($antes + PunicaoSpecs::BONUS_MICRO, $c->fresh()->fert_micro);
        $this->assertSame(1, Ledger::where('type', 'bonus_conciliador')->count());
    }

    #[Test]
    public function apelacao_revertida_estorna_a_punicao_e_nao_paga_bonus(): void
    {
        [$a, $b, $c] = [$this->colonia('alfa'), $this->colonia('beta'), $this->colonia('gama')];
        $conciliador = $this->nomearConciliador($c);

        $denuncia = $this->denunciar($a, $b, 'calote_reincidente');
        app(DecidirCaso::class)->porConciliador($conciliador, $denuncia, true);
        $this->assertSame(400, $b->user->fresh()->confianca_comercial);

        app(Apelacao::class)->apelar($b, $denuncia->fresh());
        app(Apelacao::class)->reverter($denuncia->fresh());

        // Os pontos voltam ao índice de onde saíram, e a punição fica registrada como estornada.
        $this->assertSame(500, $b->user->fresh()->confianca_comercial);
        $this->assertNotNull(Punishment::where('kind', PunicaoSpecs::REDUCAO)->firstOrFail()->revoked_at);
        $this->assertFalse(Punishment::restricaoComercialAtiva($b->user->id));
        $this->assertSame(1, $conciliador->fresh()->reversoes);

        Carbon::setTestNow(now()->addHours(PunicaoSpecs::JANELA_APELACAO_HORAS + 1));
        app(ExpirarPrazos::class)->handle();
        Carbon::setTestNow();

        $this->assertSame(0, Ledger::where('type', 'bonus_conciliador')->count());
    }

    #[Test]
    public function so_as_partes_apelam_e_so_dentro_da_janela(): void
    {
        [$a, $b, $c, $d] = [$this->colonia('alfa'), $this->colonia('beta'), $this->colonia('gama'), $this->colonia('delta')];
        $conciliador = $this->nomearConciliador($c);

        $denuncia = $this->denunciar($a, $b, 'calote_reincidente');
        app(DecidirCaso::class)->porConciliador($conciliador, $denuncia, true);

        try {
            app(Apelacao::class)->apelar($d, $denuncia->fresh());
            $this->fail('um terceiro não tem o que contestar');
        } catch (DomainRuleException $e) {
            $this->assertSame('caso_de_outros', $e->codigo);
        }

        Carbon::setTestNow(now()->addHours(PunicaoSpecs::JANELA_APELACAO_HORAS + 1));

        try {
            app(Apelacao::class)->apelar($b, $denuncia->fresh());
            $this->fail('a janela de 48 h fechou');
        } catch (DomainRuleException $e) {
            $this->assertSame('janela_de_apelacao_fechada', $e->codigo);
        } finally {
            Carbon::setTestNow();
        }
    }

    #[Test]
    public function cinco_reversoes_suspendem_o_conciliador(): void
    {
        [$a, $b, $c] = [$this->colonia('alfa'), $this->colonia('beta'), $this->colonia('gama')];
        $conciliador = $this->nomearConciliador($c);

        for ($i = 0; $i < PunicaoSpecs::LIMITE_REVERSOES; $i++) {
            $denuncia = $this->denunciar($a, $b, 'calote_reincidente');
            app(DecidirCaso::class)->porConciliador($conciliador->fresh(), $denuncia, true);
            app(Apelacao::class)->apelar($b, $denuncia->fresh());
            app(Apelacao::class)->reverter($denuncia->fresh());
        }

        // §26.7: "Acima de um limite configurável de reversões, o conciliador é suspenso do cargo."
        // O número é 5 (D-44): o GDD não o publica.
        $this->assertNotNull($conciliador->fresh()->conciliador_suspenso_em);
        $this->assertFalse($conciliador->fresh()->conciliadorAtivo());

        // Suspenso, ele deixa de receber casos: o próximo sobe à equipe (§9.3).
        $this->assertSame('na_equipe', $this->denunciar($a, $b, 'calote_reincidente')->status);
    }

    #[Test]
    public function o_conciliador_recebe_50_fert_por_dia_independentemente_do_volume(): void
    {
        $c = $this->colonia('gama');
        $this->nomearConciliador($c);
        $antes = $c->fresh()->fert_micro;

        // §26.7: "salário fixo diário, independente do volume de casos" — zero casos, salário cheio.
        $this->assertSame(1, app(PagarConciliadores::class)->handle());
        $this->assertSame($antes + PunicaoSpecs::SALARIO_DIARIO_MICRO, $c->fresh()->fert_micro);

        // Duas vezes no mesmo dia, não. O cron roda a cada minuto.
        $this->assertSame(0, app(PagarConciliadores::class)->handle());
        $this->assertSame($antes + PunicaoSpecs::SALARIO_DIARIO_MICRO, $c->fresh()->fert_micro);

        Carbon::setTestNow(now()->addDay()->addMinute());
        $this->assertSame(1, app(PagarConciliadores::class)->handle());
        Carbon::setTestNow();

        $this->assertSame($antes + 2 * PunicaoSpecs::SALARIO_DIARIO_MICRO, $c->fresh()->fert_micro);
        $this->assertSame(2, Ledger::where('type', 'salario_conciliador')->count());
    }

    #[Test]
    public function conciliador_suspenso_nao_recebe_salario(): void
    {
        $c = $this->colonia('gama');
        $u = $this->nomearConciliador($c);
        $u->forceFill(['conciliador_suspenso_em' => now()])->save();

        // O §26.7 suspende "do cargo", e salário é do cargo.
        $this->assertSame(0, app(PagarConciliadores::class)->handle());
    }

    #[Test]
    public function persona_non_grata_e_o_mesmo_limiar_que_fecha_a_doca(): void
    {
        // §9.4 fala em "reputação negativa", e a escala do §26.2 não tem negativo. D-49 resolve.
        $this->assertSame(\App\Domain\Trade\AcordoSpecs::LIMIAR_MERCADO, PunicaoSpecs::PERSONA_NON_GRATA);
    }

    #[Test]
    public function o_tick_reatribui_encerra_e_paga_num_so_comando(): void
    {
        [$a, $b, $c] = [$this->colonia('alfa'), $this->colonia('beta'), $this->colonia('gama')];
        $conciliador = $this->nomearConciliador($c);
        app(DecidirCaso::class)->porConciliador($conciliador, $this->denunciar($a, $b, 'calote_reincidente'), true);

        Carbon::setTestNow(now()->addHours(PunicaoSpecs::JANELA_APELACAO_HORAS + 1));
        $this->artisan(TickColonies::class)->assertSuccessful();
        Carbon::setTestNow();

        $this->assertSame('encerrado', Report::firstOrFail()->status);
        $this->assertSame(1, Ledger::where('type', 'bonus_conciliador')->count());
        $this->assertSame(1, Ledger::where('type', 'salario_conciliador')->count());
    }
}
