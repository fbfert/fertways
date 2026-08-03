<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Neutralidade declarada (A2.10, decisão 12 do D-193).
 *
 * ## A decisão, na íntegra
 *
 * > *"A neutralidade só pode ocorrer se declarada pelo jogador antes do início da guerra. Jogador que
 * > fica 7 dias offline não tem proteção."*
 *
 * Neutralidade **não é prêmio por ausência**: é ato político tomado com o jogador presente. Quem sabe
 * que vai sumir, declara antes.
 *
 * ## ⚠️ O que a decisão não respondia, e sem o que a mecânica não funciona
 *
 * **Quanto custa ser neutro.** Se fosse grátis e reversível na hora, todos se declarariam neutros e
 * largariam o escudo no instante de atacar — e a guerra nunca aconteceria. A neutralidade viraria o
 * estado padrão do mundo, e a A2.10 inteira, decoração.
 *
 * Duas regras resolvem isso, e são a arbitragem registrada aqui:
 *
 * 1. **Simetria.** Federação neutra não pode ser declarada **e não pode declarar**. É o que a palavra
 *    significa: quem não entra na guerra não entra dos dois lados.
 * 2. **Carência para SAIR.** Encerrar a neutralidade só vale depois de `neutralidade_carencia_horas`.
 *    Sem isso o escudo se larga na hora do ataque, e proteção que se tira quando convém não é
 *    proteção — é emboscada com aviso legal.
 *
 * Entrar é imediato; **sair, não**. A assimetria é deliberada e é a mesma da aliança (D-182), pelo
 * motivo oposto: lá, sair é livre porque ninguém deve ficar refém de um pacto; aqui, sair é lento
 * porque ninguém deve poder atacar de dentro do abrigo.
 *
 * ## E não se declara neutralidade estando em guerra
 *
 * Seria fugir do que já começou. A saída de uma guerra em curso é a capitulação, que vem na fatia
 * seguinte.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('federations', function (Blueprint $table) {
            /** Desde quando é neutra. Nulo = não é. */
            $table->dateTime('neutra_desde')->nullable();

            /*
             * Quando a neutralidade **acaba**, para quem já pediu para sair. Enquanto esta data não
             * chega, a federação continua neutra — é a carência do docblock, e é o que impede o
             * escudo de ser largado no instante do ataque.
             */
            $table->dateTime('neutralidade_termina_em')->nullable();
        });

        Schema::table('war_settings', function (Blueprint $table) {
            /** Quanto tempo entre pedir para sair da neutralidade e deixar de estar protegido. */
            $table->unsignedInteger('neutralidade_carencia_horas')->default(24);
        });
    }

    public function down(): void
    {
        Schema::table('war_settings', function (Blueprint $table) {
            $table->dropColumn('neutralidade_carencia_horas');
        });

        Schema::table('federations', function (Blueprint $table) {
            $table->dropColumn(['neutra_desde', 'neutralidade_termina_em']);
        });
    }
};
