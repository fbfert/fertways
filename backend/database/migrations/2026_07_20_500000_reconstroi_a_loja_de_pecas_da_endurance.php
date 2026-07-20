<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reconstrução da Loja de Peças da Endurance (D-135) — a v1 (D-132/D-133: 8 seções × 4 camadas
     * FIXAS, um efeito só — desconto de tributo) não agradou o usuário ao revisar: "as 4 camadas
     * não se diferenciam o bastante" (D-134). Pedido novo: catálogo DINÂMICO (o admin cria/edita/
     * apaga itens à vontade, não mais 32 linhas fixas), com efeitos EMPILHÁVEIS de verdade
     * (produção, veículo, drone, tributo — não só um número).
     *
     * `endurance_piece_specs` e `colony_endurance_pieces` são **substituídas**, não estendidas: o
     * formato antigo (`peca_key = "secao:camada"`) não mapeia para itens dinâmicos. A 1 compra real
     * que existia em produção (colônia "Maior Colonia", `baia_criogenica:comum`) se perde nesta
     * troca — documentado como perda aceita, não bug (ver docs/decisoes.md D-135).
     */
    public function up(): void
    {
        Schema::dropIfExists('colony_endurance_pieces');
        Schema::dropIfExists('endurance_piece_specs');

        Schema::create('endurance_items', function (Blueprint $table) {
            $table->id();
            $table->string('item_key', 60)->unique();
            $table->string('secao', 40);
            $table->string('nome', 120);

            // comum | raro | unico — rótulo de exibição; a escassez de verdade é `quantidade_total`.
            $table->string('tipo', 10);

            // Estoque GLOBAL do servidor, não por colônia. `unico` trava em 1 (checado em código,
            // ComprarItem::handle — não dá para expressar "tipo=unico ⇒ quantidade=1" só com SQL
            // sem um CHECK específico do MariaDB, e o projeto evita CHECK entre colunas por
            // portabilidade com o SQLite dos testes).
            $table->unsignedInteger('quantidade_total');
            $table->unsignedInteger('quantidade_vendida')->default(0);

            $table->unsignedBigInteger('preco_micro');

            // Nulo = sem gate de progressão, só Fert$ + estoque.
            $table->unsignedTinyInteger('marco_minimo')->nullable();

            $table->boolean('vendavel_em_leilao')->default(false);
            $table->text('descricao')->nullable();

            $table->foreignId('admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();

            $table->index('secao');
        });

        /*
         * Os efeitos, N por item — o que faz "seja criativo" ser possível sem reescrever o motor a
         * cada peça nova. `tipo_efeito` é vocabulário FECHADO (ver `EfeitosDaEndurance`): cada valor
         * já está ligado num ponto do jogo. O admin combina efeitos existentes, não inventa
         * mecânica nova pelo painel.
         */
        Schema::create('endurance_item_effects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('endurance_item_id')->constrained()->cascadeOnDelete();

            $table->string('tipo_efeito', 30);

            // building_type, tipo de veículo, ou nulo (drone e desconto de tributo ignoram alvo).
            $table->string('alvo', 40)->nullable();

            $table->unsignedInteger('valor_bps');
            $table->timestamps();

            $table->index('endurance_item_id');
        });

        // A posse, agora com quantidade — uma colônia pode ter mais de 1 unidade de item não-único.
        Schema::create('colony_endurance_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('colony_id')->constrained()->cascadeOnDelete();
            $table->foreignId('endurance_item_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('quantidade')->default(1);
            $table->timestamps();

            $table->unique(['colony_id', 'endurance_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('colony_endurance_items');
        Schema::dropIfExists('endurance_item_effects');
        Schema::dropIfExists('endurance_items');
    }
};
