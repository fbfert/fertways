<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O Ministério dos Transportes (D-60): placas, frota do governo e a fábrica de caminhões.
 *
 * Três mudanças em `vehicles`, e nenhuma tabela nova — porque o GDD já dá o modelo certo. O §16.2
 * chama a frota do Ministério de "**Frota Governamental — propriedade do governo**", em oposição à
 * "Frota dos Colonos". Um caminhão do governo é, então, um veículo **sem dono**: a mesma linha de
 * `vehicles`, com `colony_id` nulo. Vender é dar-lhe um dono, e nada mais. Vender NÃO copia linha
 * de uma tabela para outra, e a placa (que nasce na fabricação) atravessa a venda sem tocar em nada.
 *
 * - **`colony_id` fica nulável.** Nulo = frota do governo. Toda consulta de colono passa por
 *   `$colony->vehicles()`, que filtra por `colony_id` — logo os veículos do governo somem das telas
 *   dos colonos sem que nenhuma delas precise saber que ele existe.
 * - **`plate`** (§16.3): `FW` + 5 dígitos sequenciais + a inicial do tipo — `FW-00001-C`. Única.
 *   Nulável só para o backfill (`fertways:placas`) dos veículos que já existem.
 * - **`ready_at`** e dois estados novos no enum de `status`: `fabricando` (na fila do Ministério,
 *   fica pronto em `ready_at`) e `estoque` (pronto na prateleira, à espera de comprador). Nenhum
 *   deles é `em_rota`, que é o único que o `ConcluirTrechos` varre — a fábrica não interfere na
 *   máquina de viagem, e a máquina de viagem não sabe que a fábrica existe.
 */
return new class extends Migration
{
    private const ESTADOS_NOVOS = ['ocioso', 'carregando', 'em_rota', 'descarregando', 'fabricando', 'estoque'];

    private const ESTADOS_ANTIGOS = ['ocioso', 'carregando', 'em_rota', 'descarregando'];

    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            // Nulo = frota do governo (§16.2). A FK sobrevive: ela nunca proibiu o nulo, só
            // exigia que um valor não-nulo existisse em `colonies`.
            $table->unsignedBigInteger('colony_id')->nullable()->change();

            $table->enum('status', self::ESTADOS_NOVOS)->default('ocioso')->change();

            if (! Schema::hasColumn('vehicles', 'plate')) {
                $table->string('plate', 16)->nullable()->unique()->after('type');
            }

            if (! Schema::hasColumn('vehicles', 'ready_at')) {
                $table->timestamp('ready_at')->nullable()->after('arrives_at');
            }
        });
    }

    public function down(): void
    {
        // Os veículos do governo não têm para onde voltar: `colony_id` vai voltar a ser obrigatório
        // e eles não têm dono. Somem — são patrimônio do Estado, não de ninguém, e não há colono a
        // quem entregá-los.
        \App\Models\Vehicle::whereNull('colony_id')->delete();

        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropUnique(['plate']);
            $table->dropColumn(['plate', 'ready_at']);
            $table->enum('status', self::ESTADOS_ANTIGOS)->default('ocioso')->change();
            $table->unsignedBigInteger('colony_id')->nullable(false)->change();
        });
    }
};
