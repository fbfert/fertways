<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A periferia deixa de ser "clique em qualquer lugar" (docs/decisoes.md D-147).
 *
 * Até aqui `MapaFertways::podeFundar()` liberava qualquer célula de periferia (d > 5) livre. Nasce
 * `founding_cells`: cada linha é uma célula que o admin liberou para fundação. A tabela nasce
 * VAZIA de propósito — nenhuma célula de periferia é fundável até o admin marcar a primeira. O
 * disco de founders (D-51) não usa esta tabela; a regra dele (48 células, 20 reservadas por
 * fórmula) continua a mesma.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('founding_cells', function (Blueprint $table) {
            $table->id();
            $table->integer('x');
            $table->integer('y');
            $table->timestamps();
            $table->unique(['x', 'y']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('founding_cells');
    }
};
