<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 2 do D-135: os Leilões (D-129) passam a vender item da Endurance, não só recurso.
 *
 * `auctions.resource_type` tinha FK obrigatória para `resource_types.code` — não cabe um
 * `item_key` da Endurance ali. Em vez de inventar uma segunda tabela de leilão (duplicando toda a
 * máquina de lance/fechamento do D-129), o lote vira OU-OU: `resource_type` OU
 * `endurance_item_id`, nunca os dois — checado em código (`Auction::ehItem()`), não em
 * constraint (SQLite dos testes não suporta CHECK condicional entre colunas nulas do jeito que o
 * MariaDB suportaria, e a app já teria de confiar no código de qualquer forma).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auctions', function (Blueprint $table) {
            $table->string('resource_type', 40)->nullable()->change();
            $table->unsignedBigInteger('qty')->nullable()->change();
            $table->foreignId('endurance_item_id')->nullable()->after('resource_type')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('auctions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('endurance_item_id');
            $table->unsignedBigInteger('qty')->nullable(false)->change();
            $table->string('resource_type', 40)->nullable(false)->change();
        });
    }
};
