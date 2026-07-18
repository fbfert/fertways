<?php

namespace App\Domain\Transport;

use App\Domain\Logistics\MapaFertways;
use App\Domain\Treasury\Tesouro;
use App\Exceptions\DomainRuleException;
use App\Models\Colony;
use App\Models\Ledger;
use App\Models\Vehicle;
use Illuminate\Support\Facades\DB;

/**
 * A venda de um veículo novo pelo Ministério dos Transportes (D-60, generalizado por tipo no
 * D-109 — Caminhão de Carga e Furgão de Comércio, cada um com o preço da sua própria fábrica).
 *
 * O colono paga o preço de `Ministerio::config($tipo)`, que entra no caixa do Tesouro (D-57). O
 * veículo que ele leva é um da prateleira — um que o governo já fabricou, já registrou e que
 * estava à espera de dono. A venda **não cria veículo nenhum**: ela dá um dono a um que já
 * existia. A placa não muda, porque a placa é do veículo e não do dono (§16.3).
 *
 * Se a prateleira daquele tipo estiver vazia, **não há venda** — o colono espera o Ministério
 * repor. Foi assim que o usuário quis: o governo tem estoque de pronta entrega, e quem o esvazia
 * enfrenta a fila.
 *
 * **A entrega é física.** O veículo sai da Capital e **dirige-se sozinho** até a colônia,
 * gastando o tempo da distância — reusando o trecho de "volta" que a máquina de viagem já sabe
 * fechar. Quem mora longe da Capital espera mais, e isso é conteúdo, não atrito.
 *
 * Duas ordens importam, e não são acidente:
 *
 *  - **A vaga é conferida antes do Fert$ sair.** Ninguém paga pelo veículo que a sua Central de
 *    Transportes não comporta.
 *  - **O Fert$ sai antes de o veículo mudar de dono**, e as duas coisas estão na mesma transação:
 *    não há instante em que o veículo seja do colono sem estar pago, nem o contrário.
 */
class ComprarVeiculo
{
    public function __construct(
        private readonly Vagas $vagas,
        private readonly Tesouro $tesouro,
        private readonly Conservacao $conservacao,
    ) {}

    public function handle(Colony $colony, string $tipo): Vehicle
    {
        $this->vagas->exigirVagaLivre($colony);
        $config = Ministerio::config($tipo);

        return DB::transaction(function () use ($colony, $tipo, $config) {
            // Relê sob lock: duas compras simultâneas do mesmo colono não podem furar o teto de
            // frota nem gastar o mesmo saldo duas vezes.
            $colony = Colony::whereKey($colony->id)->lockForUpdate()->firstOrFail();

            $this->vagas->exigirVagaLivre($colony);

            // O primeiro da prateleira DAQUELE TIPO, travado: duas compras simultâneas não levam
            // o mesmo veículo. Quem chegar depois pega o próximo, ou a fila.
            $veiculo = Vehicle::whereNull('colony_id')
                ->where('type', $tipo)
                ->where('status', 'estoque')
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if (! $veiculo) {
                throw new DomainRuleException(
                    'sem_veiculo_em_estoque',
                    'O Ministério não tem esse veículo pronto. A linha de montagem repõe a prateleira; tente mais tarde.',
                );
            }

            if ($colony->fert_micro < $config['preco_micro']) {
                throw new DomainRuleException(
                    'fert_insuficiente',
                    'Fert$ insuficiente: este veículo custa '.($config['preco_micro'] / Colony::MICRO_POR_FERT).' Fert$.',
                );
            }

            $colony->decrement('fert_micro', $config['preco_micro']);
            $this->tesouro->creditarFert($config['preco_micro'], "venda_veiculo:{$veiculo->id}");

            Ledger::create([
                'colony_id' => $colony->id,
                'type' => 'compra_veiculo',
                'amount' => -$config['preco_micro'],
                'resource_type' => null,
                'ref' => "ministerio:{$tipo}:{$veiculo->id}",
                'created_at' => now(),
            ]);

            app(\App\Domain\Missoes\Progresso::class)->registrar($colony->id, 'compra_veiculo_novo');

            return $this->entregar($colony, $veiculo);
        });
    }

    /**
     * O veículo ganha dono e parte da Capital rumo à colônia.
     *
     * Ele nasce em `leg = volta` de propósito: para a máquina de viagem, "voltar" é justamente
     * "ir para casa e ficar ocioso ao chegar" — que é exatamente a entrega. O `ConcluirTrechos`
     * fecha este trecho sem uma linha de código nova: sem carga, ele não tributa nada e não credita
     * nada; só põe o veículo em `ocioso`, já na colônia.
     *
     * **A viagem de entrega não é uso ativo** (D-60): o veículo chega com 100% de conservação. É o
     * `trip_purpose` que a Fatia 2 vai consultar para não cobrar desgaste deste trecho.
     */
    private function entregar(Colony $colony, Vehicle $veiculo): Vehicle
    {
        $distancia = MapaFertways::ateCapital($colony->x, $colony->y);
        $agora = now();

        $veiculo->forceFill([
            'colony_id' => $colony->id,
            'status' => 'em_rota',
            'leg' => 'volta',
            'trip_purpose' => 'entrega_de_fabrica',
            'destination_type' => 'colonia',
            'destination_id' => $colony->id,
            'distance_slots' => $distancia,
            'departs_at' => $agora,
            'arrives_at' => $agora->copy()->addSeconds($this->conservacao->segundosDoTrecho($veiculo, $distancia)),
            'cargo_json' => null,
            'ready_at' => null,
        ])->save();

        return $veiculo;
    }
}
