<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cargos Públicos, §14.2 (D-130) — os 4 que sobravam depois do Conciliador (§9, D-49/D-50).
     *
     * O texto que a v36 consolida não tem seção de Cargos Públicos; o §14.2 só existe nas revisões
     * arquivadas em FERTWAYS_GDD_v35_MESTRE_UNIFICADO.html, e duas delas SE CONTRADIZEM: a v30 exige
     * o checklist inteiro do §14.3 (Neutro Registrado + 7 critérios) para os 5 cargos; a v32 já
     * corrige isso ("Neutro Registrado é exclusivo do Conciliador; demais contratos têm limites e
     * não aplicam sanções/dados privados") — e o D-50 já seguiu essa leitura ao implementar o
     * Conciliador. Os 4 cargos daqui seguem a v32: "contratos cívicos limitados", 1 índice de
     * reputação por cargo, sem status de Neutro Registrado.
     *
     * Tabela NOVA, separada de `users` (onde o Conciliador mora): evita tocar numa coluna que já
     * tem dado de produção, e generaliza os 4 cargos sem generalizar o Conciliador — que continua
     * exatamente como o D-50 o deixou.
     */
    public function up(): void
    {
        Schema::create('civic_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // 'reporter' | 'fiscal_de_mercado' | 'auxiliar_de_tesouro'. String, não enum — mesmo
            // motivo do D-58 (ledger.type) e do D-129 (tax_events.kind): não travar em ALTER de
            // enum se um cargo novo entrar depois (ex.: Atendente do Espaçoporto, quando o
            // Espaçoporto existir — ver D-130).
            $table->string('kind', 30);

            $table->dateTime('desde');
            $table->dateTime('suspenso_em')->nullable();
            $table->dateTime('salario_pago_em')->nullable();

            $table->timestamps();

            $table->unique(['user_id', 'kind']);
            $table->index('kind');
        });

        /*
         * O "ato" do Fiscal de Mercado e do Auxiliar de Tesouro (§14.2: "sinaliza", "aponta"): uma
         * sinalização de texto livre, confirmada pela equipe — o mesmo molde de uma denúncia do
         * Ministério, só que sem punição nenhuma do outro lado. O bônus (§14.2, sem número — usa o
         * mesmo dos 3 Fert$ do Conciliador, D-50) só paga na confirmação, não no ato de sinalizar:
         * senão seria Fert$ de graça por escrever qualquer coisa.
         */
        Schema::create('civic_flags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 30);
            $table->string('motivo', 500);
            $table->dateTime('confirmado_em')->nullable();
            $table->dateTime('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('civic_flags');
        Schema::dropIfExists('civic_posts');
    }
};
