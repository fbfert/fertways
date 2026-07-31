<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Onboarding produtivo (A2.1): a marca de obrigatoriedade.
 *
 * ## O que o confronto com o código achou, e que muda o tamanho da fase
 *
 * O roadmap previa duas adaptações no motor de Missões, e a primeira era "missão hoje é recusável;
 * a fase obrigatória mínima não pode ser". **Não existe mecanismo de recusa no código** — nada de
 * botão "abandonar", nada de status `recusada`. O que torna a tutoria dispensável é outra coisa:
 * ela **expira em 3 dias** (`Atribuir::tutoria`) e nada acontece se o colono simplesmente a ignorar.
 *
 * E a segunda descoberta é melhor ainda: **o motor já sabe fazer sequência obrigatória.** A
 * categoria `narrativa` (D-140) é encadeada por `requer_template_id`, entrega um capítulo por vez e
 * **não expira** (`expires_at` nulo). É exatamente a forma que o onboarding pede. Não há mecanismo
 * novo a construir — há um mecanismo existente a reaproveitar.
 *
 * Sobra, de verdade, uma coluna: a marca que diz **quais etapas não podem ser puladas**. O
 * encadeamento sozinho não diz isso — ele diz a ordem, não a obrigação.
 *
 * ## Por que uma coluna e não uma categoria nova
 *
 * Uma categoria `onboarding_obrigatorio` separada quebraria o encadeamento: `requer_template_id`
 * aponta para um template, e uma sequência que atravessa duas categorias faria a consulta de
 * liberação ter de olhar as duas. Uma coluna booleana mantém a sequência inteira numa categoria só
 * e deixa a obrigatoriedade ser um atributo de cada degrau — que é o que ela é.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mission_templates', function (Blueprint $table) {
            /*
             * `false` como padrão, e é a escolha segura: todo template que já existe — 33 diárias,
             * 8 semanais, 5 de tutoria — continua exatamente como estava. A obrigatoriedade é
             * afirmação explícita de quem cadastra, nunca herança de um default.
             */
            $table->boolean('obrigatoria')->default(false)->after('categoria');
        });
    }

    public function down(): void
    {
        Schema::table('mission_templates', function (Blueprint $table) {
            $table->dropColumn('obrigatoria');
        });
    }
};
