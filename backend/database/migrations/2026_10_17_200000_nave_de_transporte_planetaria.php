<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A Nave de Transporte Planetária entra na linha de montagem do Ministério (D-281).
 *
 * O GDD diz que ela é "construída na Central de Transportes" (§17.5). No jogo, todo veículo vem do
 * Ministério desde o D-60: ele fabrica com o caixa do Tesouro e vende da prateleira. A Nave segue o
 * mesmo caminho, como o Furgão seguiu no D-109.
 *
 * - **Custo:** o do §21.8, nível 1 — 250 Ligas Metálicas, 120 Componentes Eletrônicos, 45 Metal Bruto.
 * - **Preço:** 1.400 Fert$. O custo dela vale 4,7× o do Caminhão a preço de referência; o Caminhão é
 *   vendido a 300, e 300 × 4,7 dá 1.410, arredondado. Número meu, no painel (aba Fábrica).
 * - **Linha de montagem:** 280 min, pela mesma proporção dos 60 min do Caminhão.
 * - **Prateleira:** 2. É um veículo caro e de nicho; o Caminhão e o Furgão têm 5.
 *
 * One-time, como as outras linhas desta tabela: o painel é dono dos números depois daqui.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('fabrica_veiculos')->insertOrIgnore([
            'tipo' => 'nave_de_transporte_planetaria',
            'preco_micro' => 1_400_000_000,
            'estoque_alvo' => 2,
            'minutos_fabricacao' => 280,
            'custo_json' => json_encode([
                'ligas_metalicas' => 250, 'componentes_eletronicos' => 120, 'metal_bruto' => 45,
            ], JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        if (DB::table('vehicles')->where('type', 'nave_de_transporte_planetaria')->whereNull('deleted_at')->whereNotNull('colony_id')->exists()) {
            throw new RuntimeException('Há Nave de Transporte Planetária com dono. Desfazer apagaria a fábrica de um veículo em uso.');
        }

        DB::table('vehicles')->where('type', 'nave_de_transporte_planetaria')->whereNull('colony_id')->delete();
        DB::table('fabrica_veiculos')->where('tipo', 'nave_de_transporte_planetaria')->delete();
    }
};
