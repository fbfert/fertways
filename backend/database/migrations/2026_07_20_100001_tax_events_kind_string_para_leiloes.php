<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Leilões (D-129) precisam de um `kind` novo em `tax_events` (`leilao_venda`). Mesmo motivo do
     * D-58 ter tirado `ledger.type` do enum: acrescentar um tipo de fato tributável não deve exigir
     * ALTER de enum, caro no MariaDB e mal suportado no SQLite dos testes.
     */
    public function up(): void
    {
        Schema::table('tax_events', function (Blueprint $table) {
            $table->string('kind', 30)->change();
        });
    }

    public function down(): void
    {
        Schema::table('tax_events', function (Blueprint $table) {
            $table->enum('kind', ['transporte_entrega', 'mercado_venda'])->change();
        });
    }
};
