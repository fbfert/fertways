<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A guerra do §27 e do §28.10 — a Fatia 2 do D-52. Ver docs/decisoes.md D-66.
 *
 * Três coisas nascem aqui, e uma morre.
 *
 * NASCE `war_settings`: os bônus defensivos que o §27.3 chama de "valores configuráveis" e as
 * duas chances que o §28.10 manda calcular e nunca publica. São do OPERADOR, não do código —
 * o mesmo gancho do §16 que destravou a depreciação no D-60.
 *
 * ⚠️ As colunas têm DEFAULT DE VERDADE, e isso é deliberado. O `transport_settings` do D-60
 * nasceu vazio e deixou a depreciação INERTE até alguém lembrar do seeder — um esquecimento
 * silencioso, que o RETOMAR hoje lista como armadilha conhecida. Aqui, esquecer o seeder não
 * pode apagar a guerra: o `firstOrCreate([])` já sai com os números do D-66.
 *
 * NASCE `units`: unidades de verdade, com HP. O §27.6 manda as baixas se distribuírem entre as
 * unidades presentes, os sobreviventes voltarem FERIDOS e as de HP zero morrerem de vez.
 *
 * NASCE `combats`: a batalha por rodadas de 10 minutos do §27.5, compartilhada pelos quatro
 * tipos de ataque.
 *
 * MORRE `neutral_zones.garrison`: era um `int` com a contagem de robôs, e um inteiro não sabe
 * quem está ferido. A guarnição passa a ser linhas de `units`. Produção tem ZERO zonas ocupadas
 * (conferido em 2026-07-12), então o backfill é vazio lá — mas ele existe e é exercitado pelos
 * testes e pelo e2e, que ocupam zonas.
 *
 * ⚠️ Idempotente de propósito (lição do D-59): a suíte roda em SQLite e a produção é MariaDB.
 * Se um passo falhar no meio, o `migrate` seguinte retoma de onde parou em vez de tentar recriar
 * o que já existe.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('war_settings')) {
            Schema::create('war_settings', function (Blueprint $t) {
                $t->id();

                // Bônus defensivos do §27.3 — ADITIVOS. As três juntas dobram a defesa (D-66).
                $t->unsignedInteger('muralha_bonus_bps')->default(2000);   // +20%
                $t->unsignedInteger('torre_bonus_bps')->default(3000);     // +30%
                $t->unsignedInteger('bastiao_bonus_bps')->default(5000);   // +50%

                // Torre de Vigia detecta o Infiltrador, por rodada: bps × nível dela (§28.10).
                $t->unsignedInteger('torre_deteccao_bps_por_nivel')->default(1500);  // 15%/nível

                // Predador contra o Abrigo de Robôs (§28.10): base ± por nível de diferença,
                // preso entre o piso e o teto. Empate dá moeda justa; nunca há certeza.
                $t->unsignedInteger('predador_base_bps')->default(5000);       // 50%
                $t->unsignedInteger('predador_por_nivel_bps')->default(1000);  // ±10%/nível
                $t->unsignedInteger('predador_min_bps')->default(1000);        // nunca < 10%
                $t->unsignedInteger('predador_max_bps')->default(9000);        // nunca > 90%

                // O governo vende Nióbio (D-66), sem o qual a Sentinela é inalcançável.
                // Preço de referência do §06 (0,3163 Fert$) × 10, em micro-Fert$.
                $t->unsignedBigInteger('niobio_preco_micro')->default(3_163_000);

                $t->timestamps();
            });
        }

        if (! Schema::hasTable('units')) {
            Schema::create('units', function (Blueprint $t) {
                $t->id();

                // Uma unidade está em CASA (colônia) ou NA ZONA. Nunca nos dois, nunca em nenhum.
                $t->foreignId('colony_id')->nullable()->constrained()->cascadeOnDelete();
                $t->foreignId('zone_id')->nullable()->constrained('neutral_zones')->cascadeOnDelete();
                $t->unsignedBigInteger('combat_id')->nullable();

                $t->string('type', 20);   // sentinela | robo_minerador | infiltrador | predador
                $t->unsignedTinyInteger('level')->default(1);

                // HP em basis points: 10000 = inteira, 0 = destruída (§27.6). Guardar fração
                // evita que uma rodada de 3% de dano some por truncamento.
                $t->unsignedSmallInteger('hp_bps')->default(10000);

                // casa | marchando | na_zona | em_combate | voltando
                $t->string('status', 16)->default('casa');
                $t->timestamp('arrives_at')->nullable();

                $t->timestamps();

                $t->index(['zone_id', 'status']);
                $t->index(['colony_id', 'type', 'status']);
                $t->index('combat_id');
            });
        }

        if (! Schema::hasTable('combats')) {
            Schema::create('combats', function (Blueprint $t) {
                $t->id();

                $t->foreignId('zone_id')->constrained('neutral_zones')->cascadeOnDelete();
                $t->foreignId('attacker_colony_id')->constrained('colonies')->cascadeOnDelete();
                $t->foreignId('defender_colony_id')->nullable()->constrained('colonies')->nullOnDelete();

                // invasao | cerco | sabotagem | apreensao — os quatro do §27 e do §28.10.
                $t->string('tipo', 12);

                // marchando | em_curso | vitoria_atacante | vitoria_defensor | rendido
                // | abandonado | expirado | repelido
                $t->string('status', 20)->default('marchando');

                $t->unsignedInteger('rodada')->default(0);

                // Fim da marcha de combate — 1,3× mais lenta que a civil (§27.4).
                $t->timestamp('chega_at');
                $t->timestamp('proxima_rodada_at')->nullable();

                // Cerco: as 48 h para romper ou render-se. Apreensão: as 24 h do resgate.
                $t->timestamp('prazo_at')->nullable();

                // Sabotagem e apreensão miram UMA estrutura da zona (o "Módulo Operacional").
                $t->string('alvo', 32)->nullable();

                // O saque, as baixas, o que foi detectado. Append-only na prática.
                $t->json('resultado')->nullable();

                $t->timestamps();

                // O tick acorda os combates cuja rodada venceu.
                $t->index(['status', 'proxima_rodada_at']);

                // O cooldown de 48 h do §27.10 se responde daqui: mesmo atacante, mesma zona.
                $t->index(['zone_id', 'attacker_colony_id', 'created_at']);
            });
        }

        // As quatro estruturas de defesa (§27.3) e o estado dos dois ataques que não são combate.
        Schema::table('neutral_zones', function (Blueprint $t) {
            if (! Schema::hasColumn('neutral_zones', 'wall_level')) {
                $t->unsignedTinyInteger('wall_level')->default(0)->after('deposit_level');
            }
            if (! Schema::hasColumn('neutral_zones', 'watchtower_level')) {
                $t->unsignedTinyInteger('watchtower_level')->default(0)->after('wall_level');
            }
            if (! Schema::hasColumn('neutral_zones', 'bastion_level')) {
                $t->unsignedTinyInteger('bastion_level')->default(0)->after('watchtower_level');
            }
            if (! Schema::hasColumn('neutral_zones', 'shelter_level')) {
                $t->unsignedTinyInteger('shelter_level')->default(0)->after('bastion_level');
            }
            // Início do cerco. Nulo = não cercada. Após 30 min o depósito para de aceitar.
            if (! Schema::hasColumn('neutral_zones', 'sieged_at')) {
                $t->timestamp('sieged_at')->nullable()->after('shelter_level');
            }
            // As estruturas desligadas pelo Predador e pelo Infiltrador, até resgate ou reparo.
            if (! Schema::hasColumn('neutral_zones', 'modules_offline')) {
                $t->json('modules_offline')->nullable()->after('sieged_at');
            }
        });

        // ── o backfill: o `garrison` int vira linhas de `units` ─────────────────────────────
        //
        // Produção tem zero zonas ocupadas, então lá isto não move nada. Nos testes e no e2e,
        // que ocupam zonas, é o que preserva a guarnição existente.
        if (Schema::hasColumn('neutral_zones', 'garrison')) {
            $zonas = DB::table('neutral_zones')
                ->where('garrison', '>', 0)
                ->whereNotNull('owner_colony_id')
                ->get(['id', 'garrison']);

            $agora = now();

            foreach ($zonas as $z) {
                // Só semeia se ainda não houver robôs — o backfill roda uma vez, mas pode ser
                // retomado depois de uma falha, e não pode duplicar a guarnição.
                $jaTem = DB::table('units')
                    ->where('zone_id', $z->id)
                    ->where('type', 'robo_minerador')
                    ->count();

                $faltam = max(0, $z->garrison - $jaTem);

                if ($faltam === 0) {
                    continue;
                }

                DB::table('units')->insert(array_fill(0, $faltam, [
                    'zone_id' => $z->id,
                    'colony_id' => null,
                    'type' => 'robo_minerador',
                    'level' => 1,
                    'hp_bps' => 10000,
                    'status' => 'na_zona',
                    'created_at' => $agora,
                    'updated_at' => $agora,
                ]));
            }

            // O `garrison` não tem índice nem FK, então derrubá-lo não cai na armadilha do
            // D-59 (o InnoDB só exige índice que COMECE pela coluna de uma FK).
            Schema::table('neutral_zones', function (Blueprint $t) {
                $t->dropColumn('garrison');
            });
        }
    }

    public function down(): void
    {
        // Devolve o `garrison`, contando os robôs de volta — senão a Fatia 1 perderia a guarnição.
        if (! Schema::hasColumn('neutral_zones', 'garrison')) {
            Schema::table('neutral_zones', function (Blueprint $t) {
                $t->unsignedSmallInteger('garrison')->default(0)->after('command_post_level');
            });

            if (Schema::hasTable('units')) {
                $contagem = DB::table('units')
                    ->where('type', 'robo_minerador')
                    ->whereNotNull('zone_id')
                    ->selectRaw('zone_id, count(*) as n')
                    ->groupBy('zone_id')
                    ->pluck('n', 'zone_id');

                foreach ($contagem as $zoneId => $n) {
                    DB::table('neutral_zones')->where('id', $zoneId)->update(['garrison' => $n]);
                }
            }
        }

        Schema::table('neutral_zones', function (Blueprint $t) {
            foreach (['wall_level', 'watchtower_level', 'bastion_level', 'shelter_level',
                      'sieged_at', 'modules_offline'] as $c) {
                if (Schema::hasColumn('neutral_zones', $c)) {
                    $t->dropColumn($c);
                }
            }
        });

        Schema::dropIfExists('combats');
        Schema::dropIfExists('units');
        Schema::dropIfExists('war_settings');
    }
};
