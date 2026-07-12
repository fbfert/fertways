<?php

namespace Tests\Feature;

use App\Domain\Media\Biblioteca;
use App\Domain\Media\Vinculaveis;
use App\Models\Admin;
use App\Models\ImageBinding;
use App\Models\MediaAsset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * A gestão de imagens (docs/decisoes.md D-68).
 *
 * O que se afirma aqui é, sobretudo, o **fallback**: uma construção sem imagem não é um buraco na
 * tela, é um hexágono — e apagar uma imagem em uso não pode deixar o jogo apontando para um arquivo
 * que já não existe.
 */
class ImagensTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\ResourceTypeSeeder::class);
        $this->seed(\Database\Seeders\BuildingSpecSeeder::class);
    }

    private function admin(): Admin
    {
        return Admin::create([
            'name' => 'Op', 'email' => 'op@fertways.test',
            'password' => Hash::make('segredo-forte-1234'), 'role' => Admin::OPERADOR,
        ]);
    }

    private function imagem(string $cat = 'colonia-base', string $arq = 'reator-helios.png'): MediaAsset
    {
        return MediaAsset::create([
            'category' => $cat,
            'filename' => $arq,
            'filename_large' => str_replace('.png', '_1024.png', $arq),
        ]);
    }

    // ── a API que o jogo consome ────────────────────────────────────────────────────────────────

    /**
     * **Só o que TEM imagem aparece no mapa.** É isso que faz o jogo nunca ficar com buraco: o
     * frontend não acha a chave, e desenha o hexágono colorido de sempre.
     */
    public function test_o_mapa_de_arte_so_traz_o_que_tem_imagem(): void
    {
        $colono = User::factory()->create();
        app(\App\Domain\Colony\CreateColony::class)->handle($colono, 'Base', 20, 20);

        $img = $this->imagem();
        ImageBinding::create(['entity_key' => 'reator_de_energia', 'media_asset_id' => $img->id]);

        $this->actingAs($colono)
            ->getJson('/images')
            ->assertOk()
            ->assertJsonCount(1, 'images')
            ->assertJsonPath('images.reator_de_energia.pequena', '/media/colonia-base/reator-helios.png')
            ->assertJsonPath('images.reator_de_energia.grande', '/media/colonia-base/reator-helios_1024.png')
            // A Fazenda não tem imagem: não vem no mapa, e o jogo a desenha como hexágono.
            ->assertJsonMissingPath('images.fazenda');
    }

    /** Sem versão grande, a grande cai na pequena — o cartão de detalhe nunca fica sem imagem. */
    public function test_sem_versao_grande_a_grande_cai_na_pequena(): void
    {
        $colono = User::factory()->create();
        app(\App\Domain\Colony\CreateColony::class)->handle($colono, 'Base', 20, 20);

        $img = MediaAsset::create(['category' => 'colonia-base', 'filename' => 'x.png']);
        ImageBinding::create(['entity_key' => 'fazenda', 'media_asset_id' => $img->id]);

        $this->actingAs($colono)->getJson('/images')
            ->assertJsonPath('images.fazenda.grande', '/media/colonia-base/x.png');
    }

    // ── o painel ────────────────────────────────────────────────────────────────────────────────

    public function test_a_aba_de_imagens_abre_e_lista_as_categorias(): void
    {
        $this->imagem();

        $this->actingAs($this->admin(), 'admin')
            ->get('/admin/imagens')
            ->assertOk()
            ->assertSee('Imagens das construções')
            ->assertSee('reator-helios.png')
            // As nove categorias, com a de mapas que nasce vazia (decisão do usuário).
            ->assertSee('Mapas');
    }

    public function test_vincular_e_desvincular(): void
    {
        $img = $this->imagem();
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')
            ->post('/admin/imagens/vincular', [
                'entity_key' => 'reator_de_energia',
                'media_asset_id' => $img->id,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('image_bindings', ['entity_key' => 'reator_de_energia']);

        // Sem imagem = **volta ao hexágono**. Não é erro: é escolha, e é o que torna a arte reversível.
        $this->actingAs($admin, 'admin')
            ->post('/admin/imagens/vincular', [
                'entity_key' => 'reator_de_energia',
                'media_asset_id' => '',
            ])
            ->assertRedirect();

        $this->assertDatabaseMissing('image_bindings', ['entity_key' => 'reator_de_energia']);
    }

    /**
     * **Apagar uma imagem em uso devolve as construções ao hexágono** — e a auditoria diz QUAIS.
     *
     * Sem isso, alguém apagaria uma imagem, três prédios perderiam a arte, e ninguém relacionaria as
     * duas coisas semanas depois. Pior: o jogo apontaria para um arquivo que já não existe.
     */
    public function test_apagar_a_imagem_devolve_as_construcoes_ao_hexagono(): void
    {
        $img = $this->imagem();
        ImageBinding::create(['entity_key' => 'reator_de_energia', 'media_asset_id' => $img->id]);
        ImageBinding::create(['entity_key' => 'fazenda', 'media_asset_id' => $img->id]);

        $this->actingAs($this->admin(), 'admin')
            ->post("/admin/imagens/{$img->id}/apagar")
            ->assertRedirect();

        $this->assertDatabaseMissing('media_assets', ['id' => $img->id]);
        // O cascade desfaz os vínculos: nada aponta para um arquivo que não existe mais.
        $this->assertSame(0, ImageBinding::count());

        $ato = \App\Models\AuditEntry::where('acao', 'imagem.apagar')->latest('id')->first();
        $this->assertNotNull($ato);
        $this->assertStringContainsString('Reator de Energia', $ato->resumo);
        $this->assertStringContainsString('Fazenda', $ato->resumo);
    }

    // ── o upload ────────────────────────────────────────────────────────────────────────────────

    /** Só PNG: o sprite precisa de fundo transparente, e um JPEG viraria um quadrado branco. */
    public function test_so_png(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->post('/admin/imagens', [
                'categoria' => 'colonia-base',
                'arquivo' => UploadedFile::fake()->image('foto.jpg'),
            ])
            ->assertSessionHas('erro');

        $this->assertSame(0, MediaAsset::count());
    }

    /** Categoria desconhecida não cria pasta órfã. */
    public function test_categoria_invalida_e_recusada(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->post('/admin/imagens', [
                'categoria' => 'inventada',
                'arquivo' => UploadedFile::fake()->create('x.png', 10, 'image/png'),
            ])
            ->assertSessionHasErrors('categoria');
    }

    // ── o catálogo do que pode receber arte ─────────────────────────────────────────────────────

    /**
     * As coisas vinculáveis existem e têm nome humano. Um painel que gere prédios e os chama de
     * `refinaria_quimica` não serve — e é por isso que a lista de nomes é UMA só, partilhada com o
     * gerador do GDD.
     */
    public function test_o_que_pode_receber_arte(): void
    {
        $todas = Vinculaveis::todas();

        // As 17 da colônia, as 9 da zona, veículos, unidades, e a Capital.
        $this->assertArrayHasKey('reator_de_energia', $todas);
        $this->assertArrayHasKey('muralha_de_perimetro', $todas);
        $this->assertArrayHasKey('sentinela', $todas);
        $this->assertArrayHasKey('capital:area:oeste', $todas);
        $this->assertArrayHasKey('capital:slot:2', $todas);

        // Nome humano, não slug.
        $this->assertSame('Refinaria Química', $todas['refinaria_quimica']);
        $this->assertSame('Captação de Água', $todas['captacao_de_agua']);
    }

    /** As nove categorias, e a de `mapas` que nasce vazia à espera de arte. */
    public function test_as_nove_categorias(): void
    {
        $this->assertCount(9, Biblioteca::CATEGORIAS);
        $this->assertArrayHasKey('mapas', Biblioteca::CATEGORIAS);
        $this->assertArrayHasKey('destrocos-da-endurance', Biblioteca::CATEGORIAS);
    }
}
