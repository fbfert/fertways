<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O admin passa a poder ajustar tempo e custo de construção por nível (pedido do usuário,
 * docs/decisoes.md D-107 — "Gestão de Construções").
 *
 * `building_specs` continua sendo a base do GDD, semeada por `BuildingSpecSeeder` — que roda de
 * novo toda vez que alguém chama `db:seed` (e isso acontece: foi preciso rodar manualmente ao
 * lançar o Depósito Local, D-106). Se o admin editasse `building_specs` direto, o próximo reseed
 * apagaria a edição sem avisar. Por isso esta tabela é SEPARADA e vazia — nada além do admin
 * grava nela, nunca um Seeder — e `BuildingSpecs::para()` aplica o override por cima da base
 * quando existir. Mesmo problema que o kit inicial já resolveu (D-92), mesma solução: uma tabela
 * que só o admin toca.
 *
 * `build_time_seconds`/`cost_json` nullable **de propósito**: o admin edita tempo e custo em
 * telas separadas, então uma linha pode ter só um dos dois preenchido.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('building_specs_overrides', function (Blueprint $table) {
            $table->id();
            $table->string('building_type', 40);
            $table->unsignedTinyInteger('level');
            $table->unsignedInteger('build_time_seconds')->nullable();
            $table->json('cost_json')->nullable();
            $table->foreignId('admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();

            $table->unique(['building_type', 'level']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('building_specs_overrides');
    }
};
