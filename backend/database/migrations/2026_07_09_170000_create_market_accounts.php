<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Conta do colono no Mercado Central (GDD §25.8).
 *
 * O §25.8 exige que o recurso seja **fisicamente entregue** ao Mercado antes de poder ser
 * vendido, e que o recurso comprado fique numa conta até o colono mandar um veículo buscá-lo.
 * Essa conta é este saldo: nem estoque de colônia (não conta para o `storage_cap`, não é
 * produzido nem consumido lá) nem carga em trânsito.
 *
 * A venda em si não entra aqui — ela depende de um preço, e §22.2 e §24.8 divergem em trinta e
 * oito vezes para os Componentes Eletrônicos (pendência D-24). Depósito e retirada não dependem
 * de preço nenhum, então vão primeiro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_accounts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('colony_id')->constrained()->cascadeOnDelete();

            $t->string('resource_type', 40);
            $t->foreign('resource_type')->references('code')->on('resource_types');

            $t->unsignedBigInteger('amount')->default(0);

            $t->timestamps();

            // Um saldo por recurso por colônia. É o que permite o `where amount >= qtd` do
            // débito ser atômico contra dois veículos disputando o mesmo saldo.
            $t->unique(['colony_id', 'resource_type']);
        });

        Schema::table('vehicles', function (Blueprint $t) {
            /*
             * `entrega` leva carga na ida. `retirada` sai vazio, carrega no Mercado e traz na
             * volta (§25.8). Sem esta coluna a ida de uma retirada seria indistinguível de um
             * depósito, e o tick entregaria a carga ao Mercado em vez de trazê-la para casa.
             */
            $t->string('trip_purpose', 12)->nullable()->after('leg');
        });

        // Viagens já no ar quando esta migration roda são todas entregas — `retirada` não
        // existia. Deixar NULL funcionaria (o código trata NULL como entrega), mas um valor
        // explícito evita que a próxima pessoa a ler a tabela tenha de saber disso.
        DB::table('vehicles')->where('status', 'em_rota')->update(['trip_purpose' => 'entrega']);
    }

    public function down(): void
    {
        Schema::dropIfExists('market_accounts');
        Schema::table('vehicles', fn (Blueprint $t) => $t->dropColumn('trip_purpose'));
    }
};
