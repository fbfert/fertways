<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A Indústria Siderúrgica na colônia (docs/decisoes.md D-82) — construção nova, não está no GDD.
 *
 * Processa Metal Bruto em Ligas Metálicas e nos cinco minerais eletrônicos que, na Temporada 1,
 * só o governo extrai (§4.3). Arbitragem consciente do usuário.
 *
 * `siderurgica_lote_remainder`: o excedente de Metal Bruto processado que ainda não fechou um
 * lote de 1000 (a receita tem seis saídas simultâneas — só se credita em lotes inteiros, ou
 * alguma saída ficaria sem unidade pra dar). Guarda progresso, não fração de recurso.
 *
 * Idempotente, como as demais (lição do D-59).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('colonies', function (Blueprint $t) {
            if (! Schema::hasColumn('colonies', 'siderurgica_lote_remainder')) {
                $t->unsignedInteger('siderurgica_lote_remainder')->default(0)->after('last_tick_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('colonies', function (Blueprint $t) {
            if (Schema::hasColumn('colonies', 'siderurgica_lote_remainder')) {
                $t->dropColumn('siderurgica_lote_remainder');
            }
        });
    }
};
