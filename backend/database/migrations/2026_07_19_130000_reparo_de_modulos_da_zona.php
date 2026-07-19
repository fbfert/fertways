<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O "Módulo Operacional" (D-66) ganha as duas metades que faltavam (revisão, D-118).
 *
 * `modules_offline` já existia (a Apreensão do Predador, binária) mas nunca era lido por ninguém
 * além do próprio ataque e da UI — nem o prazo de 24h do §28.10 (`Combat::RESGATE_HORAS`) era
 * consultado para restaurar o módulo sozinho. E a Sabotagem do Infiltrador, que o GDD descreve
 * como "perde capacidade PROPORCIONAL ao nível do Infiltrador" (diferente do desligamento total da
 * Apreensão), caía no mesmo `modules_offline` binário.
 *
 * Duas colunas novas, aditivas — `modules_offline` continua exatamente como era:
 * - `modules_offline_expira_em`: mapa `{estrutura: timestamp}` — quando cada Apreensão em
 *   `modules_offline` expira sozinha (24h). Lido pelo tick novo, `ExpirarApreensoes`.
 * - `structures_saboted`: mapa `{estrutura: nível do Infiltrador}` — a Sabotagem, que não desliga
 *   nada, só reduz a capacidade da estrutura na proporção do nível de quem sabotou, até reparo
 *   ativo do dono (`RepararModulo`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('neutral_zones', function (Blueprint $t) {
            if (! Schema::hasColumn('neutral_zones', 'modules_offline_expira_em')) {
                $t->json('modules_offline_expira_em')->nullable()->after('modules_offline');
            }
            if (! Schema::hasColumn('neutral_zones', 'structures_saboted')) {
                $t->json('structures_saboted')->nullable()->after('modules_offline_expira_em');
            }
        });

        /*
         * O custo do reparo/resgate (`RepararModulo`) — mesmo padrão do `manutencao_bps_do_custo`
         * do Transporte (D-60): fração do custo de CONSTRUÇÃO da estrutura, não número novo. 10%
         * por padrão, parâmetro do operador (painel da Guerra), porque o §28.10 não publica custo
         * nenhum para "reparar ou pagar o resgate".
         */
        Schema::table('war_settings', function (Blueprint $t) {
            if (! Schema::hasColumn('war_settings', 'reparo_bps_do_custo')) {
                $t->unsignedInteger('reparo_bps_do_custo')->default(1_000);
            }
        });
    }

    public function down(): void
    {
        Schema::table('neutral_zones', function (Blueprint $t) {
            foreach (['modules_offline_expira_em', 'structures_saboted'] as $c) {
                if (Schema::hasColumn('neutral_zones', $c)) {
                    $t->dropColumn($c);
                }
            }
        });

        Schema::table('war_settings', function (Blueprint $t) {
            if (Schema::hasColumn('war_settings', 'reparo_bps_do_custo')) {
                $t->dropColumn('reparo_bps_do_custo');
            }
        });
    }
};
