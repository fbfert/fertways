<?php

namespace App\Domain\Zona;

use App\Exceptions\DomainRuleException;
use App\Models\Colony;
use App\Models\Ledger;
use App\Models\NeutralZone;
use App\Models\Unit;
use Illuminate\Support\Facades\DB;

/**
 * Upgrade de zona (GDD §07/§27.12; docs/decisoes.md D-84).
 *
 * **Debita direto da colônia, como a ocupação — não do canteiro.** `ConstruirNaZona` (D-67) exige
 * material entregue por veículo de propósito: "a ocupação é o ato de chegar, as obras são o ato de
 * investir". O upgrade não é uma estrutura entre outras — é o Posto de Comando crescendo, e o
 * Posto nasce com a ocupação, também debitado direto (D-52). O mesmo ato, mais tarde.
 *
 * Guarnição escala junto: `NeutralZone::guarnicaoAlvo()` sobe pela curva 1,65×, e o upgrade compra
 * a diferença de Robôs Mineradores na hora, como a ocupação compra os 20 iniciais — não existe
 * hoje uma ação separada de "recrutar mais guarnição".
 *
 * O nível só sobe no tick (`ConcluirUpgradeDaZona`), como as obras de estrutura — este método só
 * cobra e arma o relógio.
 */
class SubirNivelDaZona
{
    public function handle(Colony $colony, NeutralZone $zona): NeutralZone
    {
        return DB::transaction(function () use ($colony, $zona) {
            $zona = NeutralZone::whereKey($zona->id)->lockForUpdate()->firstOrFail();

            if ($zona->owner_colony_id !== $colony->id) {
                throw new DomainRuleException('zona_nao_e_sua', 'Esta zona neutra não é sua.');
            }

            if ($zona->cercada()) {
                throw new DomainRuleException(
                    'zona_cercada',
                    'A zona está cercada: não se investe nela sob sítio. Rompa o cerco ou espere as 48 h.',
                );
            }

            if ($zona->level_target !== null) {
                throw new DomainRuleException('upgrade_em_curso', 'Já há um upgrade em curso nesta zona.');
            }

            if ($zona->level >= NeutralZone::NIVEL_MAXIMO) {
                throw new DomainRuleException('nivel_maximo', 'Esta zona já está no nível máximo (5).');
            }

            $alvo = $zona->level + 1;
            $colony = Colony::whereKey($colony->id)->lockForUpdate()->firstOrFail();

            $custo = $this->custoDeRecursos($zona, $alvo);
            $this->debitarRecursos($colony, $custo, $zona, $alvo);
            $this->debitarFert($colony, NeutralZone::custoDeUpgrade($alvo)['fert'], $zona, $alvo);
            $this->reforcarGuarnicao($zona, $alvo);

            $zona->update([
                'level_target' => $alvo,
                'level_upgrade_finishes_at' => now()->addHours(NeutralZone::horasDeUpgrade($alvo)),
            ]);

            return $zona->fresh();
        });
    }

    /**
     * Metal Bruto do upgrade mais os Robôs Mineradores que faltam para a guarnição-alvo do nível.
     *
     * @return array<string,int>
     */
    private function custoDeRecursos(NeutralZone $zona, int $alvo): array
    {
        $faltam = max(0, NeutralZone::guarnicaoAlvo($alvo) - $zona->guarnicao());

        $custo = ['metal_bruto' => NeutralZone::custoDeUpgrade($alvo)['metal_bruto']];

        if ($faltam > 0) {
            $custoRobo = json_decode(
                DB::table('building_specs')->where('building_type', 'robo_minerador')->where('level', 1)->value('cost_json') ?? '{}',
                true,
            );

            foreach ($custoRobo as $recurso => $qtd) {
                $custo[$recurso] = ($custo[$recurso] ?? 0) + $qtd * $faltam;
            }
        }

        return $custo;
    }

    /** @param array<string,int> $custo */
    private function debitarRecursos(Colony $colony, array $custo, NeutralZone $zona, int $alvo): void
    {
        $estoque = $colony->resources()->lockForUpdate()->get()->keyBy('resource_type');

        $faltam = [];
        foreach ($custo as $recurso => $qtd) {
            $tem = $estoque->get($recurso)?->amount ?? 0;
            if ($tem < $qtd) {
                $faltam[] = "{$recurso}: faltam ".($qtd - $tem);
            }
        }

        if ($faltam !== []) {
            throw new DomainRuleException(
                'recursos_insuficientes',
                "Faltam recursos para o upgrade ao nível {$alvo}: ".implode(' · ', $faltam).'.',
            );
        }

        foreach ($custo as $recurso => $qtd) {
            $estoque[$recurso]->decrement('amount', $qtd);

            Ledger::create([
                'colony_id' => $colony->id,
                'type' => 'custo_upgrade_zona',
                'amount' => -$qtd,
                'resource_type' => $recurso,
                'ref' => "zona:{$zona->id}:nivel:{$alvo}",
                'created_at' => now(),
            ]);
        }
    }

    private function debitarFert(Colony $colony, int $fert, NeutralZone $zona, int $alvo): void
    {
        $micro = $fert * 1_000_000;

        $pagou = Colony::whereKey($colony->id)
            ->where('fert_micro', '>=', $micro)
            ->decrement('fert_micro', $micro);

        if ($pagou === 0) {
            throw new DomainRuleException(
                'fert_insuficiente',
                "Faltam Fert\$ para o upgrade ao nível {$alvo}: exige {$fert}.",
            );
        }

        Ledger::create([
            'colony_id' => $colony->id,
            'type' => 'custo_upgrade_zona',
            'amount' => -$micro,
            'resource_type' => null,
            'ref' => "zona:{$zona->id}:nivel:{$alvo}",
            'created_at' => now(),
        ]);
    }

    private function reforcarGuarnicao(NeutralZone $zona, int $alvo): void
    {
        $faltam = max(0, NeutralZone::guarnicaoAlvo($alvo) - $zona->guarnicao());

        if ($faltam === 0) {
            return;
        }

        $agora = now();

        Unit::insert(array_fill(0, $faltam, [
            'zone_id' => $zona->id,
            'colony_id' => null,
            'type' => 'robo_minerador',
            'level' => 1,
            'hp_bps' => Unit::INTEIRA,
            'status' => 'na_zona',
            'created_at' => $agora,
            'updated_at' => $agora,
        ]));
    }
}
