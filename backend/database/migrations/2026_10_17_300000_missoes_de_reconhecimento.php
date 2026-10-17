<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * As Missões de Reconhecimento e o Telescópio Gagarin (aditivo v3.4 §2, D-282).
 *
 * O aditivo publica o pipeline inteiro: a ativação do Gagarin (50 jogadores ou 45 dias), o boletim a
 * cada 2 a 4 dias, uma missão planetária e uma lunar ativas no máximo, as fases e as durações, as oito
 * luas com o recurso e a primeira revelação de cada uma, as recompensas permitidas e as proibidas.
 *
 * O que ele **não** publica é o resultado de uma missão planetária: o planeta novo, os preços, o risco.
 * Ele manda que esse resultado seja "pré-configurado na versão da missão e assinado no log antes da
 * abertura da fase pública". É o operador quem o escreve, no painel, e o jogo o lacra com um hash.
 * Nenhum planeta nasce desta migration.
 *
 * Os números de partida que não são do aditivo (ele dá faixas ou nada) estão em `gagarin_settings`, no
 * painel:
 *
 * | número                     | partida | de onde                                                    |
 * |----------------------------|---------|------------------------------------------------------------|
 * | `boletim_intervalo_horas`  | 72      | o meio dos "2 a 4 dias"                                     |
 * | `tarefas_por_dia`          | 2       | "até duas tarefas diárias de pesquisa" (§2.2)               |
 * | `xp_por_tarefa`            | 25      | D-283: 100 fazia as 8 luas renderem 8.000 XP (o marco 20 é 6.000) |
 * | `meta_de_tarefas`          | 20      | meu: dez colonos com as duas tarefas de um dia              |
 * | `viagens_na_abertura`      | 1       | meu: o "limite de ordem" das primeiras 48 h (§2.2)          |
 */
return new class extends Migration
{
    /** As oito luas, na ordem do §2.4: recurso associado, primeira revelação e gancho. */
    private const LUAS = [
        ['armstrong', 'Armstrong', 'niobio_alienigena', 'Atmosfera e estabilidade de solo', 'Mapeamento de áreas de futura colonização.'],
        ['tereshkova', 'Tereshkova', 'cristal_de_helio_3', 'Ausência de atmosfera e assinatura cristalina', 'Risco de mineração hermética.'],
        ['sagan', 'Sagan', 'quartzo_piezoeletrico', 'Atmosfera tóxica e camada superficial', 'Terraformação lenta e formação de quartzo.'],
        ['aldrin', 'Aldrin', 'ferro_vermelho', 'Composição de ferro e relevo pesado', 'Rotas de mineração de grande escala.'],
        ['ride', 'Ride', 'resina_organica', 'Potencial de colonização', 'Bioassinaturas e materiais orgânicos estáveis.'],
        ['leonov', 'Leonov', 'gelo_de_metano', 'Atmosfera tóxica e bolsões de gelo', 'Propulsão de naves avançadas.'],
        ['hawking', 'Hawking', 'plasma_fossilizado', 'Camadas profundas e perturbação magnética', 'Local de alta disputa futura, sem combate lunar na Temporada 1.'],
        ['laika', 'Laika', 'fungo_bioluminescente', 'Sinal anômalo de origem desconhecida', 'Encadeia a Antena de Sinal Profundo e missões narrativas não competitivas.'],
    ];

    public function up(): void
    {
        Schema::create('gagarin_settings', function (Blueprint $table) {
            $table->id();
            // Nulo: a abertura do servidor é a fundação da primeira colônia.
            $table->timestamp('servidor_aberto_em')->nullable();
            $table->unsignedInteger('limiar_jogadores')->default(50);
            $table->unsignedInteger('limiar_dias')->default(45);
            $table->timestamp('ativado_em')->nullable();
            $table->unsignedInteger('boletim_intervalo_horas')->default(72);
            $table->timestamp('ultimo_boletim_em')->nullable();
            $table->unsignedInteger('boletins_desde_a_ultima_rota')->default(0);
            $table->unsignedTinyInteger('tarefas_por_dia')->default(2);
            $table->unsignedInteger('xp_por_tarefa')->default(25);
            $table->unsignedInteger('meta_de_tarefas')->default(20);
            $table->unsignedTinyInteger('viagens_na_abertura')->default(1);
            // Manutenção (§2.1): o painel pode pausar o relógio, nunca editar o resultado.
            $table->timestamp('pausado_em')->nullable();
            $table->timestamps();
        });

        Schema::create('luas', function (Blueprint $table) {
            $table->string('chave', 30)->primary();
            $table->string('nome', 40);
            $table->string('recurso', 40);
            $table->string('primeira_revelacao', 120);
            $table->string('gancho', 200);
            $table->unsignedTinyInteger('ordem');
            $table->timestamp('revelada_em')->nullable();
        });

        Schema::create('missoes_de_reconhecimento', function (Blueprint $table) {
            $table->id();
            $table->string('tipo', 12); // planetaria | lunar
            $table->string('alvo', 40); // a chave do planeta novo, ou da lua
            // O que a pista revela, e nada além: o setor e o tipo de anomalia (§2.2).
            $table->string('setor', 60);
            $table->string('anomalia', 20); // gravitacional | energetica | espectral
            $table->string('faixa', 10)->nullable(); // proxima | media | distante | remota
            $table->string('fase', 20);
            $table->timestamp('fase_desde');
            $table->timestamp('fase_ate')->nullable();
            $table->unsignedInteger('tarefas')->default(0);
            $table->unsignedInteger('meta_de_tarefas');
            // O resultado, lacrado na criação (§2.5): o JSON e o seu SHA-256, públicos no registro.
            $table->json('resultado');
            $table->char('assinatura', 64);
            $table->unsignedSmallInteger('versao')->default(1);
            $table->timestamp('pausada_em')->nullable();
            $table->timestamp('concluida_em')->nullable();
            $table->foreignId('admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();
            $table->index(['tipo', 'concluida_em']);
        });

        Schema::create('contribuicoes_de_reconhecimento', function (Blueprint $table) {
            $table->id();
            $table->foreignId('missao_id')->constrained('missoes_de_reconhecimento')->cascadeOnDelete();
            $table->foreignId('colony_id')->constrained()->cascadeOnDelete();
            $table->date('dia');
            $table->unsignedTinyInteger('tarefas')->default(0);
            $table->timestamps();
            $table->unique(['missao_id', 'colony_id', 'dia']);
            $table->index(['colony_id', 'dia']);
        });

        // O registro público de cada missão (§2.1, §2.5): criação com a assinatura, fases, pausas e
        // toda correção da equipe, com a justificativa.
        Schema::create('registro_de_reconhecimento', function (Blueprint $table) {
            $table->id();
            $table->foreignId('missao_id')->constrained('missoes_de_reconhecimento')->cascadeOnDelete();
            $table->string('evento', 30);
            $table->text('detalhe');
            $table->timestamp('created_at');
        });

        // As entradas privadas do Diário do Colono (§2.3, §2.5): recompensa narrativa, nunca recurso.
        Schema::create('diario_do_colono', function (Blueprint $table) {
            $table->id();
            $table->foreignId('colony_id')->constrained()->cascadeOnDelete();
            $table->foreignId('missao_id')->nullable()->constrained('missoes_de_reconhecimento')->nullOnDelete();
            $table->string('titulo', 120);
            $table->text('corpo');
            $table->timestamp('created_at');
            $table->index(['colony_id', 'created_at']);
        });

        Schema::table('planetas_npc', function (Blueprint $table) {
            // D-282: o planeta que uma missão de reconhecimento descobriu, e até quando vale o
            // limite de viagens da abertura (§2.2: "nas primeiras 48 h [...] limites de ordem").
            $table->foreignId('missao_id')->nullable()->constrained('missoes_de_reconhecimento')->nullOnDelete();
            $table->timestamp('abertura_ate')->nullable();
        });

        DB::table('gagarin_settings')->insert(['id' => 1, 'created_at' => now(), 'updated_at' => now()]);

        foreach (self::LUAS as $i => [$chave, $nome, $recurso, $revelacao, $gancho]) {
            DB::table('luas')->insert([
                'chave' => $chave, 'nome' => $nome, 'recurso' => $recurso,
                'primeira_revelacao' => $revelacao, 'gancho' => $gancho, 'ordem' => $i + 1,
            ]);
        }
    }

    public function down(): void
    {
        if (DB::table('planetas_npc')->whereNotNull('missao_id')->exists()) {
            throw new RuntimeException('Há planeta descoberto por reconhecimento. Desfazer apagaria uma rota em uso.');
        }

        Schema::table('planetas_npc', function (Blueprint $table) {
            $table->dropConstrainedForeignId('missao_id');
            $table->dropColumn('abertura_ate');
        });

        Schema::dropIfExists('diario_do_colono');
        Schema::dropIfExists('registro_de_reconhecimento');
        Schema::dropIfExists('contribuicoes_de_reconhecimento');
        Schema::dropIfExists('missoes_de_reconhecimento');
        Schema::dropIfExists('luas');
        Schema::dropIfExists('gagarin_settings');
    }
};
