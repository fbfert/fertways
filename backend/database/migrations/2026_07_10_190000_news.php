<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Central de Pesquisas e Notícias (slot 3) — mural de comunicados oficiais.
 *
 * O Gagarin (auto-boletins do §12.1) só ativa com 50 jogadores ou 45 dias, e o GDD não publica o
 * formato do boletim. Enquanto isso, o mural é editorial e **operado pela equipe** — a regra do §02:
 * "'Governo' e 'Administração' são sistemas operados pela equipe". Decisão do usuário (2026-07-10):
 * comunicados publicados à mão via `artisan fertways:noticia`, sem inventar conteúdo automático.
 * As notícias são globais (do servidor), não de uma colônia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('news', function (Blueprint $table) {
            $table->id();
            $table->string('title', 140);
            $table->text('body');
            // 'comunicado' oficial da equipe hoje; 'boletim' fica reservado para quando o Gagarin ativar.
            $table->enum('kind', ['comunicado', 'boletim'])->default('comunicado');
            $table->string('author', 60)->default('Administração Pública');
            $table->dateTime('published_at')->useCurrent();
            $table->dateTime('created_at')->useCurrent();

            $table->index('published_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('news');
    }
};
