<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O preço de referência do Furgão — a âncora que fecha a lavagem de Fert$ (docs/decisoes.md D-73).
 *
 * O aditivo 14 do D-52 deixou o Furgão **sem teto de revenda de propósito**: o teto é `preço de
 * fábrica × conservação`, e o Furgão não tem preço de fábrica (o Ministério não o vende). O risco,
 * aceito na época de olhos abertos: um Furgão sucateado anunciado por 5.000 Fert$ move dinheiro
 * limpo entre duas contas do mesmo jogador, pelo escrow, sem carga e sem tributo.
 *
 * O usuário reviu a arbitragem em 2026-07-13: o Furgão ganha um **preço de referência do operador**
 * — não é preço de venda (o Ministério continua não o vendendo), é só a âncora do teto. 60 Fert$
 * é a proporção da capacidade: ele carrega 1/5 do Caminhão de 300 Fert$.
 *
 * ⚠️ O default mora no BANCO, como no `war_settings` (D-66): um banco sem seeder não pode deixar
 * o teto inerte em silêncio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transport_settings', function (Blueprint $table) {
            $table->unsignedBigInteger('furgao_preco_referencia_micro')->default(60_000_000);
        });
    }

    public function down(): void
    {
        Schema::table('transport_settings', function (Blueprint $table) {
            $table->dropColumn('furgao_preco_referencia_micro');
        });
    }
};
