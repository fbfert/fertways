<?php

use App\Models\Colony;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * O kit inicial (D-85) deixa de ser uma const em PHP e vira linhas de banco, editáveis pelo
 * painel de admin (D-92) — pedido do usuário: arbitrar o kit sem precisar mexer em código.
 *
 * Duas tabelas, dois formatos, porque são duas naturezas diferentes:
 *
 *  - `kit_inicial_recursos`: uma linha por recurso do catálogo (chave = código, como
 *    `resource_types`) — os mesmos 26 valores que `KitInicial::RECURSOS` tinha, agora editáveis.
 *  - `kit_inicial_settings`: linha única (mesmo padrão de `transport_settings`/`marco_settings`)
 *    para o que NÃO é "um valor por recurso" — o Fert$ inicial e quantos veículos de cada tipo a
 *    colônia nasce com.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kit_inicial_recursos', function (Blueprint $table) {
            $table->string('resource_type')->primary();
            $table->unsignedBigInteger('amount')->default(0);
            $table->timestamps();
        });

        // Os mesmos 26 valores que `KitInicial::RECURSOS` publicava (D-85) — só migram de PHP
        // para banco; nenhum número muda neste commit.
        $valores = [
            'oxigenio' => 500, 'agua' => 500, 'biomassa' => 500, 'energia' => 500,
            'metal_bruto' => 500, 'ligas_metalicas' => 250, 'compostos_quimicos' => 100,
            'biocombustivel' => 100, 'componentes_eletronicos' => 100, 'aluminio' => 25,
            'cobre' => 25, 'estanho' => 25, 'litio' => 25, 'ouro' => 25, 'silicio' => 10,
            'tantalo' => 10, 'tungstenio' => 10, 'bioenergia_curativa' => 5,
            'cristal_de_helio_3' => 5, 'ferro_vermelho' => 5, 'fungo_bioluminescente' => 0,
            'gelo_de_metano' => 15, 'niobio_alienigena' => 0, 'plasma_fossilizado' => 2,
            'quartzo_piezoeletrico' => 2, 'resina_organica' => 5,
        ];

        $agora = now();

        DB::table('kit_inicial_recursos')->insert(
            array_map(
                fn ($codigo, $qtd) => [
                    'resource_type' => $codigo, 'amount' => $qtd,
                    'created_at' => $agora, 'updated_at' => $agora,
                ],
                array_keys($valores),
                array_values($valores),
            ),
        );

        Schema::create('kit_inicial_settings', function (Blueprint $table) use ($agora) {
            $table->id();
            $table->unsignedBigInteger('fert_micro')->default(Colony::SALDO_INICIAL_MICRO);
            // Um Furgão de Comércio, zero Caminhões — o mesmo kit que `CreateColony` já dava.
            $table->unsignedTinyInteger('furgoes')->default(1);
            $table->unsignedTinyInteger('caminhoes')->default(0);
            $table->timestamps();
        });

        DB::table('kit_inicial_settings')->insert([
            'fert_micro' => Colony::SALDO_INICIAL_MICRO,
            'furgoes' => 1,
            'caminhoes' => 0,
            'created_at' => $agora,
            'updated_at' => $agora,
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('kit_inicial_settings');
        Schema::dropIfExists('kit_inicial_recursos');
    }
};
