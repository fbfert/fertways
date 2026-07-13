<?php

namespace Tests\Feature;

use App\Domain\Chat\PurgarMensagens;
use App\Domain\Colony\CreateColony;
use App\Domain\Ministry\PunicaoSpecs;
use App\Models\Admin;
use App\Models\ChatMessage;
use App\Models\ChatSetting;
use App\Models\Punishment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * O Sistema de Mensagens do §10 (D-77).
 *
 * O GDD publica os 5 canais, a moderação e a retenção; o usuário arbitrou polling (não Reverb),
 * as 5 regiões (4 quadrantes + Núcleo), o filtro que BLOQUEIA, e o silêncio só por pena humana.
 */
class ChatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\ResourceTypeSeeder::class);
        $this->seed(\Database\Seeders\ComponentRecipeSeeder::class);
        $this->seed(\Database\Seeders\BuildingSpecSeeder::class);
    }

    private function colono(int $x, int $y, string $nick): User
    {
        $user = User::factory()->create(['nickname' => $nick, 'tutorial_completed_at' => now()]);
        app(CreateColony::class)->handle($user, "Colônia {$nick}", $x, $y);

        return $user->fresh();
    }

    // ---------------------------------------------------------------- os canais

    public function test_o_global_fala_e_todo_o_planeta_ouve(): void
    {
        $a = $this->colono(40, 40, 'aurora');
        $b = $this->colono(-40, -40, 'boreal');

        $this->actingAs($a)->postJson('/chat/global', ['body' => 'Vendo Ligas na doca!'])->assertCreated();

        $this->actingAs($b)->getJson('/chat/global')
            ->assertOk()
            ->assertJsonPath('mensagens.0.body', 'Vendo Ligas na doca!')
            ->assertJsonPath('mensagens.0.de.nickname', 'aurora');
    }

    public function test_a_regiao_e_o_quadrante_e_o_nucleo_e_o_disco_central(): void
    {
        $nordeste = $this->colono(40, 40, 'ne');
        $sudoeste = $this->colono(-40, -40, 'so');
        $nucleo = $this->colono(0, 3, 'founder');   // a 3 slots da Capital: dentro do disco

        $this->actingAs($nordeste)->postJson('/chat/regiao', ['body' => 'Alguém do NE por aí?'])->assertCreated();

        // O vizinho de quadrante ouve; o do outro canto do planeta, não; o do Núcleo, também não.
        $this->actingAs($nordeste)->getJson('/chat/regiao')->assertJsonCount(1, 'mensagens');
        $this->actingAs($sudoeste)->getJson('/chat/regiao')->assertJsonCount(0, 'mensagens');
        $this->actingAs($nucleo)->getJson('/chat/regiao')->assertJsonCount(0, 'mensagens');

        // E a tela diz a cada um em que sala ele está.
        $this->actingAs($nucleo)->getJson('/chat')->assertJsonPath('regiao', 'Núcleo');
        $this->actingAs($sudoeste)->getJson('/chat')->assertJsonPath('regiao', 'Sudoeste');
    }

    public function test_a_vizinhanca_e_um_raio_e_o_raio_e_do_operador(): void
    {
        $a = $this->colono(20, 20, 'perto1');
        $b = $this->colono(20, 26, 'perto2');    // a 6 slots: dentro do raio 10
        $c = $this->colono(20, 45, 'longe');     // a 25 slots: fora

        $this->actingAs($a)->postJson('/chat/vizinhanca', ['body' => 'Oi, vizinhos!'])->assertCreated();

        $this->actingAs($b)->getJson('/chat/vizinhanca')->assertJsonCount(1, 'mensagens');
        $this->actingAs($c)->getJson('/chat/vizinhanca')->assertJsonCount(0, 'mensagens');

        // O operador alarga o raio (§10.1: "raio configurável") — e o longe passa a ouvir.
        ChatSetting::singleton()->update(['vizinhanca_raio_slots' => 30]);
        $this->actingAs($c)->getJson('/chat/vizinhanca')->assertJsonCount(1, 'mensagens');
    }

    public function test_a_privada_vira_conversa_e_e_evidencia_permanente(): void
    {
        $a = $this->colono(20, 20, 'alice');
        $b = $this->colono(30, 30, 'bruno');

        $this->actingAs($a)->postJson("/chat/privada/{$b->id}", ['body' => 'Te vendo 50 Água por 20 Metal?'])->assertCreated();
        $this->actingAs($b)->postJson("/chat/privada/{$a->id}", ['body' => 'Fechado. Mando o Furgão.'])->assertCreated();

        $this->actingAs($a)->getJson("/chat/privada/{$b->id}")
            ->assertOk()->assertJsonCount(2, 'mensagens');

        // A lista de conversas mostra o outro lado e a última fala.
        $this->actingAs($b)->getJson('/chat/conversas')
            ->assertOk()
            ->assertJsonPath('conversas.0.nickname', 'alice')
            ->assertJsonPath('conversas.0.ultima.body', 'Fechado. Mando o Furgão.');
    }

    // ---------------------------------------------------------------- o bloqueio (MVP social)

    public function test_bloquear_e_nao_ouvir_e_nao_receber(): void
    {
        $a = $this->colono(20, 20, 'quieta');
        $chato = $this->colono(30, 30, 'insistente');

        $this->actingAs($chato)->postJson('/chat/global', ['body' => 'oi oi oi'])->assertCreated();
        $this->actingAs($a)->postJson("/chat/bloquear/{$chato->id}")->assertCreated();

        // Some da tela dela em QUALQUER canal…
        $this->actingAs($a)->getJson('/chat/global')->assertJsonCount(0, 'mensagens');

        // …e a privada dele bate na porta fechada.
        $this->actingAs($chato)->postJson("/chat/privada/{$a->id}", ['body' => 'me responde'])
            ->assertStatus(422)->assertJsonPath('code', 'bloqueado');

        // Mas o resto do planeta continua ouvindo o bloqueado: bloquear não cala o outro.
        $c = $this->colono(40, 40, 'neutro');
        $this->actingAs($c)->getJson('/chat/global')->assertJsonCount(1, 'mensagens');

        // Desbloqueou, voltou.
        $this->actingAs($a)->deleteJson("/chat/bloquear/{$chato->id}")->assertOk();
        $this->actingAs($a)->getJson('/chat/global')->assertJsonCount(1, 'mensagens');
    }

    // ---------------------------------------------------------------- moderação (§10.2)

    public function test_o_filtro_bloqueia_avisa_e_conta_a_reincidencia(): void
    {
        ChatSetting::singleton()->update(['termos_vedados' => ['palavrao']]);
        $a = $this->colono(20, 20, 'boca_suja');

        $this->actingAs($a)->postJson('/chat/global', ['body' => 'Seu PALAVRÃO... digo, palavrao!'])
            ->assertStatus(422)->assertJsonPath('code', 'termo_vedado');

        // A mensagem NÃO entrou (bloquear, não censurar) — e a reincidência ficou contada.
        $this->assertSame(0, ChatMessage::count());
        $this->assertDatabaseHas('chat_filter_hits', ['user_id' => $a->id, 'termo' => 'palavrao']);
    }

    public function test_a_privada_nao_tem_filtro_automatico(): void
    {
        // §10.2, textual: "mensagens privadas não têm filtro automático — denúncias manuais".
        ChatSetting::singleton()->update(['termos_vedados' => ['palavrao']]);
        $a = $this->colono(20, 20, 'a');
        $b = $this->colono(30, 30, 'b');

        $this->actingAs($a)->postJson("/chat/privada/{$b->id}", ['body' => 'palavrao entre nós'])
            ->assertCreated();
    }

    public function test_o_silencio_cala_a_praca_mas_nao_a_boca(): void
    {
        $a = $this->colono(20, 20, 'punido');
        $b = $this->colono(30, 30, 'confidente');

        Punishment::create([
            'user_id' => $a->id, 'kind' => PunicaoSpecs::SILENCIO, 'index_name' => 'conduta_social',
            'points' => 0, 'applied_at' => now(), 'expires_at' => now()->addHours(24),
        ]);

        // Os públicos, fechados (§10.2: "remove acesso aos chats públicos")…
        foreach (['global', 'regiao', 'vizinhanca'] as $canal) {
            $this->actingAs($a)->postJson("/chat/{$canal}", ['body' => 'alô?'])
                ->assertStatus(422)->assertJsonPath('code', 'silenciado');
        }

        // …a privada, aberta: a pena cala a praça, não a boca.
        $this->actingAs($a)->postJson("/chat/privada/{$b->id}", ['body' => 'fui silenciado'])->assertCreated();

        // E expira sozinha.
        $this->travelTo(now()->addHours(25));
        $this->actingAs($a)->postJson('/chat/global', ['body' => 'voltei'])->assertCreated();
    }

    public function test_o_nickname_passa_pelo_mesmo_filtro(): void
    {
        // §03: "passa pelo mesmo filtro automático de termos do chat" — a promessa vale agora.
        ChatSetting::singleton()->update(['termos_vedados' => ['maldito']]);

        $this->postJson('/register', [
            'name' => 'Fulano', 'nickname' => 'maldito99',
            'email' => 'f@fertways.test', 'password' => 'segredo-forte-123',
        ])->assertStatus(422)->assertJsonValidationErrors('nickname');
    }

    // ---------------------------------------------------------------- retenção (§08, publicada)

    public function test_a_purga_cumpre_os_prazos_publicados_e_poupa_as_privadas(): void
    {
        $a = $this->colono(20, 20, 'antigo');
        $b = $this->colono(30, 30, 'destino');

        $velhas = fn (string $canal, ?int $dest = null) => ChatMessage::create([
            'user_id' => $a->id, 'channel' => $canal, 'recipient_user_id' => $dest,
            'x' => 20, 'y' => 20, 'body' => 'mensagem antiga', 'created_at' => now()->subDays(200),
        ]);

        $velhas('global');
        $velhas('regiao:nordeste');
        $velhas('vizinhanca');
        $velhas('privada', $b->id);

        // Vizinhança recente (100 dias): já passou dos 90 — cai também.
        ChatMessage::create([
            'user_id' => $a->id, 'channel' => 'vizinhanca', 'x' => 20, 'y' => 20,
            'body' => 'meio antiga', 'created_at' => now()->subDays(100),
        ]);

        // Global recente (100 dias): dentro dos 180 — fica.
        ChatMessage::create([
            'user_id' => $a->id, 'channel' => 'global', 'body' => 'nem tão antiga',
            'created_at' => now()->subDays(100),
        ]);

        $apagadas = app(PurgarMensagens::class)->handle(now());

        $this->assertSame(4, $apagadas);
        $this->assertSame(1, ChatMessage::where('channel', 'privada')->count(), 'privadas ficam: "sem prazo de expiração no lançamento"');
        $this->assertSame(1, ChatMessage::where('channel', 'global')->count(), 'os 180 dias valem para os dois lados');
    }

    // ---------------------------------------------------------------- os avisos (aditivo do D-77)

    public function test_a_privada_acende_o_selo_e_ler_apaga(): void
    {
        $a = $this->colono(20, 20, 'remetente');
        $b = $this->colono(30, 30, 'destinataria');

        $this->actingAs($a)->postJson("/chat/privada/{$b->id}", ['body' => 'oi!']);
        $this->actingAs($a)->postJson("/chat/privada/{$b->id}", ['body' => 'tudo bem?']);

        // O selo acende para quem recebeu — o poll do HUD vê sem abrir o painel.
        $this->actingAs($b)->getJson('/chat/pendencias')
            ->assertOk()->assertJsonPath('privadas_nao_lidas', 2);

        // E a lista de conversas diz QUAL conversa está acesa.
        $this->actingAs($b)->getJson('/chat/conversas')
            ->assertJsonPath('conversas.0.nao_lidas', 2);

        // Ler É apagar: a marca anda junto com a leitura.
        $this->actingAs($b)->getJson("/chat/privada/{$a->id}");
        $this->actingAs($b)->getJson('/chat/pendencias')->assertJsonPath('privadas_nao_lidas', 0);

        // E não reacende por releitura de página velha: a marca só anda PARA A FRENTE.
        $this->actingAs($b)->getJson("/chat/privada/{$a->id}?after=0");
        $this->actingAs($b)->getJson('/chat/pendencias')->assertJsonPath('privadas_nao_lidas', 0);
    }

    public function test_a_citacao_acende_e_abrir_o_canal_apaga(): void
    {
        $a = $this->colono(20, 20, 'oradora');
        $b = $this->colono(30, 30, 'citado');

        $this->actingAs($a)->postJson('/chat/global', ['body' => 'Alguém viu o @citado por aí?'])->assertCreated();

        $this->actingAs($b)->getJson('/chat/pendencias')->assertJsonPath('mencoes', 1);

        // Citar a si mesmo não acende nada, e nickname inexistente também não.
        $this->actingAs($a)->postJson('/chat/global', ['body' => 'eu, @oradora, e o @fantasma_que_nao_existe']);
        $this->actingAs($a)->getJson('/chat/pendencias')->assertJsonPath('mencoes', 0);

        // Abrir o canal citado apaga o selo: ele avisou, o colono veio.
        $this->actingAs($b)->getJson('/chat/global');
        $this->actingAs($b)->getJson('/chat/pendencias')->assertJsonPath('mencoes', 0);
    }

    public function test_quem_eu_bloqueei_nao_acende_o_meu_selo(): void
    {
        $a = $this->colono(20, 20, 'tranquila');
        $chato = $this->colono(30, 30, 'gritalhao');

        $this->actingAs($a)->postJson("/chat/bloquear/{$chato->id}");

        // Nem a citação na praça, nem a privada (que já era barrada na porta).
        $this->actingAs($chato)->postJson('/chat/global', ['body' => 'ei @tranquila!!']);

        $this->actingAs($a)->getJson('/chat/pendencias')
            ->assertJsonPath('mencoes', 0)
            ->assertJsonPath('privadas_nao_lidas', 0);
    }

    // ---------------------------------------------------------------- o painel

    public function test_espiar_privada_registra_a_auditoria_antes_de_abrir(): void
    {
        $a = $this->colono(20, 20, 'parte_a');
        $b = $this->colono(30, 30, 'parte_b');
        $this->actingAs($a)->postJson("/chat/privada/{$b->id}", ['body' => 'segredo']);

        $admin = Admin::create([
            'name' => 'Mod', 'email' => 'mod@fertways.test',
            'password' => Hash::make('segredo-forte-1234'), 'role' => Admin::OPERADOR,
        ]);

        $this->actingAs($admin, 'admin')->post('/admin/chat/espiar', [
            'nickname_a' => 'parte_a', 'nickname_b' => 'parte_b',
            'motivo' => 'caso #42: histórico solicitado como evidência',
        ])->assertRedirect();

        // §10.3: "todo acesso interno a mensagens reportadas é registrado". O rastro veio ANTES.
        $this->assertDatabaseHas('audit_log', ['acao' => 'chat.acesso_privado']);

        $this->actingAs($admin, 'admin')
            ->get('/admin/chat?privada_a='.$a->id.'&privada_b='.$b->id)
            ->assertOk()->assertSee('segredo');
    }

    public function test_o_painel_silencia_com_prazo_e_auditoria(): void
    {
        $a = $this->colono(20, 20, 'barulhento');
        $admin = Admin::create([
            'name' => 'Mod', 'email' => 'mod@fertways.test',
            'password' => Hash::make('segredo-forte-1234'), 'role' => Admin::OPERADOR,
        ]);

        $this->actingAs($admin, 'admin')->post("/admin/jogadores/{$a->id}/silenciar", [
            'motivo' => 'flood no global', 'horas' => 12,
        ])->assertRedirect();

        // 'sanctum' explícito: o guard admin da chamada anterior ainda está resolvido nesta
        // requisição de teste, e o actingAs sem guard não o desbanca.
        $this->actingAs($a, 'sanctum')->postJson('/chat/global', ['body' => 'alô'])
            ->assertStatus(422)->assertJsonPath('code', 'silenciado');
        $this->assertDatabaseHas('audit_log', ['acao' => 'chat.silenciar']);
    }

    public function test_o_painel_grava_os_termos_e_o_raio(): void
    {
        $admin = Admin::create([
            'name' => 'Mod', 'email' => 'mod@fertways.test',
            'password' => Hash::make('segredo-forte-1234'), 'role' => Admin::OPERADOR,
        ]);

        $this->actingAs($admin, 'admin')->post('/admin/chat/parametros', [
            'vizinhanca_raio_slots' => 15,
            'termos' => "spam\ngolpe",
        ])->assertRedirect();

        $c = ChatSetting::singleton()->fresh();
        $this->assertSame(15, $c->vizinhanca_raio_slots);
        $this->assertSame(['spam', 'golpe'], $c->termos());
    }
}
