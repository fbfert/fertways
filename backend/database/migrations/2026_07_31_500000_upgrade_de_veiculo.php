<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Upgrade de veículo (A2.7) — os parâmetros da rota que faltava.
 *
 * `vehicles.level` **já existe** no banco desde sempre, e **nunca houve caminho para subi-lo**: o
 * nível existia sem rota. O roadmap diz exatamente isso, e é o que esta fase fecha.
 *
 * ## Um eixo, com contrapartida
 *
 * O nível aumenta a capacidade **e aumenta a manutenção junto**. É o que a §13 do BALANCEAMENTO
 * pede ao proibir melhorar tudo de graça: sem a contrapartida, subir nível seria decisão sem
 * escolha, e "escolha econômica mensurável" é o critério de saída da fase.
 *
 * ## ⚠️ Velocidade NÃO entra, e isso é decisão de desenho
 *
 * Velocidade é **traço do tipo de veículo** — é o que diferencia Furgão de Caminhão. Se o nível
 * também acelerasse, a **distância** encolheria a cada upgrade; e distância é pilar declarado do
 * jogo ("logística sem teleporte"). Há teste que guarda isso, porque é o tipo de coisa que alguém
 * acrescenta depois achando que melhora.
 *
 * ## Nas `transport_settings`, e não em tabela nova
 *
 * A casa já tem uma linha única de parâmetros de transporte — desgaste, piso de desempenho, custo de
 * manutenção, frete. Criar uma segunda tabela para os mesmos veículos espalharia o balanceamento em
 * dois lugares, e quem for ajustar o transporte teria de lembrar de olhar os dois.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transport_settings', function (Blueprint $table) {
            /*
             * O teto de nível. Cinco é HIPÓTESE — como todo número desta fase, ele espera a rodada
             * do simulador que o item 6 do trabalho pede.
             */
            $table->unsignedTinyInteger('upgrade_nivel_maximo')->default(5);

            /*
             * Custo do upgrade, como fração do custo de compra do veículo — mesma forma que a
             * manutenção já usa (`manutencao_bps_do_custo` = 10% do custo). Não é número novo: é
             * uma fração de um número que o GDD publica, que é como o D-60 resolveu a manutenção.
             *
             * Multiplica pelo nível alvo: subir para o 2 custa 1×, para o 3 custa 2×. Sem isso, o
             * último nível sairia pelo preço do primeiro.
             */
            $table->unsignedInteger('upgrade_custo_bps_do_custo')->default(4000);

            /** Quanto a capacidade sobe POR NÍVEL, em pontos-base (1500 = +15% por nível). */
            $table->unsignedInteger('upgrade_capacidade_bps_por_nivel')->default(1500);

            /*
             * E quanto a manutenção sobe junto. **Maior que o ganho de capacidade de propósito**:
             * é a contrapartida que transforma o upgrade em escolha. Um veículo de nível alto
             * carrega mais e custa mais para manter — quem roda pouco não deve querer subir.
             */
            $table->unsignedInteger('upgrade_manutencao_bps_por_nivel')->default(2000);

            /** Tempo do serviço, por nível alvo. O veículo fica indisponível enquanto sobe. */
            $table->unsignedInteger('upgrade_segundos_por_nivel')->default(3600);
        });
    }

    public function down(): void
    {
        Schema::table('transport_settings', function (Blueprint $table) {
            $table->dropColumn([
                'upgrade_nivel_maximo',
                'upgrade_custo_bps_do_custo',
                'upgrade_capacidade_bps_por_nivel',
                'upgrade_manutencao_bps_por_nivel',
                'upgrade_segundos_por_nivel',
            ]);
        });
    }
};
