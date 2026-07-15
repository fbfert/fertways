<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Upgrade de zona e manutenção territorial (GDD §27.12; docs/decisoes.md D-84).
 *
 * A Fatia 1 (D-52) fixou `level` em 1 para sempre — "upgrade de zona fica para uma fatia
 * posterior". É esta fatia. Duas colunas novas para o relógio do upgrade (espelham o padrão de
 * `ZoneBuild`, mas fora do canteiro: o upgrade debita direto da colônia, como a ocupação, não do
 * canteiro transportado por veículo — D-84 explica por quê) e duas para o relógio da manutenção
 * territorial, que nunca foi implementada para nenhuma zona, nem a de nível 1.
 *
 * `maintenance_next_due_at` nasce preenchida para quem **já** tem zona (`owner_colony_id` não
 * nulo): dar 24 h de trégua antes da primeira cobrança, em vez de cobrar retroativo ou marcar
 * inadimplência no instante do deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('neutral_zones', function (Blueprint $t) {
            $t->unsignedTinyInteger('level_target')->nullable()->after('level');
            $t->timestamp('level_upgrade_finishes_at')->nullable()->after('level_target');

            $t->timestamp('maintenance_next_due_at')->nullable()->after('last_extraction_at');
            $t->timestamp('maintenance_unpaid_since')->nullable()->after('maintenance_next_due_at');
        });

        DB::table('neutral_zones')
            ->whereNotNull('owner_colony_id')
            ->update(['maintenance_next_due_at' => now()->addHours(24)]);
    }

    public function down(): void
    {
        Schema::table('neutral_zones', function (Blueprint $t) {
            $t->dropColumn([
                'level_target', 'level_upgrade_finishes_at',
                'maintenance_next_due_at', 'maintenance_unpaid_since',
            ]);
        });
    }
};
