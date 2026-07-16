<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O extrato do Governo (D-96): até aqui, `Tesouro` só guardava o SALDO corrente
 * (`treasury_holdings`) — nenhuma operação ficava registrada, ao contrário de toda colônia, que
 * tem o `ledger` (append-only, "a regra de ouro" — recurso não nasce sem história).
 *
 * Uma tabela nova, não uma coluna nullable em `ledger`: `ledger.colony_id` é `NOT NULL` e
 * `cascadeOnDelete()` numa tabela crítica e já em produção — alterar essa constraint ao vivo é
 * um risco maior do que o problema pede. O Tesouro é um singleton (como `treasury_holdings` e
 * `kit_inicial_settings` já são) — não precisa de FK nenhuma, só do próprio histórico.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('treasury_ledger', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20); // credito | debito | distribuicao
            $table->bigInteger('amount'); // sinal: positivo entra, negativo sai
            $table->string('resource_type')->nullable(); // null = Fert$, como no ledger da colônia
            $table->string('ref', 120)->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('treasury_ledger');
    }
};
