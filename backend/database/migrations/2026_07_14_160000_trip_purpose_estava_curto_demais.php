<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `trip_purpose` nasceu `VARCHAR(12)` (`2026_07_09_170000_create_market_accounts.php`), quando os
 * únicos valores eram `entrega`/`retirada` (D-33). O D-60 (Ministério dos Transportes) passou a
 * gravar `entrega_de_fabrica` — 19 caracteres — e ninguém alargou a coluna.
 *
 * **Em produção, toda compra de Caminhão de Carga vem falhando com 500** desde o D-60
 * (2026-07-12): o MariaDB recusa a `string data, right truncated` em modo estrito, a transação sofre
 * rollback (nenhum Fert$ perdido, nenhum caminhão fantasma — é limpo), e o colono só vê "Server
 * Error". **A suíte de testes nunca pegou isto** porque roda em SQLite, que não aplica largura de
 * `VARCHAR` — a mesma família de armadilha do D-59 (`docs/decisoes.md`).
 *
 * 32, e não só 19+folga: mesma largura de `structure` e `resource_type`, por consistência.
 *
 * Idempotente, como as demais (lição do D-59).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $t) {
            $t->string('trip_purpose', 32)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $t) {
            $t->string('trip_purpose', 12)->nullable()->change();
        });
    }
};
