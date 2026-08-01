<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * O teto de estoque que TRAVA (A2.7, item 4 / BALANCEAMENTO §14).
 *
 * ## A decisão, que é do §14 e não minha
 *
 * *"Decidido: o teto trava, não derrama. Ao encher, a produção para; nada transborda e vira
 * desperdício. O jogador perde oportunidade, nunca estoque."*
 *
 * É o que reconcilia as duas exigências que brigam aqui: a §14 quer capacidade como instrumento
 * econômico, e o §1.1 do GDD proíbe exigir login constante. Um teto que descarta o excedente puniria
 * quem passou o dia fora; um teto que trava apenas suspende o ganho e devolve a decisão ao jogador.
 *
 * ## ⚠️ Por que uma tabela nova, e não `silo_capacidades`
 *
 * `Silo` **não é teto de estoque** — ele decide o que fica **protegido de saque**, e o docblock dele
 * diz isso com todas as letras. São duas perguntas diferentes sobre o mesmo prédio: *quanto cabe* e
 * *quanto está a salvo*. Conflá-las inventaria uma regra que ninguém decidiu, e amarraria para
 * sempre dois números que precisam se mover em separado.
 *
 * (De quebra: `silo_capacidades` é **plana** — 10.000 em todos os dez níveis, para todo recurso. O
 * nível do Depósito Local hoje não altera nada. Isso é assunto da proteção, e fica para quando o
 * saque de colônia existir.)
 *
 * ## A curva, e o alvo declarado antes de medir
 *
 * **No nível 1 uma colônia a plena produção enche o teto em cerca de um dia; no nível 10, em cerca
 * de uma semana.** É a leitura direta do que a §14 manda avaliar — *"o ritmo de produção por hora e
 * o intervalo real entre sessões"*.
 *
 * Medido contra a produção real das 29 colônias: o pico não-energético é **405 de água por hora**.
 *
 * | | conta | resultado |
 * |---|---|---|
 * | um dia no pico | 405 × 24 | ~9.700 → **base 10.000** |
 * | uma semana no pico | 405 × 168 | ~68.000 |
 *
 * O fator **compõe** (1,25× por nível) em vez de somar. Somar 25% ao nível 1 chegaria a 3,25× no
 * nível 10 — 3,3 dias, aquém do alvo. Compondo chega a 7,45×, ou ~74.500, que são 7,7 dias. A forma
 * foi escolhida por atingir o alvo declarado, não por gosto.
 *
 * ## ⚠️ Nasce DESLIGADO, e não é formalidade
 *
 * O mundo guarda hoje ~35 mil de água por colônia — **3,5× o teto do nível 1** — e 25 das 29 têm
 * Depósito Local nível 1. Ligar isto hoje travaria a produção de quase todas de uma vez.
 *
 * O teto nunca destrói estoque: acima dele a produção para, e o que já existe fica. É a mesma forma
 * do teto habitacional da população (D-178) — *trava o crescimento, não expulsa ninguém*. Mas
 * "não destrói" não quer dizer "pode ligar": a ativação é decisão separada, e precisa da rodada do
 * simulador (item 6) e de um plano para as veteranas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estoque_settings', function (Blueprint $table) {
            $table->id();

            /*
             * A chave-mestra, e ver o docblock: enquanto `false`, `TetoDoEstoque::capacidade()`
             * devolve `null` e o tick não conhece teto nenhum. Ligar é decisão separada.
             */
            $table->boolean('ativo')->default(false);

            /** Quanto cabe de CADA recurso no Depósito Local nível 1. Ver a conta no docblock. */
            $table->unsignedInteger('capacidade_base')->default(10_000);

            /** O fator por nível, em milésimos — 1250 = 1,25×, e COMPÕE. Mesma forma da população. */
            $table->unsignedInteger('capacidade_fator_milesimos')->default(1_250);
        });

        DB::table('estoque_settings')->insert(['ativo' => false]);
    }

    public function down(): void
    {
        Schema::dropIfExists('estoque_settings');
    }
};
