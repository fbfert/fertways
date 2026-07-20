<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A Loja de Peças da Endurance (D-132) — desenho nosso sobre a curva do Marco do §05, que o
     * GDD só nomeia ("peças comuns", "peças de reputação nível 1/2", "leilões de peças únicas") sem
     * publicar o que uma peça é. O catálogo (32 peças: 8 seções × 4 camadas) vive em código
     * (`App\Domain\Endurance\EnduranceSpecs`), não em tabela — é dado de design arbitrado, não
     * conteúdo administrável. Só a POSSE é dinâmica por colônia, e é o que esta tabela guarda.
     */
    public function up(): void
    {
        Schema::create('colony_endurance_pieces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('colony_id')->constrained()->cascadeOnDelete();

            // "{secao}:{camada}" — chave do catálogo em EnduranceSpecs::catalogo(). String, não
            // FK: o catálogo não é tabela.
            $table->string('peca_key', 60);

            $table->dateTime('comprado_em');

            $table->unique(['colony_id', 'peca_key']);
            // Para checar rápido, no ato da compra de uma peça `unica`, se ALGUMA colônia já a tem.
            $table->index('peca_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('colony_endurance_pieces');
    }
};
