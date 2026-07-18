<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Manutenção de estruturas (docs/decisoes.md D-112): quanto de cada recurso primário/industrial
 * uma construção consome por hora — generalização do que hoje só existe pra energia
 * (`building_specs.energia_consumo_hora`, GDD).
 *
 * **Aditiva, não substitui a energia.** `energia_consumo_hora` continua exatamente como está —
 * é GDD, é balanceamento comprovado, e mudá-lo é risco que ninguém pediu para correr. Esta tabela
 * é um consumo A MAIS, por cima, que o admin escolhe ligar — vazia por padrão, então nenhuma
 * construção existente passa a consumir nada até o admin configurar.
 *
 * **Por TIPO, não por nível** — diferente do custo/tempo (`building_specs_overrides`, que é por
 * `(tipo, nível)`). O usuário não pediu granularidade por nível, e uma grade de 38 construções ×
 * até 10 níveis × 17 recursos não caberia numa tela administrável. Uma taxa só por tipo, mesma
 * simplicidade do pedido original ("quanto consome por hora").
 *
 * Mesmo molde de `building_specs_overrides` (D-107/108): tabela separada, nunca tocada por
 * Seeder, vazia até o admin mexer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manutencao_estruturas', function (Blueprint $table) {
            $table->id();
            $table->string('building_type', 40);
            $table->string('resource_type', 40);
            $table->unsignedInteger('qtd_hora');
            $table->foreignId('admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();

            $table->unique(['building_type', 'resource_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manutencao_estruturas');
    }
};
