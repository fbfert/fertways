<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * O Espaçoporto e os cinco planetas NPC (GDD v35 §03, §05, §16.2, §21.10; D-262).
 *
 * ## O que é do GDD, e o que é do operador
 *
 * Os **planetas** são do GDD — nome, distância, risco, o que exportam e importam (§03) — e entram aqui
 * mesmo, nesta migration: o `deploy.sh` não roda seeder, e um Espaçoporto sem planetas não teria o que
 * mostrar. Todos nascem **fechados**.
 *
 * Os **números de economia** não são do GDD, e nenhum é inventado: preço de cada recurso em cada
 * planeta, aluguel do Cargueiro, capacidade, tamanho da frota. Nascem vazios, e vazio quer dizer
 * fechado — o mesmo desenho da escavação (D-249). O operador os escreve no painel.
 */
return new class extends Migration
{
    /** §03 (v3.0), "Espaçoporto e Planetas NPC". A distância é a do GDD, em minutos. */
    private const PLANETAS = [
        ['kalidor', 'Kalidor', 240, 'nenhum', 'Metais pesados, compostos químicos', 'Biomassa, água'],
        ['veyra', 'Veyra', 390, 'nenhum', 'Sementes alienígenas, compostos orgânicos', 'Energia, componentes'],
        ['auryn', 'Auryn', 720, 'baixo', 'Variedade enorme', 'Qualquer recurso'],
        ['solene', 'Solène', 1080, 'nenhum', 'Tecnologia, dados de pesquisa', 'Recursos raros'],
        ['drakmoor', 'Drakmoor', 1860, 'alto', 'Minerais raros exclusivos', 'Biomassa, água, componentes'],
    ];

    public function up(): void
    {
        Schema::create('planetas_npc', function (Blueprint $table) {
            $table->id();
            $table->string('chave', 30)->unique();
            $table->string('nome', 60);
            // "A duração final é parametrizada por servidor" (v3.2 §11): o número do GDD é a partida.
            $table->unsignedInteger('distancia_minutos');
            $table->string('risco', 10);
            $table->string('exporta', 200);
            $table->string('importa', 200);
            // Os portões por planeta (Auryn "por marco de servidor", Solène "por ciência", Drakmoor
            // "tardio") não têm critério publicado: abrir é ato do operador.
            $table->boolean('aberto')->default(false);
            $table->unsignedTinyInteger('ordem');
            $table->timestamps();
        });

        Schema::create('planeta_npc_precos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('planeta_npc_id')->constrained('planetas_npc')->cascadeOnDelete();
            $table->string('resource_type', 40);
            // O que o planeta PAGA por unidade que o colono leva; nulo = não compra.
            $table->unsignedBigInteger('compra_micro')->nullable();
            // O que o planeta COBRA por unidade que o colono traz; nulo = não vende.
            $table->unsignedBigInteger('venda_micro')->nullable();
            $table->timestamps();

            $table->unique(['planeta_npc_id', 'resource_type']);
        });

        Schema::create('espacoporto_settings', function (Blueprint $table) {
            $table->id();
            // A 5ª atribuição do Ministério dos Transportes (§16): "disponibilidade para aluguel".
            $table->boolean('ativo')->default(false);
            $table->unsignedBigInteger('aluguel_micro')->nullable();
            $table->unsignedInteger('capacidade_unidades')->nullable();
            // A frota do Governo, operador exclusivo (§16.2): contratos simultâneos, no máximo.
            $table->unsignedSmallInteger('cargueiros')->nullable();
            $table->timestamps();
        });

        Schema::create('contratos_de_rota', function (Blueprint $table) {
            $table->id();
            $table->foreignId('colony_id')->constrained('colonies')->cascadeOnDelete();
            $table->foreignId('planeta_npc_id')->constrained('planetas_npc');
            $table->string('status', 20)->default('em_viagem');
            // As duas pernas, com o preço TRAVADO na partida: {recurso: {qtd, preco_micro}}.
            $table->json('venda');
            $table->json('compra');
            $table->unsignedBigInteger('aluguel_micro');
            $table->dateTime('parte_em');
            $table->dateTime('chega_em');
            $table->dateTime('concluido_em')->nullable();
            $table->timestamps();

            $table->index(['status', 'chega_em']);
        });

        DB::table('espacoporto_settings')->insert(['id' => 1, 'created_at' => now(), 'updated_at' => now()]);

        foreach (self::PLANETAS as $ordem => [$chave, $nome, $minutos, $risco, $exporta, $importa]) {
            DB::table('planetas_npc')->insert([
                'chave' => $chave, 'nome' => $nome, 'distancia_minutos' => $minutos, 'risco' => $risco,
                'exporta' => $exporta, 'importa' => $importa, 'aberto' => false, 'ordem' => $ordem + 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (DB::table('contratos_de_rota')->where('status', 'em_viagem')->exists()) {
            throw new RuntimeException('Há cargueiro em viagem. Espere os contratos voltarem antes de desfazer.');
        }

        Schema::dropIfExists('contratos_de_rota');
        Schema::dropIfExists('espacoporto_settings');
        Schema::dropIfExists('planeta_npc_precos');
        Schema::dropIfExists('planetas_npc');
    }
};
