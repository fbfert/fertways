<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Leilões (D-129). O GDD nunca desenha este sistema — só o cita duas vezes, como alvo de uma
     * punição inerte ("reputação negativa bloqueia acesso a leilões", §9.4/D-49/D-50). O mecanismo
     * inteiro é desenho nosso, encaixado sobre o que o Mercado Central já resolveu: escrow do lote
     * ao anunciar, acesso pela mesma Confiança Comercial (`AcessoAoMercado`), tributo pela mesma
     * `resource_types.tax_bps`, fechamento pelo tick — como o Acordo de Troca já faz com prazo.
     *
     * `qty` não decresce como em `market_orders`: o leilão é um lote único, tudo ou nada — não há
     * arremate parcial. `lance_atual_micro`/`lance_colony_id` guardam só o lance vigente; cada lance
     * anterior já foi devolvido (ledger `estorno`) no instante em que foi superado, então não há
     * necessidade de uma tabela de histórico de lances — o ledger já é o histórico.
     */
    public function up(): void
    {
        Schema::create('auctions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('colony_id')->constrained()->cascadeOnDelete();

            $table->string('resource_type', 40);
            $table->foreign('resource_type')->references('code')->on('resource_types');

            $table->unsignedBigInteger('qty');
            $table->unsignedBigInteger('lance_minimo_micro');
            $table->unsignedBigInteger('lance_atual_micro')->nullable();
            $table->foreignId('lance_colony_id')->nullable()->constrained('colonies')->nullOnDelete();

            $table->string('status', 20)->default('aberto');
            $table->dateTime('deadline_at');

            $table->timestamps();

            $table->index(['status', 'deadline_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auctions');
    }
};
