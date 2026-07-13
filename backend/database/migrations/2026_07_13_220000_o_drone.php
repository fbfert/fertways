<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O Drone de Exploração e a névoa que lhe dá ofício (docs/decisoes.md D-74).
 *
 * O D-37 abriu o diretório de colônias sem névoa e deixou anotado: "se um dia a névoa entrar, este
 * é o ponto". Ela entrou aqui — **só no interior das zonas alheias** (guarnição e depósito), que é
 * o mínimo que dá ao Drone um papel de verdade: com a guerra do D-66/D-70 no ar, quem quer saber a
 * força de defesa antes de gastar Sentinelas manda o olheiro primeiro.
 *
 * Esta tabela é o que o olheiro trouxe: **a foto datada** de cada zona que um drone da colônia já
 * viu. Uma linha por (colônia, zona), sempre sobrescrita pela passagem mais recente — intel velha
 * não vira histórico, vira engano; o que importa é O QUE se viu e QUANDO.
 *
 * ⚠️ **A missão do drone NÃO tem tabela própria, de propósito.** Ela vive nas colunas de viagem que
 * o veículo já tem (`leg` ida → vigia → volta, `trip_purpose` = o modo, `destination_id` = a zona),
 * porque criar uma segunda máquina de viagem seria criar o segundo lugar onde ela quebra. E o
 * `vehicles.status` é ENUM no MariaDB — o voo inteiro cabe em `em_rota` sem tocar no DDL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drone_sightings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('colony_id')->constrained('colonies')->cascadeOnDelete();
            $table->foreignId('zone_id')->constrained('neutral_zones')->cascadeOnDelete();
            // O que se viu: os dois únicos segredos do interior (o resto do payload da zona é
            // derivável do nível, que é público).
            $table->unsignedInteger('garrison');
            $table->unsignedBigInteger('deposit_amount');
            // Quando se viu. É a idade da foto que a tela mostra ("visto há 3 h").
            $table->timestamp('seen_at');
            $table->timestamps();

            $table->unique(['colony_id', 'zone_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drone_sightings');
    }
};
