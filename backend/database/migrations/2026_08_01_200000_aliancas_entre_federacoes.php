<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aliança entre federações (A2.5, item 7 — *"preparar interface diplomática"*).
 *
 * ## O que existia, e o que faltava
 *
 * *"Diplomata"* era um **cargo sem sistema**: o papel existe desde o D-114 e só sabe convidar
 * colônia. Nunca houve tratado, aliança ou relação de qualquer espécie **entre** federações — o
 * D-174 registrou isso ao fechar a fatia anterior da fase.
 *
 * E havia uma peça esperando: `federation_settings.desconto_tributo_aliados_bps` existe desde o
 * D-120 valendo 50%, mas *"aliado"* ali quer dizer **mesma federação**. O jogo tinha desconto entre
 * aliados sem ter aliados.
 *
 * ## ⚠️ Dois estados, e não três
 *
 * Aliada e neutra. **Hostilidade NÃO entra**, e a razão não é preguiça: não há guerra entre
 * federações no jogo — a A2.10 é quem a traz. Um estado "hostil" hoje não faria nada, e publicar
 * estado sem efeito é exatamente a peça inerte que esta fase vem consertando (`vehicles.level` sem
 * rota, `population_settings.ativo` sem leitor, `.botao` sem definição).
 *
 * ## Consentimento mútuo para aliar, unilateral para romper
 *
 * Propor é de Líder ou Diplomata; aceitar também. Romper **não pede permissão a ninguém** — a
 * assimetria é deliberada: entrar exige acordo, sair não exige refém.
 *
 * ## O par é ORDENADO, e isso é o que impede aliança duplicada
 *
 * `menor_id` e `maior_id` em vez de "de/para": sem a ordenação, A→B e B→A seriam duas linhas para a
 * mesma relação, e a pergunta *"estas duas são aliadas?"* passaria a ter duas respostas possíveis.
 * Quem propôs fica em `proposta_por_id`, que é informação de história, não de identidade.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('federation_alliances', function (Blueprint $table) {
            $table->id();

            /*
             * Sempre `menor_id < maior_id`. O índice único é o que garante uma linha por par —
             * ver o docblock.
             */
            $table->foreignId('menor_id')->constrained('federations')->cascadeOnDelete();
            $table->foreignId('maior_id')->constrained('federations')->cascadeOnDelete();
            $table->unique(['menor_id', 'maior_id']);

            /** `proposta` até a outra aceitar; `aceita` é o que dá efeito de jogo. */
            $table->enum('status', ['proposta', 'aceita'])->default('proposta');

            /** Quem propôs — história, não identidade. Quem aceita é sempre a outra. */
            $table->foreignId('proposta_por_id')->constrained('federations')->cascadeOnDelete();

            $table->timestamp('aceita_em')->nullable();
            $table->timestamps();
        });

        Schema::table('federation_settings', function (Blueprint $table) {
            /*
             * ⚠️ MENOR que o desconto interno (5000 = 50%), e é decisão de desenho, não número solto.
             *
             * Se aliar rendesse tanto quanto filiar-se, o teto de 12 membros viraria letra morta:
             * bastaria montar três federações aliadas em vez de uma grande, e a regra que existe
             * para limitar concentração seria contornada pela porta da frente.
             */
            $table->unsignedInteger('desconto_tributo_aliancas_bps')->default(2_000);

            /*
             * Quantas aliadas uma federação pode ter ao mesmo tempo.
             *
             * Sem teto, todo mundo se alia a todo mundo e o mundo vira um bloco só — diplomacia
             * deixa de ser escolha no instante em que aliar-se não custa nada e não exclui nada.
             * Duas é o menor número que ainda permite blocos rivais em vez de um pacto universal.
             */
            $table->unsignedTinyInteger('max_aliadas')->default(2);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('federation_alliances');

        Schema::table('federation_settings', function (Blueprint $table) {
            $table->dropColumn(['desconto_tributo_aliancas_bps', 'max_aliadas']);
        });
    }
};
