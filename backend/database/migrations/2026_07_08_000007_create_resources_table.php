<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('colony_id')->constrained()->cascadeOnDelete();
            $table->string('resource_type', 40);
            $table->foreign('resource_type')->references('code')->on('resource_types');

            // Unidades inteiras. Energia é estoque (custo de construção, GDD §4.2) e também
            // fluxo (consumo operacional por hora, §19.4). O estoque mora aqui; a taxa por
            // nível de construção mora em building_specs.energia_consumo_hora.
            $table->unsignedBigInteger('amount')->default(0);
            $table->unsignedBigInteger('storage_cap');

            $table->timestamps();
            $table->unique(['colony_id', 'resource_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resources');
    }
};
