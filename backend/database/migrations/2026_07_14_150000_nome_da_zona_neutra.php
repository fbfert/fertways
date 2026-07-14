<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O colono pode nomear a zona neutra, como já nomeia a colônia.
 *
 * Sem regra no GDD (ele nunca fala de nome de zona) — é conveniência de UI, mesma classe da
 * planta da zona (D-67): não muda função nenhuma, só como o colono a reconhece.
 *
 * Idempotente, como as demais (lição do D-59).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('neutral_zones', function (Blueprint $t) {
            if (! Schema::hasColumn('neutral_zones', 'name')) {
                $t->string('name', 120)->nullable()->after('y');
            }
        });
    }

    public function down(): void
    {
        Schema::table('neutral_zones', function (Blueprint $t) {
            if (Schema::hasColumn('neutral_zones', 'name')) {
                $t->dropColumn('name');
            }
        });
    }
};
