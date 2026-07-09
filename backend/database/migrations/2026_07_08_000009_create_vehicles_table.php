<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * GDD (precedência, seção 0): a Central de Transportes "libera vagas de frota;
     * veículo é fabricado ou adquirido separadamente". O nível da Central limita a
     * CONTAGEM de linhas aqui — ela não cria veículo.
     *
     * GDD §25.4: 1.000 unidades = 1 m³. Furgão 6 m³ = 6.000 un. Caminhão 30 m³ = 30.000 un.
     * GDD §25.5: veículo em rota fica indisponível até completar a viagem.
     */
    public function up(): void
    {
        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('colony_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['furgao_comercio', 'caminhao_carga']);
            $table->unsignedTinyInteger('level')->default(1);
            $table->enum('status', ['ocioso', 'carregando', 'em_rota', 'descarregando'])->default('ocioso');

            $table->unsignedBigInteger('capacity');

            // Destino polimórfico: outra colônia, Mercado Central, federação ou zona neutra.
            $table->string('destination_type', 20)->nullable();
            $table->unsignedBigInteger('destination_id')->nullable();

            $table->dateTime('departs_at')->nullable();
            $table->dateTime('arrives_at')->nullable();
            $table->json('cargo_json')->nullable();

            $table->timestamps();
            $table->index(['status', 'arrives_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicles');
    }
};
