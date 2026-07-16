<?php

namespace App\Domain\Frete;

use App\Domain\Logistics\MapaFertways;
use App\Domain\Trade\AcessoAoMercado;
use App\Domain\Treasury\Tesouro;
use App\Exceptions\DomainRuleException;
use App\Models\Colony;
use App\Models\Ledger;
use App\Models\MarketAccount;
use App\Models\TransportSetting;
use App\Models\Vehicle;
use Illuminate\Support\Facades\DB;

/**
 * O serviço logístico público (§07; D-76): o governo leva o seu lote da doca até a sua colônia.
 *
 * "O comprador agenda retirada com veículo próprio ou paga serviço logístico público." O preço é
 * do OPERADOR (1 F$ + 0,02 F$/slot por padrão — arbitragem do usuário, deliberadamente amável: o
 * governo empurra o comércio, e a Garagem finita é o freio) e vai ao TESOURO, como as taxas — o
 * §07 lista serviços públicos entre os sumidouros de Fert$.
 *
 * ⚠️ **A entrega paga tributo na chegada, como QUALQUER entrega física.** O §07 diz que a retirada
 * não gera novo imposto, mas essa contradição já foi arbitrada no D-32 (o tributo do §25.2 incide
 * em toda entrega, inclusive na retirada com veículo próprio) — e um frete isento seria uma ROTA
 * DE FUGA do tributo: bastaria "fretar" em vez de buscar. Mesma regra para os dois caminhos.
 *
 * O caminhão é REAL (a Garagem, D-76): se os dez estiverem na estrada, o serviço recusa — e é essa
 * escassez que impede o preço amável de aposentar a frota própria.
 */
class FretePublico
{
    public function __construct(private readonly Tesouro $tesouro) {}

    /** O orçamento de um frete até esta colônia — a UI mostra antes de o colono pagar. */
    public function orcamento(Colony $colony): array
    {
        $config = TransportSetting::singleton();
        $distancia = MapaFertways::distancia(
            MapaFertways::CAPITAL_X, MapaFertways::CAPITAL_Y, $colony->x, $colony->y,
        );

        return [
            'distancia_slots' => $distancia,
            'preco_micro' => (int) $config->frete_base_micro + $distancia * (int) $config->frete_por_slot_micro,
            'capacidade' => Vehicle::CAPACIDADE['caminhao_de_carga'],
            'caminhoes_livres' => Garagem::livres()->count(),
        ];
    }

    /** @param array<string,int> $carga recurso => quantidade, do depósito da Capital */
    public function despachar(Colony $colony, array $carga): Vehicle
    {
        // Tirar carga do depósito é usar o Mercado Central (§25.8) — as mesmas portas da retirada.
        AcessoAoMercado::exigir($colony);

        $carga = array_filter(array_map('intval', $carga), fn ($q) => $q > 0);

        if ($carga === []) {
            throw new DomainRuleException('carga_vazia', 'Diga o que o caminhão do governo deve levar.');
        }

        if (array_sum($carga) > Vehicle::CAPACIDADE['caminhao_de_carga']) {
            throw new DomainRuleException(
                'carga_excede_capacidade',
                'O caminhão do governo leva até '.number_format(Vehicle::CAPACIDADE['caminhao_de_carga'], 0, ',', '.')
                .' unidades por viagem. Divida em dois fretes.',
            );
        }

        $orcamento = $this->orcamento($colony);
        $preco = (int) $orcamento['preco_micro'];

        return DB::transaction(function () use ($colony, $carga, $orcamento, $preco) {
            /*
             * O caminhão primeiro: é o recurso escasso. `lockForUpdate` serializa dois fretes
             * disputando o último caminhão livre — o segundo não acha nenhum e é recusado.
             */
            $caminhao = Garagem::livres()->lockForUpdate()->orderBy('id')->first();

            if (! $caminhao) {
                throw new DomainRuleException(
                    'garagem_vazia',
                    'Os caminhões do governo estão todos na estrada. Tente mais tarde — ou mande um veículo seu.',
                );
            }

            // O Fert$ do frete, à vista. O `where` é a guarda contra saldo insuficiente.
            $pagou = Colony::whereKey($colony->id)
                ->where('fert_micro', '>=', $preco)
                ->decrement('fert_micro', $preco);

            if ($pagou === 0) {
                throw new DomainRuleException(
                    'fert_insuficiente',
                    'O frete custa '.number_format($preco / 1_000_000, 2, ',', '.').' Fert$ e o seu caixa não cobre.',
                );
            }

            Ledger::create([
                'colony_id' => $colony->id,
                'type' => 'frete_publico',
                'amount' => -$preco,
                'resource_type' => null,
                'ref' => "frete:caminhao:{$caminhao->id}",
                'created_at' => now(),
            ]);

            // Receita de serviço público: o Tesouro, como as taxas (§07 e D-57).
            $this->tesouro->creditarFert($preco, "frete_publico:caminhao:{$caminhao->id}");

            // A carga sai do depósito AGORA: reservada no embarque, como na retirada própria.
            foreach ($carga as $recurso => $qtd) {
                $debitadas = MarketAccount::where('colony_id', $colony->id)
                    ->where('resource_type', $recurso)
                    ->where('amount', '>=', $qtd)
                    ->decrement('amount', $qtd);

                if ($debitadas === 0) {
                    throw new DomainRuleException(
                        'saldo_mercado_insuficiente',
                        "Sua conta no Mercado não tem {$qtd} de {$recurso}.",
                    );
                }

                Ledger::create([
                    'colony_id' => $colony->id,
                    'type' => 'retirada_mercado',
                    'amount' => -$qtd,
                    'resource_type' => $recurso,
                    'ref' => "frete:caminhao:{$caminhao->id}",
                    'created_at' => now(),
                ]);
            }

            $agora = now();
            $distancia = (int) $orcamento['distancia_slots'];

            app(\App\Domain\Missoes\Progresso::class)->registrar($colony->id, 'frete_publico');

            $caminhao->forceFill([
                'status' => 'em_rota',
                'leg' => 'ida',
                'trip_purpose' => 'frete',
                'destination_type' => 'colonia',
                'destination_id' => $colony->id,
                'distance_slots' => $distancia,
                'return_distance_slots' => $distancia,
                'departs_at' => $agora,
                'arrives_at' => $agora->copy()->addSeconds(
                    \App\Domain\Logistics\VeiculoSpecs::segundosDoTrecho('caminhao_de_carga', $distancia),
                ),
                'cargo_json' => $carga,
            ])->save();

            return $caminhao->fresh();
        });
    }
}
