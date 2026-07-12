<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A administração deixa rastro (D-61).
 *
 * **O buraco que isto fecha.** Num jogo cuja regra de ouro é que recurso não nasce sem história — o
 * `ledger` é append-only justamente por isso —, **o operador era o único que podia criar valor sem
 * deixar história**. Julgar um caso, distribuir o Tesouro, disparar um tick: nada disso ficava
 * registrado. O ledger audita a economia; nada auditava a administração.
 *
 * ---
 *
 * **`audit_log`** — append-only, como o ledger. Sem `updated_at` de propósito: uma linha de auditoria
 * que pode ser editada não é auditoria. Guarda o **antes e o depois** (`de` / `para`), porque um log
 * que diz "fulano editou a colônia 4" sem dizer *o quê* não responde a pergunta que se faz dele.
 *
 * `admin_id` é **nulável** para caber o que não tem autor conhecido: um **login que falhou** (o
 * e-mail digitado pode nem existir) e os atos do **sistema** (o cron do tick). O e-mail vai numa
 * coluna própria (`admin_email`) e **não** é uma FK: se o admin for apagado um dia, o rastro do que
 * ele fez **não pode sumir com ele**. É a mesma razão pela qual o ledger não tem FK para o veículo.
 *
 * **`admins.role`** — dois papéis (D-61):
 *   dono      tudo, inclusive gerir admins e realocar colônias
 *   operador  julga, publica, distribui, e nos jogadores vê/suspende/corrige — não gere admins
 *
 * O admin que já existe vira **dono**: ele é o único que há, e rebaixá-lo trancaria o painel.
 *
 * **`users.suspenso_*`** — a suspensão do D-61: barra o acesso e congela **só o comércio** (reusa a
 * restrição comercial do §9.4). Motivo e prazo obrigatórios; `suspenso_ate` nulo = definitivo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_log', function (Blueprint $table) {
            $table->id();

            // Nulável: login que falhou não tem admin, e o sistema (cron) não é ninguém.
            $table->foreignId('admin_id')->nullable()->constrained('admins')->nullOnDelete();
            // Sem FK, de propósito: o rastro sobrevive ao admin que o deixou.
            $table->string('admin_email')->nullable();
            $table->string('papel', 16)->nullable();

            // `julgar`, `tesouro.distribuir`, `jogador.suspender`, `login.falhou`…
            $table->string('acao', 64)->index();
            // Sobre o quê: `user:12`, `colony:4`, `report:7`, `admin:2`. Texto, não FK — o alvo pode
            // ser de qualquer tabela, e a auditoria não pode ficar refém do ciclo de vida dele.
            $table->string('alvo', 64)->nullable()->index();
            $table->string('resumo', 255);

            // O antes e o depois. JSON porque cada ação muda um conjunto diferente de campos.
            $table->json('de')->nullable();
            $table->json('para')->nullable();

            $table->string('ip', 45)->nullable();
            $table->string('agente', 255)->nullable();

            // Só `created_at`: uma linha de auditoria não se atualiza. Se um dia alguém acrescentar
            // um `updated_at` aqui, terá desfeito a garantia inteira.
            $table->timestamp('created_at')->useCurrent()->index();
        });

        Schema::table('admins', function (Blueprint $table) {
            $table->string('role', 16)->default('operador')->after('email');
            // Desativar em vez de apagar: o `admin_id` das linhas de auditoria continua apontando
            // para alguém, e o histórico de quem julgou o quê não se perde.
            $table->timestamp('desativado_em')->nullable()->after('role');
        });

        // O admin que já existe é o único que há. Rebaixá-lo a operador trancaria o painel: não
        // haveria dono para promover ninguém.
        DB::table('admins')->update(['role' => 'dono']);

        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('suspenso_em')->nullable()->after('tutorial_completed_at');
            // Nulo com `suspenso_em` preenchido = suspensão definitiva.
            $table->timestamp('suspenso_ate')->nullable()->after('suspenso_em');
            $table->string('suspenso_motivo', 500)->nullable()->after('suspenso_ate');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_log');

        Schema::table('admins', function (Blueprint $table) {
            $table->dropColumn(['role', 'desativado_em']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['suspenso_em', 'suspenso_ate', 'suspenso_motivo']);
        });
    }
};
