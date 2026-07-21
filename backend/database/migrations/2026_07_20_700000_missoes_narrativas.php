<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Missões narrativas (§02/§16.2, docs/decisoes.md D-140) — a categoria que o D-78 deixou de fora
 * de propósito ("Narrativa (...) esperam os seus sistemas"). `Endurance.tsx` admitia, verbatim:
 * "As missões narrativas continuam sem existir; esta tela não finge o contrário."
 *
 * Uma coluna só: `requer_template_id`, auto-referente. É o que falta no motor genérico (D-78) para
 * encadear capítulos — as diárias/semanais sorteiam do pool sem ordem; a narrativa precisa que o
 * capítulo N+1 só apareça depois do N concluído. Sem índice: a tabela é pequena (o catálogo inteiro
 * de missões, não uma tabela de jogo por tick) e a consulta é sempre por `id`, já com PK.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mission_templates', function (Blueprint $table) {
            $table->foreignId('requer_template_id')->nullable()->after('categoria')
                ->constrained('mission_templates')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('mission_templates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('requer_template_id');
        });
    }
};
