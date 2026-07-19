<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O limite antimonopólio do §04 (D-119): "Limite antimonopólio dinâmico: 20% → 10%" — sem dizer
 * 20%/10% DE QUÊ, nem o que dispara a transição de um estágio ao outro. Arbitrado com o usuário:
 * mede a fatia de TODAS as zonas neutras OCUPADAS do jogo que uma federação detém; um teto FIXO
 * (não os dois estágios — o GDD não diz o gatilho, e inventar um seria a mesma arbitragem vaga que
 * o D-114 já tinha evitado), do operador, ajustável sem deploy.
 *
 * 2000 bps (20%) por padrão — o mais frouxo dos dois números do GDD, para começar sem morder o
 * jogo pequeno de hoje; o operador aperta para 10% (ou o que quiser) conforme o servidor cresce.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('federation_settings', function (Blueprint $t) {
            $t->id();
            $t->unsignedInteger('teto_ocupacao_zonas_bps')->default(2_000);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('federation_settings');
    }
};
