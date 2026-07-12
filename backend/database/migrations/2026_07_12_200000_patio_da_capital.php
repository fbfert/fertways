<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O Pátio Logístico da Capital, e a viagem com pernas independentes (D-65).
 *
 * Duas mudanças no veículo, e as duas nascem da mesma decisão do usuário: **o veículo que entrega
 * no depósito da Capital fica estacionado lá**, em vez de voltar sozinho.
 *
 *  - `local` diz onde o veículo está quando está parado. Até aqui, "onde" não existia como dado:
 *    `colony_id` era dono **e** lugar, e o veículo sempre voltava para casa. Agora ele pode estar
 *    no Pátio (§2.1, slot 6 — "Estacionamento de Caminhões, 20 vagas"), e é de lá que ele parte.
 *
 *  - `return_distance_slots` é a distância da **volta**, que deixou de ser igual à da ida. Um
 *    caminhão do Pátio entrega num colono e **segue para casa**: Capital → colônia do outro →
 *    a sua. São três pontos e duas distâncias, e `distance_slots` sozinha não sabe dizer isso.
 *    **Nulo passa a significar "viagem só de ida"** — o veículo termina no destino e fica lá. É
 *    esse nulo que faz o depósito estacionar o veículo, sem nenhum outro sinalizador.
 *
 * `parked_at` e `patio_cobrado_ate` são a cobrança do estacionamento: 0,005 Fert$ por hora parada,
 * decidida pelo usuário. O GDD publica as 20 vagas e a "cobrança por hora", mas **nunca o preço** —
 * o D-63 tinha deixado a tarifa como lacuna aberta, e é ela que fecha aqui. As vagas, essas, ficam
 * sem limite: o usuário decidiu que o Pátio não recusa ninguém.
 *
 * Colunas aditivas, com default. Nenhum backfill: veículo que existe hoje está em casa, que é o
 * default — e nenhum deles está em rota com volta diferente da ida, porque isso não existia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->string('local', 10)->default('colonia')->after('status');
            $table->timestamp('parked_at')->nullable()->after('arrives_at');
            $table->timestamp('patio_cobrado_ate')->nullable()->after('parked_at');
            $table->unsignedSmallInteger('return_distance_slots')->nullable()->after('distance_slots');
        });

        // Quem está parado no Pátio é varrido a cada tick pela cobrança. Sem índice, isso é um
        // full scan da tabela de veículos a cada minuto.
        Schema::table('vehicles', function (Blueprint $table) {
            $table->index(['local', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropIndex(['local', 'status']);
            $table->dropColumn(['local', 'parked_at', 'patio_cobrado_ate', 'return_distance_slots']);
        });
    }
};
