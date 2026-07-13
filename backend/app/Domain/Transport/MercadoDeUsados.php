<?php

namespace App\Domain\Transport;

use App\Domain\Logistics\MapaFertways;
use App\Domain\Logistics\VeiculoSpecs;
use App\Exceptions\DomainRuleException;
use App\Models\Colony;
use App\Models\Ledger;
use App\Models\Vehicle;
use App\Models\VehicleListing;
use Illuminate\Support\Facades\DB;

/**
 * O mercado de usados (D-60, fatia 3 — GDD §16.4).
 *
 * "O estado de conservação é visível no registro e **afeta diretamente o preço de venda no mercado
 * de usados**. Veículos podem ser vendidos a outros colonos a qualquer momento."
 *
 * **Com escrow do Ministério** (aditivo 15 do D-60), e a ordem dos atos é o que dá segurança aos
 * dois lados:
 *
 *   1. o vendedor **anuncia** — o veículo continua dele e continua ocioso, mas fica marcado;
 *   2. o comprador **paga**, e os Fert$ ficam **retidos no anúncio**, não no vendedor;
 *   3. o veículo **muda de dono no ato** e parte, dirigindo-se sozinho até a colônia do comprador;
 *   4. **na chegada**, o `ConcluirTrechos` libera o escrow ao vendedor e fecha o anúncio.
 *
 * Se a viagem nunca terminasse, o Fert$ ficaria preso — mas ela sempre termina: o tick a fecha, e o
 * veículo não tem como se perder (não há regra de perda de veículo no jogo).
 *
 * **O teto de revenda só existe para o Caminhão.** Ele tem preço de fábrica (300 Fert$), e o §16.4
 * manda a manutenção corroer o "teto de valor de revenda" — sem uma âncora, não há o que corroer. O
 * **Furgão não tem teto**: o Ministério não o vende, logo ele não tem preço de fábrica, e o usuário
 * decidiu deixá-lo sem âncora (aditivo 14). **É o buraco por onde a lavagem de Fert$ entre duas
 * contas do mesmo jogador vai aparecer primeiro, se aparecer.**
 */
class MercadoDeUsados
{
    public function __construct(
        private readonly Conservacao $conservacao,
        private readonly Vagas $vagas,
    ) {}

    /** Anuncia um veículo ocioso do colono. */
    public function anunciar(Colony $colony, Vehicle $veiculo, int $precoMicro): VehicleListing
    {
        if ($veiculo->colony_id !== $colony->id) {
            throw new DomainRuleException('veiculo_de_outra_colonia', 'Este veículo não é seu.');
        }

        if ($veiculo->status !== 'ocioso') {
            throw new DomainRuleException(
                'veiculo_em_rota',
                'Só se anuncia veículo no pátio. Espere-o voltar.',
            );
        }

        if ($precoMicro <= 0) {
            throw new DomainRuleException('preco_invalido', 'O preço tem de ser positivo.');
        }

        /*
         * ⚠️ O Drone é "vendável" (§16.1), mas SEM ÂNCORA ele seria a reabertura do buraco que o
         * D-73 acabou de fechar: teto de revenda nulo = preço livre = lavagem de Fert$ entre duas
         * contas, agora por Drone em vez de Furgão. Fica fora do mercado até ganhar uma âncora —
         * decisão registrada no D-74, não esquecimento.
         */
        if ($veiculo->type === \App\Domain\Drone\DroneSpecs::TIPO) {
            throw new DomainRuleException(
                'drone_sem_ancora_de_revenda',
                'O Drone ainda não tem teto de revenda — e vendê-lo sem teto reabriria a brecha que o D-73 fechou.',
            );
        }

        $teto = $this->conservacao->tetoDeRevendaMicro($veiculo);

        if ($teto !== null && $precoMicro > $teto) {
            $emFert = $teto / Colony::MICRO_POR_FERT;

            throw new DomainRuleException(
                'acima_do_teto_de_revenda',
                "O teto de revenda deste veículo é {$emFert} Fert$ — ele cai a cada manutenção (§16.4).",
            );
        }

        return DB::transaction(function () use ($colony, $veiculo, $precoMicro) {
            $veiculo = Vehicle::whereKey($veiculo->id)->lockForUpdate()->firstOrFail();

            if (VehicleListing::where('vehicle_id', $veiculo->id)->where('status', 'aberto')->exists()) {
                throw new DomainRuleException('ja_anunciado', 'Este veículo já está anunciado.');
            }

            return VehicleListing::create([
                'vehicle_id' => $veiculo->id,
                'seller_colony_id' => $colony->id,
                'price_micro' => $precoMicro,
                'status' => 'aberto',
            ]);
        });
    }

    public function cancelar(Colony $colony, VehicleListing $anuncio): void
    {
        if ($anuncio->seller_colony_id !== $colony->id) {
            throw new DomainRuleException('anuncio_de_outro_colono', 'Este anúncio não é seu.');
        }

        if ($anuncio->status !== 'aberto') {
            throw new DomainRuleException(
                'anuncio_nao_aberto',
                'Este anúncio já foi comprado ou cancelado — não há mais o que desfazer.',
            );
        }

        $anuncio->update(['status' => 'cancelado']);
    }

    /**
     * O comprador paga; o veículo muda de dono e parte para a colônia dele.
     *
     * O Fert$ **não vai ao vendedor agora**: fica retido no anúncio. Quem o libera é a chegada.
     */
    public function comprar(Colony $comprador, VehicleListing $anuncio): Vehicle
    {
        if ($anuncio->seller_colony_id === $comprador->id) {
            throw new DomainRuleException('anuncio_seu', 'Você não pode comprar o seu próprio veículo.');
        }

        // Antes de o Fert$ sair, como na compra nova: um teto que não impede nada é decoração.
        $this->vagas->exigirVagaLivre($comprador);

        return DB::transaction(function () use ($comprador, $anuncio) {
            $comprador = Colony::whereKey($comprador->id)->lockForUpdate()->firstOrFail();
            $anuncio = VehicleListing::whereKey($anuncio->id)->lockForUpdate()->firstOrFail();

            if ($anuncio->status !== 'aberto') {
                throw new DomainRuleException('anuncio_nao_aberto', 'Este anúncio já não está aberto.');
            }

            $this->vagas->exigirVagaLivre($comprador);

            if ((int) $comprador->fert_micro < $anuncio->price_micro) {
                throw new DomainRuleException('fert_insuficiente', 'Fert$ insuficiente para este veículo.');
            }

            $veiculo = Vehicle::whereKey($anuncio->vehicle_id)->lockForUpdate()->firstOrFail();

            if ($veiculo->status !== 'ocioso' || $veiculo->colony_id !== $anuncio->seller_colony_id) {
                throw new DomainRuleException(
                    'veiculo_indisponivel',
                    'O veículo deste anúncio já não está disponível.',
                );
            }

            // Sai do bolso do comprador e fica RETIDO no anúncio — não vai ao vendedor.
            $comprador->decrement('fert_micro', $anuncio->price_micro);

            $anuncio->update([
                'buyer_colony_id' => $comprador->id,
                'escrow_micro' => $anuncio->price_micro,
                'status' => 'em_transito',
            ]);

            Ledger::create([
                'colony_id' => $comprador->id,
                'type' => 'compra_veiculo',
                'amount' => -$anuncio->price_micro,
                'resource_type' => null,
                'ref' => "usado:{$anuncio->id}",
                'created_at' => now(),
            ]);

            return $this->entregar($anuncio, $veiculo, $comprador);
        });
    }

    /**
     * O veículo muda de dono e parte da colônia do vendedor rumo à do comprador.
     *
     * `leg = volta` porque, para a máquina de viagem, "voltar" é ir para casa e ficar ocioso ao
     * chegar — que é exatamente a entrega. Mesmo truque da entrega de fábrica (D-60), e pelo mesmo
     * motivo: nenhuma linha nova no `ConcluirTrechos` para mover o veículo. A única linha nova lá é
     * a que **libera o escrow**.
     *
     * `trip_purpose = 'venda_usado'` faz a `Conservacao` **não cobrar desgaste desta viagem**: quem
     * comprou não pode receber o veículo mais gasto do que o anúncio dizia.
     */
    private function entregar(VehicleListing $anuncio, Vehicle $veiculo, Colony $comprador): Vehicle
    {
        $vendedor = Colony::findOrFail($anuncio->seller_colony_id);
        $distancia = MapaFertways::distancia($vendedor->x, $vendedor->y, $comprador->x, $comprador->y);
        $agora = now();

        $veiculo->forceFill([
            'colony_id' => $comprador->id,
            'status' => 'em_rota',
            'leg' => 'volta',
            'trip_purpose' => 'venda_usado',
            'destination_type' => 'colonia',
            'destination_id' => $comprador->id,
            'distance_slots' => $distancia,
            'departs_at' => $agora,
            'arrives_at' => $agora->copy()->addSeconds($this->conservacao->segundosDoTrecho($veiculo, $distancia)),
            'cargo_json' => null,
        ])->save();

        return $veiculo;
    }

    /**
     * A chegada: o Ministério libera o escrow ao vendedor e fecha o anúncio. Chamado pelo tick, do
     * `ConcluirTrechos`, quando o trecho de um `venda_usado` termina.
     *
     * A placa **não muda** — ela é do veículo, não do dono (§16.3). O que mudou de dono foi o
     * veículo, e isso já aconteceu na compra.
     */
    public function concluirEntrega(Vehicle $veiculo): void
    {
        $anuncio = VehicleListing::where('vehicle_id', $veiculo->id)
            ->where('status', 'em_transito')
            ->lockForUpdate()
            ->first();

        if (! $anuncio) {
            return;
        }

        $vendedor = Colony::find($anuncio->seller_colony_id);

        if ($vendedor) {
            $vendedor->increment('fert_micro', $anuncio->escrow_micro);

            Ledger::create([
                'colony_id' => $vendedor->id,
                'type' => 'venda_veiculo',
                'amount' => $anuncio->escrow_micro,
                'resource_type' => null,
                'ref' => "usado:{$anuncio->id}",
                'created_at' => now(),
            ]);
        }

        $anuncio->update(['escrow_micro' => 0, 'status' => 'concluido']);
    }
}
