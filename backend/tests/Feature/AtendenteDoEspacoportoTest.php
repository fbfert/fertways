<?php

namespace Tests\Feature;

use App\Domain\Cargos\AtendimentoDoEspacoporto;
use App\Domain\Cargos\CargosCivicosSpecs;
use App\Domain\Cargos\GerirCargoCivico;
use App\Domain\Cargos\PagarCargosCivicos;
use App\Domain\Colony\CreateColony;
use App\Exceptions\DomainRuleException;
use App\Models\Colony;
use App\Models\Feedback;
use App\Models\Ledger;
use App\Models\User;
use Database\Seeders\BuildingSpecSeeder;
use Database\Seeders\ComponentRecipeSeeder;
use Database\Seeders\ResourceTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O Atendente do Espaçoporto, o 5º cargo cívico do §14.2 (D-270).
 *
 * O D-130 o deixou de fora: "100% dependente do Espaçoporto, que não existe". O Espaçoporto existe
 * desde o D-262. O GDD diz o que ele faz — *"responde a fluxos predefinidos e encaminha dúvidas; não
 * altera reservas, fretes ou rotas"* — e não diz quanto ganha. A recomendação aprovada: **o mesmo dos
 * outros cargos** (salário de 50 F$/dia, teto de 400 F$/semana), e o bônus por **dúvida que ajudou** —
 * quem decide se ajudou é quem perguntou. Resposta qualquer não paga; só a que serviu.
 */
class AtendenteDoEspacoportoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ResourceTypeSeeder::class);
        $this->seed(ComponentRecipeSeeder::class);
        $this->seed(BuildingSpecSeeder::class);
    }

    private int $slot = 0;

    private function colono(string $nick): User
    {
        $u = User::factory()->create(['email' => "{$nick}@t.test", 'nickname' => $nick]);
        app(CreateColony::class)->handle($u, "Colônia {$nick}", 10 + 2 * $this->slot++, 10);

        return $u->fresh();
    }

    private function atendente(string $nick = 'at'): User
    {
        $u = $this->colono($nick);
        app(GerirCargoCivico::class)->nomear($u, CargosCivicosSpecs::ATENDENTE_DO_ESPACOPORTO);

        return $u;
    }

    private function servico(): AtendimentoDoEspacoporto
    {
        return app(AtendimentoDoEspacoporto::class);
    }

    public function test_o_atendente_e_um_cargo_como_os_outros_e_recebe_salario(): void
    {
        $at = $this->atendente();

        $this->assertContains(CargosCivicosSpecs::ATENDENTE_DO_ESPACOPORTO, CargosCivicosSpecs::KINDS);
        $this->assertSame('Atendente do Espaçoporto', CargosCivicosSpecs::NOMES[CargosCivicosSpecs::ATENDENTE_DO_ESPACOPORTO]);

        app(PagarCargosCivicos::class)->handle();

        $this->assertSame(
            CargosCivicosSpecs::SALARIO_DIARIO_MICRO,
            (int) Ledger::where('colony_id', $at->colony->id)->where('type', 'salario_cargo_civico')->sum('amount'),
        );
    }

    public function test_a_duvida_que_ajudou_paga_o_bonus_ao_atendente(): void
    {
        $at = $this->atendente();
        $colono = $this->colono('novo');

        $d = $this->servico()->perguntar($colono, 'Por que Auryn não me deixa alugar?');
        $this->servico()->responder($at, $d->id, 'Auryn pede o marco 25. Suba de marco e volte.');

        $antes = (int) $at->colony->fresh()->fert_micro;
        $this->servico()->avaliar($colono, $d->id, true);

        $this->assertSame($antes + CargosCivicosSpecs::BONUS_MICRO, (int) $at->colony->fresh()->fert_micro);
        $this->assertTrue((bool) $d->fresh()->ajudou);
    }

    public function test_resposta_que_nao_ajudou_nao_paga(): void
    {
        $at = $this->atendente();
        $colono = $this->colono('novo');
        $d = $this->servico()->perguntar($colono, 'Como funciona o imposto?');
        $this->servico()->responder($at, $d->id, 'Não sei.');

        $antes = (int) $at->colony->fresh()->fert_micro;
        $this->servico()->avaliar($colono, $d->id, false);

        $this->assertSame($antes, (int) $at->colony->fresh()->fert_micro);
    }

    /** Uma avaliação por dúvida: avaliar de novo não paga de novo. */
    public function test_a_avaliacao_e_uma_so(): void
    {
        $at = $this->atendente();
        $colono = $this->colono('novo');
        $d = $this->servico()->perguntar($colono, 'Pergunta?');
        $this->servico()->responder($at, $d->id, 'Resposta.');
        $this->servico()->avaliar($colono, $d->id, true);

        $this->expectException(DomainRuleException::class);
        $this->servico()->avaliar($colono, $d->id, true);
    }

    public function test_so_o_atendente_responde_e_nunca_a_propria_duvida(): void
    {
        $at = $this->atendente();
        $outro = $this->colono('outro');

        $d = $this->servico()->perguntar($outro, 'Pergunta?');
        try {
            $this->servico()->responder($this->colono('curioso'), $d->id, 'Eu sei!');
            $this->fail('quem não é Atendente não responde');
        } catch (DomainRuleException $e) {
            $this->assertSame('sem_cargo', $e->codigo);
        }

        $propria = $this->servico()->perguntar($at, 'Minha própria dúvida');
        $this->expectException(DomainRuleException::class);
        $this->servico()->responder($at, $propria->id, 'Eu mesmo respondo.');
    }

    /** Só quem perguntou avalia. */
    public function test_so_quem_perguntou_avalia(): void
    {
        $at = $this->atendente();
        $d = $this->servico()->perguntar($this->colono('novo'), 'Pergunta?');
        $this->servico()->responder($at, $d->id, 'Resposta.');

        $this->expectException(DomainRuleException::class);
        $this->servico()->avaliar($at, $d->id, true);
    }

    /** "Encaminha dúvidas": vira uma dúvida na aba Feedback do painel, para a equipe. */
    public function test_encaminhar_leva_a_duvida_para_a_equipe(): void
    {
        $at = $this->atendente();
        $colono = $this->colono('novo');
        $d = $this->servico()->perguntar($colono, 'O Cargueiro sumiu com a minha carga!');

        $this->servico()->encaminhar($at, $d->id);

        $f = Feedback::firstOrFail();
        $this->assertSame('duvida', $f->tipo);
        $this->assertSame($colono->id, $f->user_id);
        $this->assertStringContainsString('sumiu com a minha carga', $f->mensagem);
        $this->assertNotNull($d->fresh()->encaminhada_em);
    }

    /** Três dúvidas abertas por colono, no máximo — a fila é de quem precisa, não de quem insiste. */
    public function test_tres_duvidas_abertas_por_vez(): void
    {
        $colono = $this->colono('novo');
        foreach (range(1, 3) as $i) {
            $this->servico()->perguntar($colono, "Pergunta {$i}");
        }

        $this->expectException(DomainRuleException::class);
        $this->servico()->perguntar($colono, 'Pergunta 4');
    }

    public function test_a_api_mostra_a_fila_so_ao_atendente(): void
    {
        $at = $this->atendente();
        $colono = $this->colono('novo');

        $this->actingAs($colono)->postJson('/espacoporto/duvidas', ['pergunta' => 'Como alugo o Cargueiro?'])->assertCreated();

        $this->actingAs($colono)->getJson('/espacoporto/duvidas')
            ->assertOk()->assertJsonPath('sou_atendente', false)->assertJsonCount(1, 'minhas')->assertJsonCount(0, 'fila');

        $id = $this->actingAs($at)->getJson('/espacoporto/duvidas')
            ->assertOk()->assertJsonPath('sou_atendente', true)->assertJsonCount(1, 'fila')->json('fila.0.id');

        $this->actingAs($at)->postJson("/espacoporto/duvidas/{$id}/responder", ['resposta' => 'Na Capital, área Sul.'])->assertOk();
        $this->actingAs($colono)->postJson("/espacoporto/duvidas/{$id}/avaliar", ['ajudou' => true])->assertOk();

        $this->assertSame(1, Ledger::where('colony_id', $at->colony->id)->where('type', 'bonus_cargo_civico')->count());
    }
}
