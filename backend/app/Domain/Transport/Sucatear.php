<?php

namespace App\Domain\Transport;

use App\Exceptions\DomainRuleException;
use App\Models\Colony;
use App\Models\Vehicle;
use App\Models\VehicleListing;
use Illuminate\Support\Facades\DB;

/**
 * Sucatear um veículo (D-60, fatia 2).
 *
 * **O GDD conta sucateados** (o painel do §16 pede "volume de veículos registrados, vendidos e
 * **sucateados** por período") mas **nunca diz o que mata um veículo**. E, como o D-60 decidiu que o
 * veículo **nunca trava**, nada o mata sozinho: um caminhão a 0% de conservação ainda anda, a 25% do
 * desempenho, para sempre.
 *
 * Então a decisão do usuário é a única que sobra, e é a certa: **sucatear é um ato do dono, a
 * qualquer momento.** Nada some da frota sem ele mandar. **Sem devolução de recursos** — o ferro
 * velho não paga nada.
 *
 * O que o dono ganha ao sucatear é a **vaga**: uma carcaça que anda a 25% ainda ocupa uma das poucas
 * vagas da Central de Transportes, e é essa disputa que dá sentido ao botão. O relatório de
 * sucateados passa então a medir uma **decisão humana**, que é mais interessante de ler do que um
 * relógio de vida útil.
 */
class Sucatear
{
    public function handle(Colony $colony, Vehicle $veiculo): void
    {
        if ($veiculo->colony_id !== $colony->id) {
            throw new DomainRuleException('veiculo_de_outra_colonia', 'Este veículo não é seu.');
        }

        if ($veiculo->status !== 'ocioso') {
            throw new DomainRuleException(
                'veiculo_em_rota',
                'O veículo está em rota. Espere-o voltar ao pátio para sucatear.',
            );
        }

        DB::transaction(function () use ($veiculo) {
            $veiculo = Vehicle::whereKey($veiculo->id)->lockForUpdate()->firstOrFail();

            /*
             * Um veículo anunciado no mercado de usados não pode virar sucata pelas costas de quem
             * está de olho nele. O anúncio cai primeiro — e como o escrow só existe depois de
             * alguém comprar (e aí o veículo já não está `ocioso`), aqui não há Fert$ retido a
             * devolver: só um anúncio aberto a cancelar.
             */
            VehicleListing::where('vehicle_id', $veiculo->id)
                ->where('status', 'aberto')
                ->update(['status' => 'cancelado']);

            // A placa vai junto, e o número dela **não volta ao estoque** (ver `Placas::emitir`):
            // duas máquinas diferentes na história do planeta nunca levaram a mesma placa.
            $veiculo->delete();
        });
    }
}
