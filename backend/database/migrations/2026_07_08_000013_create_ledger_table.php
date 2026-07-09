<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * GDD (precedência, seção 0, "Arquitetura de dados"): "Fonte transacional única,
     * ledger APPEND-ONLY e separação lógica/operacional."
     *
     * Append-only de verdade: sem updated_at, sem deleted_at. Estorno é um novo
     * lançamento de sinal contrário, nunca UPDATE nem DELETE. Quem for escrever o Model
     * precisa desabilitar timestamps e bloquear update/delete.
     */
    public function up(): void
    {
        Schema::create('ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('colony_id')->constrained()->cascadeOnDelete();

            $table->enum('type', [
                'producao',
                'custo_construcao',
                'subsidio_governo',   // §24.7: 100% das cinco essenciais até o nível 3
                'tributo',
                'venda_mercado',
                'compra_mercado',
                'transferencia',
                'estorno',
            ]);

            // Sinal importa: positivo credita, negativo debita.
            $table->bigInteger('amount');

            // Nulo quando o lançamento é em Fert$ (micro-unidades), não em recurso.
            $table->string('resource_type', 40)->nullable();
            $table->foreign('resource_type')->references('code')->on('resource_types');

            // Rastro para a origem: tax_events.id, build_queue.id, market_orders.id...
            $table->string('ref', 120)->nullable();

            $table->dateTime('created_at')->useCurrent();

            $table->index(['colony_id', 'created_at']);
            $table->index('ref');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger');
    }
};
