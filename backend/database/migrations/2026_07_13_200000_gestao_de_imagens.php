<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A gestão de imagens (docs/decisoes.md D-68).
 *
 * Duas tabelas, e a separação é o ponto: **a biblioteca e o uso são coisas diferentes**. Uma imagem
 * pode existir sem estar em uso (acabou de ser enviada), e uma construção pode não ter imagem (volta
 * ao hexágono colorido, que continua sendo o fallback). Fundir as duas faria "apagar a imagem" e
 * "tirar a imagem do prédio" virarem o mesmo ato, e não são.
 *
 * ⚠️ **Os ARQUIVOS moram fora do repositório e fora da árvore de deploy** — em `/home/fertways/media`,
 * servidos por um symlink em `public_html/media`. Duas razões, e as duas já morderam antes:
 *
 *  1. O `deploy.sh` **aborta** se achar arquivo não rastreado na árvore de deploy (lição de
 *     2026-07-11). Uma imagem enviada pelo painel quebraria o próximo deploy.
 *  2. 52 MB de PNG num repositório git é para sempre, e cada upload exigiria um commit — o que
 *     derrotaria o "trocar quando quisermos" que é o motivo desta feature existir.
 *
 * O banco guarda só o CAMINHO. O arquivo é do disco.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('media_assets')) {
            Schema::create('media_assets', function (Blueprint $t) {
                $t->id();

                // A pasta em que ela vive: `capital`, `colonia-base`, `logistica-e-frota`…
                $t->string('category', 40);

                // O nome do arquivo pequeno (264×264) — o que a cena do jogo desenha.
                $t->string('filename', 120);

                /*
                 * O grande (1024×1024), quando existe. O zip trazia os dois; um upload novo pode
                 * trazer só um. Nulo = não há versão grande, e o painel de detalhe usa a pequena.
                 */
                $t->string('filename_large', 120)->nullable();

                // Quem enviou. Nulo para as que vieram do import inicial, que não tem autor.
                $t->foreignId('admin_id')->nullable()->constrained('admins')->nullOnDelete();

                $t->timestamps();

                // Duas imagens com o mesmo nome na mesma pasta seriam o mesmo arquivo.
                $t->unique(['category', 'filename']);
            });
        }

        if (! Schema::hasTable('image_bindings')) {
            Schema::create('image_bindings', function (Blueprint $t) {
                $t->id();

                /*
                 * A chave da coisa do jogo. Um `building_type` do catálogo (`reator_de_energia`,
                 * `sentinela`, `furgao_de_comercio`), ou um lugar da Capital (`capital:slot:2`,
                 * `capital:area:oeste`). Texto e não FK: as coisas do jogo vivem em tabelas
                 * diferentes, e algumas — as áreas da Capital — não vivem em tabela nenhuma.
                 */
                $t->string('entity_key', 60)->unique();

                /*
                 * `cascadeOnDelete`: apagar a imagem da biblioteca desfaz o vínculo, e a construção
                 * volta ao hexágono. É o comportamento que o usuário escolheu, e é o único que não
                 * deixa o jogo apontando para um arquivo que não existe mais.
                 */
                $t->foreignId('media_asset_id')->constrained('media_assets')->cascadeOnDelete();

                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('image_bindings');
        Schema::dropIfExists('media_assets');
    }
};
