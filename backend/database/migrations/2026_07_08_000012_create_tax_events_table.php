<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Uma incidência por fato econômico/lote" (GDD, precedência da seção 0; §25.2).
     *
     * Isso é invariante de DADOS, não regra de aplicação: sem a chave única abaixo, um
     * retry de request ou dois ticks concorrentes tributariam o mesmo lote duas vezes.
     * O banco é o que garante, não o código.
     *
     * O GDD se contradiz sobre o momento da incidência — §8.2/§8.3 dizem "no envio/na
     * saída", §25.2 diz "na entrega". Decisão do usuário em 2026-07-08: vale §25.2.
     * Logo a economic_event_key deriva do evento de CHEGADA do veículo. Viagem cancelada
     * ou veículo perdido antes da entrega não gera lançamento algum.
     */
    public function up(): void
    {
        Schema::create('tax_events', function (Blueprint $table) {
            $table->id();
            $table->string('economic_event_key', 191)->unique();
            $table->enum('kind', ['transporte_entrega', 'mercado_venda']);
            $table->foreignId('colony_id')->constrained()->cascadeOnDelete();

            $table->string('resource_type', 40)->nullable();
            $table->foreign('resource_type')->references('code')->on('resource_types');

            // Volume tributado (unidades) ou valor em micro-Fert$, conforme `kind`.
            $table->unsignedBigInteger('base_amount');
            $table->unsignedSmallInteger('tax_bps');
            $table->unsignedBigInteger('tax_amount');

            $table->dateTime('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_events');
    }
};
