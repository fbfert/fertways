<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O Governo vende no Mercado Central (docs/decisoes.md D-87).
 *
 * `colony_id` nulo passa a significar "esta oferta é do Governo" — o mesmo padrão que
 * `vehicles.colony_id` nulo já usa para a frota pública (D-60). `tax_events.colony_id` acompanha:
 * a venda do Governo ainda passa pelo mesmo gate de idempotência (`economic_event_key`), só que
 * sem colônia nenhuma do lado do vendedor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('market_orders', function (Blueprint $t) {
            $t->foreignId('colony_id')->nullable()->change();
        });

        Schema::table('tax_events', function (Blueprint $t) {
            $t->foreignId('colony_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('market_orders', function (Blueprint $t) {
            $t->foreignId('colony_id')->nullable(false)->change();
        });

        Schema::table('tax_events', function (Blueprint $t) {
            $t->foreignId('colony_id')->nullable(false)->change();
        });
    }
};
