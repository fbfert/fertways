<?php

namespace App\Domain\Drone;

use App\Domain\Transport\Placas;
use App\Exceptions\DomainRuleException;
use App\Models\Building;
use App\Models\Colony;
use App\Models\Ledger;
use App\Models\Vehicle;
use Illuminate\Support\Facades\DB;

/**
 * Fabrica o Drone de Exploração — na OFICINA (D-74).
 *
 * A fábrica foi arbitragem do usuário, e a razão está no próprio GDD: o §21.4 diz que o Quartel só
 * o **armazena e recarrega** — logo ele nasce noutro lugar —, e o custo é publicado em RECURSOS
 * (§4.3), o que é fabricação, não compra. A Oficina já é a fábrica da colônia.
 *
 * **O nível da Oficina é o teto do nível do Drone** — o mesmo desenho do Quartel para unidades
 * (D-66) e da Central de Transportes para a frota (D-60). E é **instantâneo**, pela regra da casa:
 * o freio é o custo, não o relógio.
 *
 * O Drone é um VEÍCULO (§16.1: tem placa, aparece na frota) — mas um que não deprecia (§16.4) e não
 * carrega nada: `capacity` 0, e o `VeiculoSpecs` não o conhece de propósito, para que a máquina de
 * carga o recuse sozinha.
 */
class FabricarDrone
{
    public function __construct(private readonly Placas $placas) {}

    public function handle(Colony $colony, int $nivel): Vehicle
    {
        if (! isset(DroneSpecs::CUSTO[$nivel])) {
            throw new DomainRuleException('nivel_invalido', 'O Drone existe do nível 1 ao 5 (§21.4).');
        }

        return DB::transaction(function () use ($colony, $nivel) {
            $colony = Colony::whereKey($colony->id)->lockForUpdate()->firstOrFail();

            $oficina = Building::where('colony_id', $colony->id)
                ->where('type', 'oficina')
                ->where('level', '>=', 1)
                ->first();

            if (! $oficina) {
                throw new DomainRuleException(
                    'sem_oficina',
                    'Erga uma Oficina: é ela que fabrica o Drone (D-74). O Quartel só o guarda e recarrega (§21.4).',
                );
            }

            if ($nivel > $oficina->level) {
                throw new DomainRuleException(
                    'nivel_acima_da_oficina',
                    "A Oficina está no nível {$oficina->level}: não fabrica Drone nível {$nivel}.",
                );
            }

            /*
             * O §05 destrava "drone nível 2" no marco 10 (Pioneiro) — o nível 1 nunca teve gate
             * (D-74/D-75, com a precedência do §05 sobre o §03). Depois das checagens da Oficina:
             * "construa a Oficina" explica o que fazer melhor do que "suba de marco".
             */
            if ($nivel >= 2) {
                app(\App\Domain\Marco\ExigirMarco::class)->exigir($colony, 10, 'Fabricar Drone nível 2 ou maior');
            }

            $estoque = $colony->resources()->lockForUpdate()->get()->keyBy('resource_type');

            foreach (DroneSpecs::CUSTO[$nivel] as $recurso => $qtd) {
                if (($estoque->get($recurso)?->amount ?? 0) < $qtd) {
                    throw new DomainRuleException(
                        'recursos_insuficientes',
                        "Faltam recursos: {$recurso} exige {$qtd}, você tem ".($estoque->get($recurso)?->amount ?? 0).'.',
                    );
                }
            }

            foreach (DroneSpecs::CUSTO[$nivel] as $recurso => $qtd) {
                $estoque[$recurso]->decrement('amount', $qtd);

                Ledger::create([
                    'colony_id' => $colony->id,
                    'type' => 'fabricar_drone',
                    'amount' => -$qtd,
                    'resource_type' => $recurso,
                    'ref' => "oficina:drone:n{$nivel}",
                    'created_at' => now(),
                ]);
            }

            $drone = $colony->vehicles()->create([
                'type' => DroneSpecs::TIPO,
                'level' => $nivel,
                'status' => 'ocioso',
                // Não carrega nada — o Drone olha, não transporta. É também o que faz o despacho
                // de carga recusá-lo sem precisar saber que ele existe.
                'capacity' => 0,
            ]);

            // §16.3: todo veículo civil é registrado — o Drone tem placa como qualquer um.
            $this->placas->registrar($drone);

            return $drone->fresh();
        });
    }
}
