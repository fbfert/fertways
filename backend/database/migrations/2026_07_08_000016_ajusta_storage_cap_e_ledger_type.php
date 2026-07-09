<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resources', function (Blueprint $table) {
            // O GDD define capacidade de armazenamento só para o Depósito de ZONA NEUTRA
            // (§19.6). Para o slot principal não há tabela nenhuma. NULL = "o GDD não
            // define teto"; o tick não capa. Preencher com um número seria inventar.
            $table->unsignedBigInteger('storage_cap')->nullable()->change();
        });

        Schema::table('vehicles', function (Blueprint $table) {
            // Alinha ao slug usado em building_specs, que vem do GDD ("Furgão de Comércio").
            // O enum anterior era 'furgao_comercio' e nunca casaria com a tabela de custos.
            $table->string('type', 40)->change();
        });

        Schema::table('ledger', function (Blueprint $table) {
            // Sai o enum: acrescentar um tipo de lançamento não deve exigir ALTER de enum,
            // que é caro no MariaDB e mal suportado no SQLite dos testes. A validação vive
            // no Model (Ledger::TIPOS), onde também mora a garantia de append-only.
            $table->string('type', 30)->change();
        });
    }

    public function down(): void
    {
        Schema::table('resources', function (Blueprint $table) {
            $table->unsignedBigInteger('storage_cap')->nullable(false)->change();
        });
    }
};
