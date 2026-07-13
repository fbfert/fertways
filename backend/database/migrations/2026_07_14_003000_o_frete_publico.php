<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O serviço logístico público do §07 (docs/decisoes.md D-76).
 *
 * "O comprador agenda retirada com veículo próprio **ou paga serviço logístico público**" — a única
 * frase operativa do GDD sobre o assunto, sem preço, prazo nem veículo. As arbitragens do usuário
 * (2026-07-13): uma **Garagem do Governo** com 10 caminhões REAIS (expansível pelo painel), frete a
 * **1 F$ + 0,02 F$/slot** (no painel do operador), escopo = só a doca do Mercado Central.
 *
 * A Garagem NÃO tem tabela: caminhão de garagem é um `vehicles` com `colony_id` nulo, `status`
 * ocioso e `local` capital — distinto da prateleira de VENDA, que é `status` estoque. O frete vive
 * nas colunas de viagem do próprio veículo, como tudo o mais desde o D-30.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transport_settings', function (Blueprint $table) {
            // 1 F$ a bandeirada…
            $table->unsignedBigInteger('frete_base_micro')->default(1_000_000);
            // …mais 0,02 F$ por slot de distância até a colônia. Defaults no BANCO, como sempre.
            $table->unsignedBigInteger('frete_por_slot_micro')->default(20_000);
        });
    }

    public function down(): void
    {
        Schema::table('transport_settings', function (Blueprint $table) {
            $table->dropColumn(['frete_base_micro', 'frete_por_slot_micro']);
        });
    }
};
