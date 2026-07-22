<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A zona neutra vira colmeia de slots (docs/decisoes.md D-144).
 *
 * Até aqui cada estrutura da zona era uma COLUNA de `neutral_zones` (`Domain\Zona\Estruturas::COLUNA`,
 * 13 entradas). Isso trava dois pedidos do usuário: crescimento por nível (não há onde crescer — as
 * 13 sempre existem, desde o nível 1) e repetíveis (uma coluna só guarda um nível). A colônia já
 * resolveu isto no D-59, mas de um jeito diferente: `buildings` já nascia como linha por construção
 * — só faltava a coluna `slot`. Aqui a estrutura de dados em si muda: de coluna para linha.
 *
 * NASCE `zone_structures` (`neutral_zone_id`, `slot`, `type`, `level`), mesma forma de `buildings`.
 * `unique(neutral_zone_id, slot)` — dois módulos no mesmo buraco, nunca.
 *
 * **A colmeia é a MESMA da colônia**: `LINHAS=[4,4,5,4,4,1]`, `TOTAL=22` (`Domain\Zona\ZonaSlots`,
 * espelho de `Domain\Colony\Slots`) — é o "visual como o da colônia" que o usuário pediu, a mesma
 * matemática de layout. O slot 10 (centro) é fixo para o Posto de Comando — como o centro da
 * colônia é fixo para o Depósito Local (D-142): o centro pertence à construção mais aberta/mais
 * essencial.
 *
 * **Backfill determinístico, pensado para ZERO regressão nas zonas já ocupadas em produção**: cada
 * estrutura com coluna > 0 migra para um slot dentro do conjunto que `ZonaSlots` desbloqueia desde
 * o NÍVEL 1 (os 12 primeiros dos 21 livres) — não importa em que nível 1-5 a zona esteja hoje. É uma
 * bijeção com a ordem de `Estruturas::COLUNA` (sem o posto): deposito→0, muralha→1, torre→2,
 * bastiao→3, abrigo→4, refinaria→5, estacionamento→6, cemiterio→7, extracao→8, comunicacao→9,
 * plataforma_de_pouso→11, industria→12.
 *
 * Idempotente e com a ordem de índice/FK do D-59 respeitada (lição paga com um deploy quebrado:
 * criar o índice novo ANTES de derrubar qualquer coisa que cubra a FK).
 */
return new class extends Migration
{
    /** A mesma ordem de `Domain\Zona\Estruturas::COLUNA`, sem o posto — ver o comentário acima. */
    private const BACKFILL_SLOT = [
        'deposito_de_zona_neutra' => 0,
        'muralha_de_perimetro' => 1,
        'torre_de_vigia' => 2,
        'bastiao' => 3,
        'abrigo_de_robos' => 4,
        'refinaria_de_campo' => 5,
        'estacionamento_da_zona' => 6,
        'cemiterio_de_robos' => 7,
        'estrutura_de_extracao' => 8,
        'central_de_comunicacao' => 9,
        'plataforma_de_pouso_da_zona' => 11,
        'industria_siderurgica' => 12,
    ];

    private const COLUNA_ANTIGA = [
        'posto_de_comando' => 'command_post_level',
        'deposito_de_zona_neutra' => 'deposit_level',
        'muralha_de_perimetro' => 'wall_level',
        'torre_de_vigia' => 'watchtower_level',
        'bastiao' => 'bastion_level',
        'abrigo_de_robos' => 'shelter_level',
        'refinaria_de_campo' => 'refinery_level',
        'estacionamento_da_zona' => 'parking_level',
        'cemiterio_de_robos' => 'cemetery_level',
        'estrutura_de_extracao' => 'extraction_level',
        'central_de_comunicacao' => 'communication_level',
        'plataforma_de_pouso_da_zona' => 'landing_pad_level',
        'industria_siderurgica' => 'industry_level',
    ];

    /** O slot fixo do Posto de Comando — o centro da colmeia (`ZonaSlots::POSTO_SLOT`). */
    private const POSTO_SLOT = 10;

    public function up(): void
    {
        if (! Schema::hasTable('zone_structures')) {
            Schema::create('zone_structures', function (Blueprint $t) {
                $t->id();
                $t->foreignId('neutral_zone_id')->constrained('neutral_zones')->cascadeOnDelete();
                $t->unsignedTinyInteger('slot');
                $t->string('type', 40);
                $t->unsignedTinyInteger('level')->default(1);
                $t->timestamps();

                $t->unique(['neutral_zone_id', 'slot']);
            });
        }

        // ── backfill: coluna vira linha ─────────────────────────────────────────────────────────
        if (Schema::hasColumn('neutral_zones', 'command_post_level')) {
            $zonas = DB::table('neutral_zones')->get();
            $agora = now();

            foreach ($zonas as $zona) {
                $jaTem = DB::table('zone_structures')->where('neutral_zone_id', $zona->id)->exists();

                if ($jaTem) {
                    continue;   // migration retomada depois de uma falha — não duplica.
                }

                $linhas = [];

                foreach (self::COLUNA_ANTIGA as $tipo => $coluna) {
                    $nivel = (int) ($zona->{$coluna} ?? 0);

                    if ($nivel <= 0) {
                        continue;
                    }

                    $slot = $tipo === 'posto_de_comando' ? self::POSTO_SLOT : self::BACKFILL_SLOT[$tipo];

                    $linhas[] = [
                        'neutral_zone_id' => $zona->id,
                        'slot' => $slot,
                        'type' => $tipo,
                        'level' => $nivel,
                        'created_at' => $agora,
                        'updated_at' => $agora,
                    ];
                }

                if ($linhas !== []) {
                    DB::table('zone_structures')->insert($linhas);
                }
            }

            Schema::table('neutral_zones', function (Blueprint $t) {
                $t->dropColumn(array_values(self::COLUNA_ANTIGA));
            });
        }

        if (! Schema::hasColumn('zone_build_queue', 'slot')) {
            Schema::table('zone_build_queue', function (Blueprint $t) {
                $t->unsignedTinyInteger('slot')->nullable()->after('structure');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('zone_build_queue', 'slot')) {
            Schema::table('zone_build_queue', function (Blueprint $t) {
                $t->dropColumn('slot');
            });
        }

        if (! Schema::hasColumn('neutral_zones', 'command_post_level')) {
            Schema::table('neutral_zones', function (Blueprint $t) {
                foreach (self::COLUNA_ANTIGA as $coluna) {
                    $t->unsignedTinyInteger($coluna)->default(0);
                }
            });

            if (Schema::hasTable('zone_structures')) {
                $linhas = DB::table('zone_structures')->get();
                $porZona = [];

                foreach ($linhas as $l) {
                    $porZona[$l->neutral_zone_id][self::COLUNA_ANTIGA[$l->type]] = $l->level;
                }

                foreach ($porZona as $zonaId => $colunas) {
                    DB::table('neutral_zones')->where('id', $zonaId)->update($colunas);
                }
            }
        }

        Schema::dropIfExists('zone_structures');
    }
};
