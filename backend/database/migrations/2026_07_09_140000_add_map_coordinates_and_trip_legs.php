<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Logística física (GDD §16, §21, §25).
 *
 * O GDD exige que "a posição no mapa importe" (§25.6) e põe o Mercado Central "no núcleo do
 * mapa" (§25.8), mas **nunca define a geometria do mapa**: não há sistema de coordenadas, nem
 * métrica de distância, nem tamanho. Decisão do usuário (2026-07-09, ver docs/decisoes.md D-29):
 * grade quadrada 100×100, Capital em (50,50), distância euclidiana arredondada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('colonies', function (Blueprint $t) {
            $t->unsignedTinyInteger('x')->nullable()->after('name');
            $t->unsignedTinyInteger('y')->nullable()->after('x');
        });

        /*
         * Colônias fundadas antes desta migration não têm coordenada. O backfill precisa ser
         * determinístico (mesma entrada, mesma saída) para que rodar a migration duas vezes em
         * ambientes diferentes não produza mapas divergentes. 37 e 61 são coprimos de 100, então
         * a sequência varre bem a grade; em caso de colisão, anda uma célula por vez.
         */
        $ocupadas = [];
        foreach (DB::table('colonies')->orderBy('id')->get(['id']) as $colonia) {
            $x = ($colonia->id * 37) % 100;
            $y = ($colonia->id * 61) % 100;

            while (isset($ocupadas["$x:$y"]) || ($x === 50 && $y === 50)) {
                $x = ($x + 1) % 100;
                if ($x === 0) {
                    $y = ($y + 1) % 100;
                }
            }

            $ocupadas["$x:$y"] = true;
            DB::table('colonies')->where('id', $colonia->id)->update(['x' => $x, 'y' => $y]);
        }

        // Só depois do backfill: com linhas sem coordenada o índice único aceitaria vários NULLs,
        // e a coluna precisa ser obrigatória daqui para frente.
        Schema::table('colonies', function (Blueprint $t) {
            $t->unsignedTinyInteger('x')->nullable(false)->change();
            $t->unsignedTinyInteger('y')->nullable(false)->change();
            $t->unique(['x', 'y']);
        });

        Schema::table('vehicles', function (Blueprint $t) {
            // Trecho corrente da viagem. `ida` leva a carga; `volta` traz o veículo de volta,
            // e só no fim dela ele volta a ficar `ocioso` (GDD §25.5).
            $t->string('leg', 10)->nullable()->after('status');
            // Guardado para auditoria: recalcular a distância depois exigiria a posição da
            // colônia no momento do despacho, que pode mudar.
            $t->unsignedSmallInteger('distance_slots')->nullable()->after('destination_id');
        });
    }

    public function down(): void
    {
        Schema::table('colonies', fn (Blueprint $t) => $t->dropColumn(['x', 'y']));
        Schema::table('vehicles', fn (Blueprint $t) => $t->dropColumn(['leg', 'distance_slots']));
    }
};
