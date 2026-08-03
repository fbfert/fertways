<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O modificador de guerra no Motor de Eventos (A2.10, §17 / dependência declarada).
 *
 * ## Por que ele existe
 *
 * O roadmap da A2.10 lista sete dependências, e a última era esta: o motor da A2.8 só sabia
 * **produção** e **consumo**, e o §17 do GDD da guerra federativa exige que um evento externo possa
 * **impor trégua** e **mexer no custo de mobilização**. A própria A2.8 pôs "combate" na lista
 * *Depois* — esta migration é o "depois" chegando.
 *
 * ## Os dois modificadores, e por que são só dois
 *
 * - **`guerra_declaracao`** — o portão. `-10000` (−100%) fecha: ninguém declara guerra enquanto
 *   durar. É a trégua imposta pelo Governo, e é também como se "abre uma janela de guerra" — fecha-se
 *   por padrão e cancela-se o evento quando a janela abre.
 * - **`guerra_custo`** — quanto custa declarar e mobilizar, em pontos-base sobre o custo normal.
 *
 * Não há um terceiro. "Abrir janela" e "impor trégua" são a mesma alavanca vista dos dois lados, e
 * inventar dois nomes para o mesmo portão só criaria a chance de os dois discordarem.
 *
 * ## ⚠️ E eles NÃO se medem como produção e consumo
 *
 * O motor calcula **média ponderada pelo tempo**, e isso é exato para taxas porque produção é linear
 * no tempo. Guerra não é taxa: *"há trégua agora?"* e *"quanto custa declarar agora?"* são perguntas
 * de **instante**. Uma trégua que cobrisse metade do intervalo viraria "meio bloqueada", que não
 * significa coisa alguma.
 *
 * O docblock de `Modificadores` já avisava: *"isso vale porque a produção é linear no tempo; se um
 * dia entrar um modificador não linear, ele precisa fatiar"*. Entrou. Por isso `Modificadores::em()`
 * responde por instante, e `para()` **recusa** os modificadores pontuais em vez de calcular uma média
 * que enganaria quem a lesse.
 *
 * ## A coluna deixa de ser `enum`
 *
 * `enum` obriga uma migration a cada modificador novo — e a A2.8 já promete seis (taxa, logística,
 * construção, pesquisa, população, território). `string` com a lista canônica em
 * `Modificadores::TODOS` põe a verdade num lugar só, que é onde o código já a procura.
 *
 * ## ⚠️ E o SQLite impõe `enum`, ao contrário do que eu supus
 *
 * A primeira versão desta migration só mexia no MariaDB, com o comentário de que *"o SQLite dos
 * testes não impõe a restrição"*. **Impõe**: o Laravel traduz `enum` em CHECK constraint, e o teste
 * quebrou na hora com *"CHECK constraint failed: modificador"*.
 *
 * É a assimetria SQLite×MariaDB de sempre, **invertida**: aqui o banco dos testes é o mais estrito.
 * O `change()` nativo do Laravel resolve os dois — no SQLite ele reconstrói a tabela, no MariaDB
 * emite o `ALTER`. Sem ramo por driver, que era o erro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_events', function (Blueprint $table) {
            $table->string('modificador', 40)->change();
        });
    }

    public function down(): void
    {
        Schema::table('game_events', function (Blueprint $table) {
            $table->enum('modificador', ['producao', 'consumo'])->change();
        });
    }
};
