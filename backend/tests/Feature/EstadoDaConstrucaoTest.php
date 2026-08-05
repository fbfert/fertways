<?php

namespace Tests\Feature;

use App\Domain\Building\EstadoDaConstrucao;
use App\Domain\Colony\CreateColony;
use App\Models\Building;
use App\Models\User;
use Database\Seeders\BuildingSpecSeeder;
use Database\Seeders\ComponentRecipeSeeder;
use Database\Seeders\ResourceTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Em que pé está cada construção, para a colmeia desenhar (A2.V3).
 *
 * O que estes testes guardam é a **honestidade** do estado, não a aparência: que `melhorando` só
 * apareça quando há relógio rodando numa construção já erguida, que `travada` só apareça quando o
 * tick de fato não vai creditar nada, e — o mais importante — que o serviço **cale** quando não tem
 * o que afirmar, em vez de chutar.
 */
class EstadoDaConstrucaoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ResourceTypeSeeder::class);
        $this->seed(ComponentRecipeSeeder::class);
        $this->seed(BuildingSpecSeeder::class);
    }

    private int $proximoSlot = 0;

    private function colono(): User
    {
        $user = User::factory()->create();
        $colony = app(CreateColony::class)->handle($user, 'Nova Aurora', 10 + $this->proximoSlot++, 20);
        $colony->resources()->update(['amount' => 0]);

        return $user->fresh();
    }

    private function ligarTeto(array $valores = []): void
    {
        DB::table('estoque_settings')->where('id', 1)->update(['ativo' => true] + $valores);
    }

    private function estocar(User $user, string $recurso, int $quanto): void
    {
        $user->colony->resources()->where('resource_type', $recurso)->update(['amount' => $quanto]);
    }

    /** @return array{estado: string|null, recursos_no_teto: list<string>} */
    private function estadoDe(User $user, string $tipo): array
    {
        $colony = $user->colony()->first();
        $b = $colony->buildings()->where('type', $tipo)->firstOrFail();

        $producao = DB::table('building_specs')
            ->where('building_type', $tipo)->where('level', $b->level)
            ->value('producao_hora_json');

        $estoque = $colony->resources()->pluck('amount', 'resource_type')
            ->map(fn ($v) => (int) $v)->all();

        return app(EstadoDaConstrucao::class)->de(
            $colony, $b, json_decode($producao ?? 'null', true), $estoque,
        );
    }

    // ─────────────────────────────────── o relógio

    /**
     * ⚠️ O estado que não existia na tela: subir do 3 para o 4 era IDÊNTICO a estar parado no 3.
     *
     * O dado (`upgrade_finish_at`) já vinha no payload desde sempre; a cena só olhava `level > 0`.
     * Este teste é o que impede a distinção de se perder de novo.
     */
    public function test_construcao_erguida_com_relogio_rodando_esta_melhorando(): void
    {
        $user = $this->colono();

        $user->colony->buildings()->where('type', 'fazenda')
            ->update(['level' => 3, 'upgrade_finish_at' => Carbon::now()->addHour()]);

        $this->assertSame(EstadoDaConstrucao::MELHORANDO, $this->estadoDe($user, 'fazenda')['estado']);
    }

    public function test_obra_nova_no_nivel_zero_esta_erguendo(): void
    {
        $user = $this->colono();

        $user->colony->buildings()->where('type', 'fazenda')
            ->update(['level' => 0, 'upgrade_finish_at' => Carbon::now()->addHour()]);

        $this->assertSame(EstadoDaConstrucao::ERGUENDO, $this->estadoDe($user, 'fazenda')['estado']);
    }

    /** Enfileirada atrás de outra: a linha existe, a obra não começou, e ainda é obra nova. */
    public function test_nivel_zero_sem_relogio_ainda_esta_erguendo(): void
    {
        $user = $this->colono();

        $user->colony->buildings()->where('type', 'fazenda')
            ->update(['level' => 0, 'upgrade_finish_at' => null]);

        $this->assertSame(EstadoDaConstrucao::ERGUENDO, $this->estadoDe($user, 'fazenda')['estado']);
    }

    // ─────────────────────────────────── o teto (§14)

    public function test_com_espaco_no_estoque_ela_esta_produzindo(): void
    {
        $this->ligarTeto();
        $user = $this->colono();
        $this->estocar($user, 'biomassa', 0);

        $estado = $this->estadoDe($user, 'fazenda');

        $this->assertSame(EstadoDaConstrucao::PRODUZINDO, $estado['estado']);
        $this->assertSame([], $estado['recursos_no_teto']);
    }

    /**
     * O caso medido em produção: 5 recursos no teto em 2 colônias, com Gerador, Captação, Fazenda e
     * Reator rodando e rendendo **zero** — e nada na tela dizendo isso.
     */
    public function test_com_o_estoque_no_teto_ela_esta_travada(): void
    {
        $this->ligarTeto(['capacidade_base' => 1000]);
        $user = $this->colono();
        $this->estocar($user, 'biomassa', 5000);

        $estado = $this->estadoDe($user, 'fazenda');

        $this->assertSame(EstadoDaConstrucao::TRAVADA, $estado['estado']);
        $this->assertSame(['biomassa'], $estado['recursos_no_teto']);
    }

    /** Desligado, o teto não existe — nem como estado. */
    public function test_com_o_teto_desligado_nada_trava(): void
    {
        DB::table('estoque_settings')->where('id', 1)->update(['ativo' => false]);
        $user = $this->colono();
        $this->estocar($user, 'biomassa', 999_999);

        $this->assertSame(EstadoDaConstrucao::PRODUZINDO, $this->estadoDe($user, 'fazenda')['estado']);
    }

    /**
     * ⚠️ Melhorando E travada ao mesmo tempo — porque ela continua produzindo no nível atual
     * enquanto sobe. O relógio é o estado principal, mas o teto não pode sumir do payload.
     */
    public function test_o_relogio_manda_no_estado_mas_o_teto_continua_relatado(): void
    {
        $this->ligarTeto(['capacidade_base' => 1000]);
        $user = $this->colono();
        $this->estocar($user, 'biomassa', 5000);

        $user->colony->buildings()->where('type', 'fazenda')
            ->update(['level' => 3, 'upgrade_finish_at' => Carbon::now()->addHour()]);

        $estado = $this->estadoDe($user, 'fazenda');

        $this->assertSame(EstadoDaConstrucao::MELHORANDO, $estado['estado']);
        $this->assertSame(['biomassa'], $estado['recursos_no_teto']);
    }

    // ─────────────────────────────────── o Biocombustível, que não passa pelo teto geral

    /**
     * ⚠️ A Destilaria é travada pelo **Tanque de Combustível**, não pelo teto geral.
     *
     * `TetoDoEstoque::SEM_TETO_GERAL` exclui o Biocombustível de propósito (§21.9/D-131). Perguntar
     * só ao teto geral devolveria "tem espaço" para uma Destilaria com o tanque cheio — exatamente o
     * caso que o D-131 criou, e o que a tela mais precisa mostrar. Sem este teste, o estado mentiria
     * justo na construção cuja parada é mais confusa para o jogador.
     */
    public function test_destilaria_trava_pelo_tanque_cheio_e_nao_pelo_teto_geral(): void
    {
        $this->ligarTeto();
        $user = $this->colono();
        $colony = $user->colony()->first();

        $livre = ((int) $colony->buildings()->max('slot')) + 1;
        $destilaria = Building::create([
            'colony_id' => $colony->id, 'type' => 'destilaria', 'level' => 1, 'slot' => $livre,
        ]);
        Building::create([
            'colony_id' => $colony->id, 'type' => 'tanque_de_combustivel', 'level' => 1,
            'slot' => $livre + 1,
        ]);

        $servico = app(EstadoDaConstrucao::class);
        $producao = ['biocombustivel' => 20];

        // Tanque de nível 1 cabe 200. Com 199 ainda entra o próximo litro.
        $this->assertSame(
            EstadoDaConstrucao::PRODUZINDO,
            $servico->de($colony, $destilaria, $producao, ['biocombustivel' => 199])['estado'],
        );

        // No teto do tanque ela para de converter, sem gastar Biomassa/Energia à toa (D-131).
        $cheio = $servico->de($colony, $destilaria, $producao, ['biocombustivel' => 200]);
        $this->assertSame(EstadoDaConstrucao::TRAVADA, $cheio['estado']);
        $this->assertSame(['biocombustivel'], $cheio['recursos_no_teto']);
    }

    // ─────────────────────────────────── o payload que a colmeia lê

    /**
     * O estado chega em `GET /buildings`, que é de onde a cena tira tudo.
     *
     * ⚠️ E a Indústria Siderúrgica **não** pode ser tratada como produtora: ela declara em
     * `producao_hora_json` o que **consome** (D-82). Quem já resolve isso é o `$efeito` do
     * controller, e o estado herda a correção — este teste é o que garante que continue herdando,
     * em vez de alguém repetir a regra no serviço e as duas divergirem.
     */
    public function test_o_endpoint_entrega_o_estado_e_nao_confunde_a_siderurgica(): void
    {
        $this->ligarTeto(['capacidade_base' => 1000]);
        $user = $this->colono();
        $colony = $user->colony()->first();
        $this->estocar($user, 'biomassa', 5000);

        $livre = ((int) $colony->buildings()->max('slot')) + 1;
        Building::create([
            'colony_id' => $colony->id, 'type' => 'industria_siderurgica', 'level' => 1,
            'slot' => $livre,
        ]);

        $specs = collect($this->actingAs($user)->getJson('/buildings')->assertOk()->json())
            ->keyBy('type');

        $this->assertSame(EstadoDaConstrucao::TRAVADA, $specs['fazenda']['estado']);
        $this->assertSame(['biomassa'], $specs['fazenda']['recursos_no_teto']);

        // O Metal Bruto dela é insumo: sem produção declarada, o serviço cala.
        $this->assertNull($specs['industria_siderurgica']['estado']);
        $this->assertSame([], $specs['industria_siderurgica']['recursos_no_teto']);
    }

    // ─────────────────────────────────── o silêncio honesto

    /**
     * Quartel não declara produção nenhuma. O serviço **cala** — `null`, e a cena desenha o que
     * sempre desenhou. Chamá-lo de "produzindo" seria afirmar o que ninguém sabe.
     */
    public function test_construcao_sem_producao_declarada_nao_recebe_estado(): void
    {
        $user = $this->colono();

        $colony = $user->colony()->first();
        $livre = ((int) $colony->buildings()->max('slot')) + 1;
        $b = Building::create([
            'colony_id' => $colony->id, 'type' => 'quartel', 'level' => 1, 'slot' => $livre,
        ]);

        $estado = app(EstadoDaConstrucao::class)->de($colony, $b, null, []);

        $this->assertNull($estado['estado']);
        $this->assertSame([], $estado['recursos_no_teto']);
    }
}
