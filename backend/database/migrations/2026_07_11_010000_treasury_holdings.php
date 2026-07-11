<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ministério do Tesouro de Fertways — a reserva do governo (D-57).
 *
 * Um caixa REAL e mutável: começa com uma dotação (10 mil de cada recurso + 1.000.000 Fert$, semeados
 * pelo TreasurySeeder), o tributo cobrado passa a ENTRAR nele (unifica o Tesouro do D-55, que era só
 * uma view derivada de `tax_events`), e o admin redistribui a partir dele. É o "coleta e redistribuição
 * de impostos" do §2.1. Os colonos veem o saldo na Capital; só o admin move.
 *
 * Uma linha por recurso (`resource_type` = code do catálogo, `amount` em unidades) mais uma linha de
 * Fert$ (`resource_type` = '__fert__', `amount` em micro-Fert$). Sem FK por causa dessa sentinela.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('treasury_holdings', function (Blueprint $table) {
            $table->string('resource_type', 40)->primary();
            $table->unsignedBigInteger('amount')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('treasury_holdings');
    }
};
