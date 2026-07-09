<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Nickname: "único no servidor e obrigatório" (GDD, Identidade do colono).
            $table->string('nickname', 32)->unique()->after('name');

            // Os quatro índices de reputação (GDD §26). A tabela de precedência da seção 0
            // veda expressamente compensação cruzada: cada índice só se recupera com
            // condutas da mesma categoria. Por isso são colunas isoladas, nunca um agregado.
            $table->smallInteger('confianca_comercial')->default(0);
            $table->smallInteger('conduta_social')->default(0);
            $table->smallInteger('status_civico')->default(0);
            $table->smallInteger('honra_militar_diplomatica')->default(0);

            // Destrava a subvenção das cinco essenciais (GDD §24.7: "mediante conclusão da tutoria").
            $table->dateTime('tutorial_completed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'nickname',
                'confianca_comercial',
                'conduta_social',
                'status_civico',
                'honra_militar_diplomatica',
                'tutorial_completed_at',
            ]);
        });
    }
};
