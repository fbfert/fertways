<?php

namespace App\Domain\Logistics;

use App\Exceptions\DomainRuleException;
use App\Models\Colony;
use App\Models\Combat;
use App\Models\NeutralZone;
use App\Models\Unit;
use App\Models\Vehicle;
use Illuminate\Support\Facades\DB;

/**
 * A Nave de Transporte Planetária leva Robôs Mineradores de uma zona sua para outra (§17.5, D-281).
 *
 * *"Transporta Robôs Mineradores entre diferentes zonas neutras do mesmo colono."* Até aqui um robô
 * só ia de casa para uma zona, marchando (D-70); uma vez lá, ficava. Com a Nave, a guarnição de
 * robôs pode mudar de zona sem voltar para casa.
 *
 * ## Como funciona
 *
 * - A Nave tem de estar **estacionada na zona de origem** (ela chega lá com um voo vazio, como
 *   qualquer veículo, D-109). Os robôs embarcam ali, e não há perna a mais.
 * - As duas zonas são suas e têm **Plataforma de Pouso**; nenhuma está **cercada** (§28.10: nada
 *   entra nem sai).
 * - Ela leva **até quatro robôs por voo**, um por m³ dos quatro que ela tem (§28.9). O GDD não diz
 *   quantos robôs cabem; ancorar no volume é a escolha minha, e o número está em `ROBOS_POR_VOO`.
 * - O voo queima o Gelo de Metano da perna (§28.9) e termina com a Nave estacionada no destino.
 * - Os robôs saem da origem **agora** e chegam com a Nave: marcham com o mesmo `arrives_at` e entram
 *   na zona pelo `ChegarReforcos`, como um reforço. No ar, não guarnecem nenhuma das duas.
 */
class TransportarRobos
{
    public const ROBOS_POR_VOO = 4;

    public function __construct(private readonly DespacharVeiculo $despachar) {}

    /** @param  list<int>  $unitIds  Robôs Mineradores na zona onde a Nave está */
    public function handle(Colony $colonia, Vehicle $nave, NeutralZone $destino, array $unitIds): Vehicle
    {
        if ($nave->type !== Vehicle::NAVE) {
            throw new DomainRuleException('nao_e_nave', 'Só a Nave de Transporte Planetária leva robôs entre zonas.');
        }

        if ($nave->colony_id !== $colonia->id) {
            throw new DomainRuleException('veiculo_de_outra_colonia', 'Este veículo não é seu.');
        }

        if (! $nave->naZona()) {
            throw new DomainRuleException(
                'nave_fora_da_zona',
                'A Nave precisa estar estacionada na zona de onde os robôs saem. Leve-a até lá primeiro.',
            );
        }

        $unitIds = array_values(array_unique(array_map('intval', $unitIds)));

        if ($unitIds === [] || count($unitIds) > self::ROBOS_POR_VOO) {
            throw new DomainRuleException(
                'robos_por_voo',
                'A Nave leva de 1 a '.self::ROBOS_POR_VOO.' Robôs Mineradores por voo.',
            );
        }

        return DB::transaction(function () use ($colonia, $nave, $destino, $unitIds) {
            $origem = NeutralZone::whereKey($nave->destination_id)->lockForUpdate()->firstOrFail();
            $destino = NeutralZone::whereKey($destino->id)->lockForUpdate()->firstOrFail();

            foreach ([$origem, $destino] as $zona) {
                if ($zona->owner_colony_id !== $colonia->id) {
                    throw new DomainRuleException('zona_nao_e_sua', 'As duas zonas precisam ser suas.');
                }

                if ($zona->cercada()) {
                    throw new DomainRuleException('zona_cercada', 'Uma das zonas está cercada: nada entra nem sai, nem pelo céu.');
                }

                DespacharVeiculo::exigirPlataformaNaZona($nave, $zona);

                /*
                 * D-284: zona em batalha não perde nem ganha guarnição pelo céu. Os defensores ficam
                 * `na_zona` durante o combate; sem esta trava, a Nave os evacuava no meio da invasão e
                 * o atacante seguia tomando dano de uma força que já não estava lá. A Capitulação já
                 * recusava zona em combate pelo mesmo motivo.
                 */
                if (Combat::where('zone_id', $zona->id)->whereIn('status', ['marchando', 'em_curso'])->exists()) {
                    throw new DomainRuleException('zona_em_combate', 'Uma das zonas está sob ataque: os robôs não embarcam nem desembarcam no meio da batalha.');
                }
            }

            $robos = Unit::whereIn('id', $unitIds)
                ->where('zone_id', $origem->id)
                ->where('type', 'robo_minerador')
                ->where('status', 'na_zona')
                ->whereNull('combat_id')
                ->where('hp_bps', '>', 0)
                ->lockForUpdate()
                ->get();

            if ($robos->count() !== count($unitIds)) {
                throw new DomainRuleException(
                    'robos_indisponiveis',
                    'Escolha Robôs Mineradores que estejam na zona onde a Nave está, fora de combate.',
                );
            }

            $nave = $this->despachar->reposicionarVazio($colonia, $nave, 'zona_neutra', $destino->id);

            Unit::whereIn('id', $robos->pluck('id'))->update([
                'zone_id' => $destino->id,
                'status' => 'marchando',
                'arrives_at' => $nave->arrives_at,
            ]);

            return $nave;
        });
    }
}
