<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A Fábrica do Ministério dos Transportes vira admin-editável (docs/decisoes.md D-109) — e passa a
 * fabricar o Furgão de Comércio também, não só o Caminhão de Carga.
 *
 * Mesmo molde do kit inicial (D-92) e do Silo (D-107/108): os valores de partida entram AQUI, numa
 * migration one-time — não num Seeder re-executável — porque esta tabela é editável pelo admin e
 * `db:seed` nunca pode apagar o ajuste dele em silêncio.
 *
 * **Caminhão**: os números que já existiam em `Ministerio.php` (PHP puro até aqui) — 300 Fert$,
 * 60 min, 90 Ligas/25 Componentes/16 Metal Bruto (GDD §21.3) — só migram de constante para banco;
 * nenhum número muda neste commit.
 *
 * **Furgão**: pedido do usuário — vendido a 150 Fert$; custo de fabricação e tempo = 40% do
 * Caminhão (36 Ligas/10 Componentes/6 Metal Bruto arredondados, 24 min). **Não é o custo do GDD
 * §21.2** (`VeiculoCustos::NIVEL_1['furgao_de_comercio']`, 40/10/7) — aquela tabela é outra coisa,
 * a base de que a MANUTENÇÃO de qualquer Furgão já existente deriva (10% dela); tocá-la mudaria o
 * custo de manutenção de todo Furgão do kit inicial, e ninguém pediu isso. O custo de FABRICAÇÃO
 * do governo é um número novo e separado, só usado por esta fábrica.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fabrica_veiculos', function (Blueprint $table) {
            $table->string('tipo', 40)->primary();
            $table->unsignedBigInteger('preco_micro');
            $table->unsignedSmallInteger('estoque_alvo');
            $table->unsignedInteger('minutos_fabricacao');
            $table->json('custo_json');
            $table->foreignId('admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();
        });

        $agora = now();

        DB::table('fabrica_veiculos')->insert([
            [
                'tipo' => 'caminhao_de_carga',
                'preco_micro' => 300_000_000,
                'estoque_alvo' => 5,
                'minutos_fabricacao' => 60,
                'custo_json' => json_encode([
                    'ligas_metalicas' => 90, 'componentes_eletronicos' => 25, 'metal_bruto' => 16,
                ], JSON_UNESCAPED_UNICODE),
                'created_at' => $agora, 'updated_at' => $agora,
            ],
            [
                'tipo' => 'furgao_de_comercio',
                'preco_micro' => 150_000_000,
                'estoque_alvo' => 5,
                'minutos_fabricacao' => 24,
                'custo_json' => json_encode([
                    'ligas_metalicas' => 36, 'componentes_eletronicos' => 10, 'metal_bruto' => 6,
                ], JSON_UNESCAPED_UNICODE),
                'created_at' => $agora, 'updated_at' => $agora,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('fabrica_veiculos');
    }
};
