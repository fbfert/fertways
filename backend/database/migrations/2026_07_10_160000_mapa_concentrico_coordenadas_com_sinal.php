<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * O mapa concêntrico do D-51.
 *
 * A grade passa de 100×100 com a Capital em (50,50) para 101×101 com a Capital em (0,0) e
 * coordenadas inteiras **com sinal** (−50 a +50). Um `tinyint unsigned` não guarda −50; a coluna
 * vira `tinyint` com sinal (−128..127, folga de sobra).
 *
 * **A conversão das linhas existentes é um deslocamento de −50** em cada eixo. É bijetivo: o
 * intervalo antigo 0..99 vira −50..49, a Capital conceitual (50,50) vira (0,0), e nenhuma colônia
 * colide com outra que já não colidisse. O `unique(x,y)` é derrubado antes do UPDATE e recriado
 * depois: um único UPDATE que desloca todas as linhas poderia bater numa violação transitória de
 * unicidade (o MariaDB confere linha a linha, e (60,60)→(10,10) colide com um (10,10) ainda não
 * deslocado). Sem o índice durante o shift, não há com o que colidir.
 *
 * O deslocamento **não** realoca as colônias para slots de founder — isso é uma operação de dados
 * à parte, guardada e conferida contra veículos ociosos (D-51, Fatia 2). Aqui só se preserva a
 * consistência do mapa entre a migration e a realocação. Em DEV a tabela está vazia e o shift é
 * inócuo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('colonies', fn (Blueprint $t) => $t->dropUnique(['x', 'y']));

        Schema::table('colonies', function (Blueprint $t) {
            // `tinyInteger` = com sinal. Mantém NOT NULL (a coluna já era obrigatória).
            $t->tinyInteger('x')->nullable(false)->change();
            $t->tinyInteger('y')->nullable(false)->change();
        });

        // Sem o índice único no ar, o deslocamento não corre risco de colisão transitória.
        DB::table('colonies')->update([
            'x' => DB::raw('x - '.self::DESLOCAMENTO),
            'y' => DB::raw('y - '.self::DESLOCAMENTO),
        ]);

        Schema::table('colonies', fn (Blueprint $t) => $t->unique(['x', 'y']));
    }

    public function down(): void
    {
        Schema::table('colonies', fn (Blueprint $t) => $t->dropUnique(['x', 'y']));

        // Desfaz o deslocamento antes de voltar ao tipo sem sinal — senão um valor negativo
        // estouraria para o outro extremo do `tinyint unsigned`.
        DB::table('colonies')->update([
            'x' => DB::raw('x + '.self::DESLOCAMENTO),
            'y' => DB::raw('y + '.self::DESLOCAMENTO),
        ]);

        Schema::table('colonies', function (Blueprint $t) {
            $t->unsignedTinyInteger('x')->nullable(false)->change();
            $t->unsignedTinyInteger('y')->nullable(false)->change();
        });

        Schema::table('colonies', fn (Blueprint $t) => $t->unique(['x', 'y']));
    }

    /** Capital de (50,50) para (0,0): o mesmo deslocamento nos dois eixos. */
    private const DESLOCAMENTO = 50;
};
