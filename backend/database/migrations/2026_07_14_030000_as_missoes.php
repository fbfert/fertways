<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * As Missões do §06 (docs/decisoes.md D-78).
 *
 * O GDD publica os ciclos — Tutoria (5, dias 1–3), Diária (3/dia de um pool de 30+, 1 rejeição),
 * Semanal (qua 07h → ter 23h59) — e as classes de recompensa (Fert$, recursos, XP), e cala os
 * valores. Arbitragens do usuário (2026-07-13): escopo = os três ciclos (Guerra entra como tipo no
 * pool; Narrativa/Federação/Evento/Conquistas esperam os seus sistemas); recompensas GENEROSAS
 * (2× a proposta modesta — risco de inflação anotado: recompensa de missão é EMISSÃO, §06);
 * a tutoria RECOMPENSA mas não trava o subsídio (contradição consciente com o §03, que diz
 * "mediante conclusão da tutoria" — o stub do D-18 sobrevive, agora documentado como decisão).
 *
 *   mission_templates     o catálogo (seeder): o que se pede, quantas vezes, o que paga.
 *   mission_assignments   a missão NA MÃO de uma colônia: progresso, prazo, estado.
 *
 * ⚠️ Todo TIMESTAMP não-nulo tem `useCurrent()`/default explícito — a lição do deploy quebrado do
 * D-77 (o MariaDB recusa o segundo TIMESTAMP sem default; o SQLite dos testes engole).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mission_templates', function (Blueprint $table) {
            $table->id();
            $table->string('chave', 40)->unique();
            $table->string('categoria', 10);   // tutoria | diaria | semanal
            $table->string('titulo', 80);
            $table->string('descricao', 200);
            // A ação rastreada (a mesma língua dos ganchos de XP: obra_concluida, despacho…).
            $table->string('acao', 30);
            $table->unsignedInteger('meta')->default(1);
            $table->unsignedBigInteger('recompensa_fert_micro')->default(0);
            $table->unsignedInteger('recompensa_xp')->default(0);
            $table->json('recompensa_recursos')->nullable();
            // O liga/desliga do operador: um template com defeito sai do baralho sem deploy.
            $table->boolean('ativa')->default(true);
            $table->timestamps();

            $table->index(['categoria', 'ativa']);
        });

        Schema::create('mission_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('colony_id')->constrained('colonies')->cascadeOnDelete();
            $table->foreignId('template_id')->constrained('mission_templates')->cascadeOnDelete();
            $table->string('categoria', 10);
            $table->string('acao', 30);
            $table->unsignedInteger('progresso')->default(0);
            $table->unsignedInteger('meta');
            $table->string('status', 12)->default('ativa');   // ativa | concluida | rejeitada | expirada
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('concluded_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            // O caminho quente: o Progresso pergunta "há missão ativa desta ação nesta colônia?"
            // a cada ato do jogo — e quase sempre a resposta é não. Barato só com este índice.
            $table->index(['colony_id', 'status', 'acao']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mission_assignments');
        Schema::dropIfExists('mission_templates');
    }
};
