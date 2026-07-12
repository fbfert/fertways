<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A frota envelhece, e há mercado de usados (D-60, fatias 2 e 3).
 *
 * Tudo em **bps** (centésimos de ponto percentual: 10.000 = 100%), como as alíquotas do §8.3. É a
 * unidade que o projeto já usa para tudo que é fração, e ela evita float no dinheiro e no desgaste.
 *
 * ---
 *
 * **`vehicles` ganha o estado de conservação (§16.3, §16.4):**
 *
 * - `conservacao_bps` — o estado atual. Nasce em 10.000 (100%, "veículo novo").
 * - `teto_conservacao_bps` — até onde a manutenção o traz de volta. Nasce em 10.000 e **cai 500 (5
 *   pontos) a cada manutenção**: é a "vida útil total" que o §16.4 diz que a manutenção corrói. É
 *   também a âncora do teto de revenda.
 * - `manutencoes` — quantas já se fizeram. Redundante com o teto, mas o §16.3 pede o histórico no
 *   registro de placa, e derivá-lo do teto seria adivinhação se um dia a perda por manutenção mudar.
 * - `uso_ativo_seg` — horas de uso ativo, em segundos. O §16.3 as mostra no registro ("312h") e o
 *   §16.4 é explícito: o desgaste é "por horas de uso ativo, **não por tempo desde a fabricação**".
 *   Um veículo parado não envelhece.
 *
 * **`transport_settings` é o painel do operador.** O §16 manda o Ministério "configurar a curva de
 * depreciação", "configurar o limite crítico" e "configurar a perda de vida útil e o teto de
 * revenda" — ou seja, **os números são do operador, não do código**. Mesmo padrão do D-35 (a
 * intervenção de preço). Uma linha só; a semente traz os valores que o usuário decidiu.
 *
 * **`vehicle_listings` é o mercado de usados**, com escrow do Ministério: o comprador paga, os Fert$
 * ficam retidos aqui (`escrow_micro`), o veículo dirige-se até ele, e **a placa muda de dono na
 * chegada** — só então o vendedor recebe. Ver o aditivo 15 do D-60.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->unsignedInteger('conservacao_bps')->default(10_000)->after('level');
            $table->unsignedInteger('teto_conservacao_bps')->default(10_000)->after('conservacao_bps');
            $table->unsignedInteger('manutencoes')->default(0)->after('teto_conservacao_bps');
            $table->unsignedBigInteger('uso_ativo_seg')->default(0)->after('manutencoes');

            /*
             * **A sucata arquiva; não apaga.** Um veículo sucateado sai da frota mas fica no
             * registro, e isso não é escrúpulo de contador — é o que faz duas coisas funcionarem:
             *
             *  - **A placa nunca é reciclada.** O sequencial vem da MAIOR placa já emitida
             *    (`Placas::emitir`); se a linha sumisse, o máximo cairia junto e o próximo veículo
             *    do planeta herdaria a placa do morto. Duas máquinas diferentes com o mesmo número
             *    é o oposto de um registro.
             *  - **Os sucateados podem ser contados.** O §16 pede "volume de veículos registrados,
             *    vendidos e **sucateados** por período" — não há como contar por período o que foi
             *    apagado, nem como saber quando.
             *
             * O `SoftDeletes` no modelo tira os arquivados de toda consulta de frota sem que
             * nenhuma delas precise saber que eles existem.
             */
            $table->softDeletes();
        });

        Schema::create('transport_settings', function (Blueprint $table) {
            $table->id();
            // 0,5% por hora de uso ativo.
            $table->unsignedInteger('desgaste_bps_por_hora')->default(50);
            // O "limite crítico" do §16.4 — que aqui é o PISO de desempenho, não um bloqueio (D-60).
            $table->unsignedInteger('piso_desempenho_bps')->default(2_500);
            // Custo da manutenção, como fração do custo do veículo em recursos (§21.2/§21.3).
            $table->unsignedInteger('manutencao_bps_do_custo')->default(1_000);
            // Quanto o teto de conservação cai a cada manutenção: 5 pontos.
            $table->unsignedInteger('perda_de_teto_bps')->default(500);
            $table->timestamps();
        });

        Schema::create('vehicle_listings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained('vehicles')->cascadeOnDelete();
            $table->foreignId('seller_colony_id')->constrained('colonies')->cascadeOnDelete();
            $table->foreignId('buyer_colony_id')->nullable()->constrained('colonies')->nullOnDelete();
            $table->unsignedBigInteger('price_micro');
            // Fert$ do comprador, retidos pelo Ministério até a placa mudar de dono na chegada.
            $table->unsignedBigInteger('escrow_micro')->default(0);
            $table->enum('status', ['aberto', 'em_transito', 'concluido', 'cancelado'])->default('aberto');
            $table->timestamps();

            // Um veículo não pode estar anunciado duas vezes. O índice é parcial na intenção, mas
            // nem MariaDB nem SQLite têm índice único parcial portátil — quem garante é o domínio,
            // que confere o anúncio aberto sob lock. Este índice é só para a busca da vitrine.
            $table->index(['status', 'vehicle_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_listings');
        Schema::dropIfExists('transport_settings');

        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropSoftDeletes();
            $table->dropColumn(['conservacao_bps', 'teto_conservacao_bps', 'manutencoes', 'uso_ativo_seg']);
        });
    }
};
