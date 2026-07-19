<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O desconto de tributo entre aliados (§04/§07; docs/decisoes.md D-114, D-120) — a última ponta que
 * o D-114 tinha deixado de fora da Fatia 1 de propósito: "a contribuição ao fundo é tributada
 * NORMALMENTE, deixando o terreno pronto para o desconto entrar depois".
 *
 * O GDD (v3.0) publica o número com todas as letras — "50% de desconto nos tributos entre aliadas"
 * — diferente do teto antimonopólio do D-119 (que o GDD nunca numera). Mesmo assim, fica no painel:
 * mesma tabela `federation_settings` do D-119, mesmo raciocínio de ajustável sem deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('federation_settings', function (Blueprint $t) {
            if (! Schema::hasColumn('federation_settings', 'desconto_tributo_aliados_bps')) {
                $t->unsignedInteger('desconto_tributo_aliados_bps')->default(5_000);
            }
        });
    }

    public function down(): void
    {
        Schema::table('federation_settings', function (Blueprint $t) {
            if (Schema::hasColumn('federation_settings', 'desconto_tributo_aliados_bps')) {
                $t->dropColumn('desconto_tributo_aliados_bps');
            }
        });
    }
};
