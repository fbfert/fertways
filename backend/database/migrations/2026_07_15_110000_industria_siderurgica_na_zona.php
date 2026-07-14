<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A Indústria Siderúrgica na zona neutra (docs/decisoes.md D-82) — construção nova, não está no
 * GDD. Só processa em zonas de Metal Bruto (distrito Nordeste), disputando o mesmo `deposit_amount`
 * que a Refinaria de Campo já consome — quem chegar primeiro no tick leva (decisão do usuário).
 *
 * `industry_level` e `last_industry_at` seguem o mesmo padrão de `refinery_level`/`last_refine_at`
 * (D-67): um relógio próprio, que só avança pelo tempo que efetivamente converteu.
 *
 * NASCE `zone_minerals` — o depósito só tinha lugar para DOIS recursos (o bruto extraído e o
 * refinado da Refinaria). Os cinco minerais eletrônicos da receita (D-82) precisam de um lugar
 * novo. Ligas Metálicas CONTINUA indo para `refined_amount` — é o mesmo recurso que a Refinaria de
 * Campo já produz ali, e as duas construções somam no mesmo total. Conta no MESMO teto de
 * capacidade de tudo o mais na zona (decisão do usuário) — `Protegido` soma esta tabela junto.
 *
 * Idempotente, como as demais (lição do D-59).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('neutral_zones', function (Blueprint $t) {
            foreach (['industry_level' => 'unsignedTinyInteger'] as $c => $tipo) {
                if (! Schema::hasColumn('neutral_zones', $c)) {
                    $t->$tipo($c)->default(0)->after('cemetery_level');
                }
            }

            if (! Schema::hasColumn('neutral_zones', 'last_industry_at')) {
                $t->timestamp('last_industry_at')->nullable()->after('last_refine_at');
            }
        });

        if (! Schema::hasTable('zone_minerals')) {
            Schema::create('zone_minerals', function (Blueprint $t) {
                $t->id();
                $t->foreignId('zone_id')->constrained('neutral_zones')->cascadeOnDelete();
                $t->string('resource_type', 32);
                $t->unsignedBigInteger('amount')->default(0);
                $t->timestamps();

                // Um mineral, uma linha, por zona — mesmo padrão de `zone_materials`.
                $t->unique(['zone_id', 'resource_type']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('zone_minerals');

        Schema::table('neutral_zones', function (Blueprint $t) {
            foreach (['industry_level', 'last_industry_at'] as $c) {
                if (Schema::hasColumn('neutral_zones', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
    }
};
