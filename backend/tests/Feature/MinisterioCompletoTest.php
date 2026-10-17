<?php

namespace Tests\Feature;

use App\Domain\Chat\EnviarMensagem;
use App\Domain\Colony\CreateColony;
use App\Domain\Ministry\AbrirDenuncia;
use App\Domain\Ministry\DecidirCaso;
use App\Domain\Ministry\PunicaoSpecs;
use App\Domain\Trade\AvaliacaoSpecs;
use App\Domain\Trade\AvaliarComercio;
use App\Exceptions\DomainRuleException;
use App\Models\ChatMessage;
use App\Models\Colony;
use App\Models\Combat;
use App\Models\Federation;
use App\Models\FederationWar;
use App\Models\NeutralZone;
use App\Models\Report;
use App\Models\TradeAgreement;
use App\Models\User;
use Database\Seeders\BuildingSpecSeeder;
use Database\Seeders\ComponentRecipeSeeder;
use Database\Seeders\ResourceTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * O Ministério das Reputações alcança os sistemas que nasceram depois dele (D-288): chat, alianças,
 * tratados e a avaliação de comércio. Até aqui, quatro violações eram "inertes" — e o jogador não
 * conseguia nem denunciá-las.
 */
class MinisterioCompletoTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    private int $zonas = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ResourceTypeSeeder::class);
        $this->seed(ComponentRecipeSeeder::class);
        $this->seed(BuildingSpecSeeder::class);
    }

    private function colonia(?Federation $f = null): Colony
    {
        $this->n++;
        $c = app(CreateColony::class)->handle(
            User::factory()->create(['confianca_comercial' => 500, 'conduta_social' => 500, 'honra_militar_diplomatica' => 500]),
            "C{$this->n}", 10 + $this->n * 7, 30,
        );

        if ($f) {
            $c->forceFill(['federation_id' => $f->id])->save();
        }

        return $c->fresh();
    }

    private function denunciar(Colony $de, Colony $contra, string $violacao, string $evidencia, ?int $ref = null, ?int $acordo = null): Report
    {
        return app(AbrirDenuncia::class)->handle($de, $contra, $violacao, 'Relato do caso, com detalhes.', $evidencia, $acordo, $ref);
    }

    private function codigo(callable $fn): string
    {
        try {
            $fn();
        } catch (DomainRuleException $e) {
            return $e->codigo;
        }

        $this->fail('Deveria ter recusado.');
    }

    private function combate(Colony $atacante, Colony $defensor, ?string $quando = null): Combat
    {
        $zona = NeutralZone::create([
            'x' => 60 + ++$this->zonas, 'y' => 60, 'district' => 'nordeste', 'mineral' => 'metal_bruto', 'level' => 1,
            'status' => 'ocupada', 'owner_colony_id' => $defensor->id, 'deposit_amount' => 1000, 'refined_amount' => 0,
        ]);

        $combate = Combat::create([
            'zone_id' => $zona->id, 'attacker_colony_id' => $atacante->id, 'defender_colony_id' => $defensor->id,
            'tipo' => 'invasao', 'status' => 'marchando', 'rodada' => 0, 'chega_at' => $quando ?? now(),
        ]);

        if ($quando) {
            $combate->forceFill(['created_at' => $quando])->save();
        }

        return $combate->fresh();
    }

    public function test_nenhuma_violacao_do_catalogo_e_inerte(): void
    {
        foreach (PunicaoSpecs::VIOLACOES as $chave => $v) {
            $this->assertFalse($v['inerte'], "{$chave} ainda está inerte");
        }
    }

    public function test_abuso_em_chat_se_prova_com_a_mensagem_e_o_silencio_morde(): void
    {
        [$vitima, $ofensor] = [$this->colonia(), $this->colonia()];
        $msg = ChatMessage::create(['user_id' => $ofensor->user_id, 'channel' => 'global', 'body' => 'ofensa grave', 'created_at' => now()]);

        $r = $this->denunciar($vitima, $ofensor, 'abuso_em_chat', 'print_de_chat', $msg->id);
        $this->assertSame('ofensa grave', $r->fresh()->chat_snapshot['body'], 'a prova sobrevive à purga do chat');

        app(DecidirCaso::class)->pelaEquipe($r->fresh(), true);

        $this->assertSame(500 + PunicaoSpecs::violacao('abuso_em_chat')['pontos'], $ofensor->user->fresh()->conduta_social);
        $this->expectException(DomainRuleException::class);
        app(EnviarMensagem::class)->handle($ofensor->user->fresh(), 'global', 'de novo');
    }

    public function test_a_mensagem_tem_de_ser_do_denunciado_e_visivel_ao_denunciante(): void
    {
        [$a, $b, $c] = [$this->colonia(), $this->colonia(), $this->colonia()];
        $deOutro = ChatMessage::create(['user_id' => $c->user_id, 'channel' => 'global', 'body' => 'x', 'created_at' => now()]);
        $privadaAlheia = ChatMessage::create([
            'user_id' => $b->user_id, 'channel' => 'privada', 'recipient_user_id' => $c->user_id, 'body' => 'y', 'created_at' => now(),
        ]);

        $this->assertSame('evidencia_de_outros', $this->codigo(fn () => $this->denunciar($a, $b, 'abuso_em_chat', 'print_de_chat', $deOutro->id)));
        $this->assertSame('evidencia_de_outros', $this->codigo(fn () => $this->denunciar($a, $b, 'abuso_em_chat', 'print_de_chat', $privadaAlheia->id)));
    }

    public function test_a_evidencia_tem_de_servir_para_a_violacao(): void
    {
        [$a, $b] = [$this->colonia(), $this->colonia()];
        $msg = ChatMessage::create(['user_id' => $b->user_id, 'channel' => 'global', 'body' => 'x', 'created_at' => now()]);

        $this->assertSame('evidencia_incompativel', $this->codigo(fn () => $this->denunciar($a, $b, 'ataque_a_aliado', 'print_de_chat', $msg->id)));
    }

    public function test_avaliacao_injusta_procedente_anula_a_nota_e_devolve_a_confianca(): void
    {
        [$a, $b] = [$this->colonia(), $this->colonia()];
        $acordo = TradeAgreement::create([
            'colony_a_id' => $a->id, 'colony_b_id' => $b->id, 'proposer_colony_id' => $a->id, 'terms_json' => [],
            'status' => 'executado', 'deadline_at' => now(), 'value_micro' => 10 * Colony::MICRO_POR_FERT,
            'reputation_applied' => true, 'executed_at' => now(), 'delivered_json' => [],
        ]);
        $av = app(AvaliarComercio::class)->handle($b->user, $acordo, 0, 'mentira');
        $this->assertSame(500 + AvaliacaoSpecs::DELTA[0], $a->user->fresh()->confianca_comercial);

        $r = $this->denunciar($a, $b, 'avaliacao_injusta', 'avaliacao', $av->id);
        app(DecidirCaso::class)->pelaEquipe($r->fresh(), true);

        $this->assertNotNull($av->fresh()->anulada_em);
        $this->assertSame(500, $a->user->fresh()->confianca_comercial);
    }

    public function test_ataque_a_aliado_se_prova_com_o_combate_entre_federacoes_aliadas(): void
    {
        $fa = Federation::create(['name' => 'Alfa']);
        $fb = Federation::create(['name' => 'Beta']);
        DB::table('federation_alliances')->insert([
            'menor_id' => min($fa->id, $fb->id), 'maior_id' => max($fa->id, $fb->id), 'status' => 'aceita',
            'proposta_por_id' => $fa->id, 'created_at' => now()->subDay(), 'updated_at' => now()->subDay(),
        ]);
        [$vitima, $traidor] = [$this->colonia($fa), $this->colonia($fb)];

        $combate = $this->combate($traidor, $vitima);
        $this->assertSame($fb->id, $combate->atacante_federation_id, 'a federação é carimbada no ataque');

        $r = $this->denunciar($vitima, $traidor, 'ataque_a_aliado', 'registro_de_combate', $combate->id);
        app(DecidirCaso::class)->pelaEquipe($r->fresh(), true);

        $this->assertSame(500 + PunicaoSpecs::violacao('ataque_a_aliado')['pontos'], $traidor->user->fresh()->honra_militar_diplomatica);
    }

    public function test_ataque_entre_quem_nao_e_aliado_nao_e_ataque_a_aliado(): void
    {
        [$a, $b] = [$this->colonia(Federation::create(['name' => 'A'])), $this->colonia(Federation::create(['name' => 'B']))];
        $combate = $this->combate($b, $a);

        $this->assertSame('nao_eram_aliados', $this->codigo(fn () => $this->denunciar($a, $b, 'ataque_a_aliado', 'registro_de_combate', $combate->id)));
    }

    public function test_quebra_de_tratado_e_atacar_na_paz_que_o_tratado_comprou(): void
    {
        $fa = Federation::create(['name' => 'Alfa']);
        $fb = Federation::create(['name' => 'Beta']);
        FederationWar::create([
            'declarante_id' => $fa->id, 'alvo_id' => $fb->id, 'comeca_em' => now()->subDays(3),
            'termina_em' => now()->addDays(4), 'status' => 'tratado', 'encerrada_em' => now()->subHour(), 'motivo_fim' => 'tratado',
        ]);
        [$vitima, $agressor] = [$this->colonia($fa), $this->colonia($fb)];

        $dentro = $this->combate($agressor, $vitima);
        $this->denunciar($vitima, $agressor, 'quebra_de_tratado', 'registro_de_combate', $dentro->id);

        $antes = $this->combate($agressor, $vitima, now()->subDays(2)->toDateTimeString());
        $this->assertSame('sem_tratado_vigente', $this->codigo(fn () => $this->denunciar($vitima, $agressor, 'quebra_de_tratado', 'registro_de_combate', $antes->id)));
    }

    public function test_a_api_aceita_a_referencia_e_o_conciliador_ve_a_prova(): void
    {
        [$a, $b] = [$this->colonia(), $this->colonia()];
        $msg = ChatMessage::create(['user_id' => $b->user_id, 'channel' => 'global', 'body' => 'insulto', 'created_at' => now()]);

        Sanctum::actingAs($a->user);
        $r = $this->postJson('/ministry/reports', [
            'accused_colony_id' => $b->id, 'violation' => 'abuso_em_chat', 'texto' => 'Me ofendeu no global.',
            'evidence_type' => 'print_de_chat', 'evidence_ref' => $msg->id,
        ])->assertCreated();

        $this->assertSame('insulto', $r->json('evidencia.mensagem'));

        $this->getJson("/ministry/provas?accused_colony_id={$b->id}")->assertOk()
            ->assertJsonPath('mensagens.0.texto', 'insulto');
        $this->getJson('/ministry/me')->assertJsonPath('catalogo.3.evidencias.0', 'print_de_chat');
    }
}
