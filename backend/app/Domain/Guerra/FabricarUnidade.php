<?php

namespace App\Domain\Guerra;

use App\Exceptions\DomainRuleException;
use App\Models\Building;
use App\Models\Colony;
use App\Models\Ledger;
use App\Models\Unit;
use Illuminate\Support\Facades\DB;

/**
 * Fabrica unidades no Quartel (GDD §27.1, §28.7; docs/decisoes.md D-66).
 *
 * O Quartel estava no catálogo desde sempre e **não fazia nada** — era uma das sete construções que
 * o D-59 marcou como "promessa pura". A Sentinela é produzida nele (§27.1, textual), e sem isto
 * nenhuma unidade de combate jamais existiria: até o D-66, a única forma de uma unidade nascer era a
 * guarnição de 20 robôs que a ocupação de zona cria.
 *
 * **O nível do Quartel é o teto do nível da unidade.** Não está no GDD. É a leitura óbvia de uma
 * fábrica que tem níveis e produz coisas que têm níveis, e é o mesmo desenho da Central de
 * Transportes do D-60 (o nível dela é o teto da frota). Sem um teto, um Quartel nível 1 faria
 * Sentinelas nível 5, e os níveis do Quartel não serviriam para nada.
 *
 * **É instantâneo**, e isso não é decisão nova: o Robô Minerador, o Infiltrador e o Predador já
 * tinham `build_time_seconds = 0` desde sempre. O freio do exército é o **Nióbio**, não o relógio.
 */
class FabricarUnidade
{
    /** O que o Quartel produz. O Robô Minerador também sai daqui — o §28.7 o lista no Quartel. */
    public const TIPOS = ['sentinela', 'robo_minerador', 'infiltrador', 'predador'];

    public function handle(Colony $colony, string $tipo, int $nivel, int $quantidade): int
    {
        if (! in_array($tipo, self::TIPOS, true)) {
            throw new DomainRuleException('tipo_invalido', "O Quartel não produz {$tipo}.");
        }

        if ($quantidade < 1) {
            throw new DomainRuleException('quantidade_invalida', 'Fabrique ao menos uma unidade.');
        }

        return DB::transaction(function () use ($colony, $tipo, $nivel, $quantidade) {
            $colony = Colony::whereKey($colony->id)->lockForUpdate()->firstOrFail();

            $quartel = Building::where('colony_id', $colony->id)
                ->where('type', 'quartel')
                ->where('level', '>=', 1)
                ->first();

            if (! $quartel) {
                throw new DomainRuleException(
                    'sem_quartel',
                    'Erga um Quartel: é lá que as unidades são produzidas (§27.1).',
                );
            }

            if ($nivel < 1 || $nivel > $quartel->level) {
                throw new DomainRuleException(
                    'nivel_acima_do_quartel',
                    "O Quartel está no nível {$quartel->level}: não produz unidade nível {$nivel}.",
                );
            }

            $custo = json_decode(
                DB::table('building_specs')
                    ->where('building_type', $tipo)
                    ->where('level', $nivel)
                    ->value('cost_json') ?? '{}',
                true,
            );

            if ($custo === []) {
                throw new DomainRuleException('sem_custo', "O catálogo não tem {$tipo} nível {$nivel}.");
            }

            $estoque = $colony->resources()->lockForUpdate()->get()->keyBy('resource_type');

            foreach ($custo as $recurso => $qtd) {
                $total = $qtd * $quantidade;
                $tem = $estoque->get($recurso)?->amount ?? 0;

                if ($tem < $total) {
                    throw new DomainRuleException(
                        'recursos_insuficientes',
                        "Faltam recursos: {$recurso} exige {$total}, você tem {$tem}."
                        // O Nióbio é o freio de propósito (D-66). Se for ele que falta, o colono
                        // compra do governo — e é por isso que a mensagem diz de onde vem.
                        .($recurso === 'niobio_alienigena'
                            ? ' Compre Nióbio do governo, na Capital.'
                            : ''),
                    );
                }
            }

            foreach ($custo as $recurso => $qtd) {
                $total = $qtd * $quantidade;
                $estoque[$recurso]->decrement('amount', $total);

                Ledger::create([
                    'colony_id' => $colony->id,
                    'type' => 'fabricar_unidade',
                    'amount' => -$total,
                    'resource_type' => $recurso,
                    'ref' => "quartel:{$tipo}:n{$nivel}x{$quantidade}",
                    'created_at' => now(),
                ]);
            }

            $agora = now();

            app(\App\Domain\Missoes\Progresso::class)->registrar($colony->id, 'fabricar_unidade', $quantidade);

            Unit::insert(array_fill(0, $quantidade, [
                'colony_id' => $colony->id,
                'zone_id' => null,
                'type' => $tipo,
                'level' => $nivel,
                'hp_bps' => Unit::INTEIRA,
                'status' => 'casa',
                'created_at' => $agora,
                'updated_at' => $agora,
            ]));

            return $quantidade;
        });
    }
}
