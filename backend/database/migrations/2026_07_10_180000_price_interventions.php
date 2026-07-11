<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Secretaria de Finanças e Tesouro (slot 4) — intervenção de preço no Mercado (§06).
 *
 * O GDD diz que "a Secretaria só altera teto, piso, oferta governamental ou taxa mediante registro
 * público de motivo, período e impacto esperado", e que a intervenção "tem prazo de expiração e
 * métrica de saída". Mas **nunca publica a largura da faixa de segurança** (D-35) — por isso o MVP
 * ficou sem teto e sem piso. Decisão do usuário (2026-07-10): nada de faixa fixa no código; o
 * operador (governo/equipe) declara cada intervenção com os números dele. Esta tabela é o "registro
 * público": recurso, teto e/ou piso em micro-Fert$, motivo e janela de vigência.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_interventions', function (Blueprint $table) {
            $table->id();

            $table->string('resource_type', 40);
            $table->foreign('resource_type')->references('code')->on('resource_types');

            // Teto e piso em micro-Fert$. Qualquer um pode ser nulo: dá para pôr só teto ou só piso.
            $table->unsignedBigInteger('floor_micro')->nullable();
            $table->unsignedBigInteger('ceil_micro')->nullable();

            $table->string('reason', 255);
            $table->dateTime('starts_at');
            $table->dateTime('expires_at');
            $table->dateTime('created_at')->useCurrent();

            // A busca quente é "há intervenção vigente para este recurso?".
            $table->index(['resource_type', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_interventions');
    }
};
