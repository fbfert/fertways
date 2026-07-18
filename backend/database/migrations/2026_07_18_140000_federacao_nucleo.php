<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Federação (docs/decisoes.md D-114), Fatia 1 — o núcleo: criar/entrar/sair, os quatro cargos do
 * GDD (Líder, Diplomata, Intendente, Membro) e o fundo.
 *
 * `colonies.federation_id`/`federation_role` são posse DIRETA, sem tabela pivô — mesmo padrão de
 * `neutral_zones.owner_colony_id`: uma colônia pertence a no máximo uma federação por vez.
 *
 * `federations.disbanded_at` em vez de `DELETE`: o projeto nunca apaga de fato (`Admin.desativado_em`,
 * `Punishment.revoked_at`) — uma federação dissolvida vira histórico consultável, não um buraco.
 *
 * `federation_holdings`/`federation_ledger` espelham `treasury_holdings`/`treasury_ledger`
 * (D-57/D-96), mas NÃO são singleton — há N federações, então PK sintética + unique composto em
 * vez de `resource_type` como chave primária.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('federations', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60)->unique();
            $table->timestamp('disbanded_at')->nullable();
            $table->timestamps();
        });

        Schema::table('colonies', function (Blueprint $table) {
            $table->foreignId('federation_id')->nullable()->after('id')
                ->constrained('federations')->nullOnDelete();
            $table->string('federation_role', 20)->nullable()->after('federation_id');
        });

        Schema::create('federation_invites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('federation_id')->constrained('federations')->cascadeOnDelete();
            $table->foreignId('colony_id')->constrained('colonies')->cascadeOnDelete();
            $table->string('kind', 10); // convite | pedido
            $table->string('status', 10)->default('pendente'); // pendente | aceito | recusado | cancelado
            $table->foreignId('created_by_colony_id')->constrained('colonies')->cascadeOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['federation_id', 'status']);
            $table->index(['colony_id', 'status']);
        });

        Schema::create('federation_holdings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('federation_id')->constrained('federations')->cascadeOnDelete();
            $table->string('resource_type', 40);
            $table->unsignedBigInteger('amount')->default(0);
            $table->timestamps();

            $table->unique(['federation_id', 'resource_type']);
        });

        Schema::create('federation_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('federation_id')->constrained('federations')->cascadeOnDelete();
            // Nulo em crédito de dissolução (o saldo remanescente vai para o Tesouro, sem colônia).
            $table->foreignId('colony_id')->nullable()->constrained('colonies')->nullOnDelete();
            $table->string('type', 20); // credito | saque | dissolucao
            $table->bigInteger('amount'); // sinal: positivo entra, negativo sai
            $table->string('resource_type', 40);
            $table->string('ref', 120)->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('federation_ledger');
        Schema::dropIfExists('federation_holdings');
        Schema::dropIfExists('federation_invites');

        Schema::table('colonies', function (Blueprint $table) {
            $table->dropConstrainedForeignId('federation_id');
            $table->dropColumn('federation_role');
        });

        Schema::dropIfExists('federations');
    }
};
