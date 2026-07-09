<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabela-fonte das tabelas do GDD §4.2/§4.3. Semeada verbatim, imutável em runtime.
     *
     * Custo É reproduzível por half-up(base * 1.65^(nivel-1)) — confere em 9/9 séries.
     * Tempo NÃO é: o Gerador de Atmosfera tabela 4,5,8,12,18 e a curva 1,50× daria
     * 4,6,9,14,20; o Reator (7,10,16,24,35) não sai de base alguma. Portanto o tempo
     * vem da tabela, nunca de fórmula. A fórmula de custo vive só como teste de
     * propriedade em tests/Gdd/, validando este seed contra o HTML do GDD.
     */
    public function up(): void
    {
        Schema::create('building_specs', function (Blueprint $table) {
            $table->id();
            $table->string('building_type', 40);
            $table->unsignedTinyInteger('level');

            // Verbatim do GDD. Nunca calculado.
            // Anulável porque o GDD simplesmente NÃO define tempo de construção para
            // Central de Transportes, Destilaria, Depósito de Zona Neutra nem para os
            // veículos — nem em §4.2/§4.3, nem no corpo v3.0. Ver docs/decisoes.md D-10.
            // NULL significa "o GDD não diz", não "instantâneo": enfileirar deve falhar.
            $table->unsignedInteger('build_time_seconds')->nullable();

            // {"agua":50,"biomassa":30,"energia":10,"oxigenio":5}
            $table->json('cost_json');

            // Consumo operacional por hora (GDD §19.4, curva 1,50×).
            // Não é custo de upgrade (GDD §4.1): são coisas distintas e curvas distintas.
            $table->unsignedInteger('energia_consumo_hora')->default(0);

            // Produção por hora deste nível, quando a construção produz. {"agua":405}
            $table->json('producao_hora_json')->nullable();

            $table->unique(['building_type', 'level']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('building_specs');
    }
};
