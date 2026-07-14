<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * As três últimas estruturas do §17.4, custeadas como inertes (docs/decisoes.md D-79).
 *
 * O D-67 tinha deixado Estrutura de Extração, Central de Comunicação e Plataforma de Pouso (da zona)
 * FORA de escopo: nenhuma tem função possível hoje, porque o que o GDD promete para elas depende de
 * um sistema que não existe (a extração territorial já funciona sem ferramenta própria; Federação;
 * Nave de Transporte Planetária). O usuário reabriu a decisão de propósito: quer poder ERGUÊ-las,
 * mesmo inertes, como sempre foi o caso do Cemitério de Robôs.
 *
 * Idempotente, como as demais (lição do D-59).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('neutral_zones', function (Blueprint $t) {
            foreach (['extraction_level', 'communication_level', 'landing_pad_level'] as $c) {
                if (! Schema::hasColumn('neutral_zones', $c)) {
                    $t->unsignedTinyInteger($c)->default(0)->after('cemetery_level');
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('neutral_zones', function (Blueprint $t) {
            foreach (['extraction_level', 'communication_level', 'landing_pad_level'] as $c) {
                if (Schema::hasColumn('neutral_zones', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
    }
};
