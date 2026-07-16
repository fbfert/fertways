<?php

namespace App\Domain\Transport;

use App\Domain\Logistics\MapaFertways;
use App\Domain\Logistics\VeiculoSpecs;
use App\Domain\Treasury\Tesouro;
use App\Exceptions\DomainRuleException;
use App\Models\Colony;
use App\Models\Ledger;
use App\Models\Vehicle;
use Illuminate\Support\Facades\DB;

/**
 * A venda de um Caminhão novo pelo Ministério dos Transportes (D-60).
 *
 * O colono paga **300 Fert$**, que entram no caixa do Tesouro (D-57). O caminhão que ele leva é um
 * da prateleira — um veículo que o governo já fabricou, já registrou e que estava à espera de dono.
 * A venda **não cria veículo nenhum**: ela dá um dono a um que já existia. A placa não muda, porque
 * a placa é do veículo e não do dono (§16.3).
 *
 * Se a prateleira estiver vazia, **não há venda** — o colono espera o Ministério repor (1 h por
 * caminhão, no tick). Foi assim que o usuário quis: o governo tem estoque de pronta entrega, e quem
 * o esvazia enfrenta a fila.
 *
 * **A entrega é física.** O caminhão sai da Capital e **dirige-se sozinho** até a colônia,
 * gastando o tempo da distância — reusando o trecho de "volta" que a máquina de viagem já sabe
 * fechar. Quem mora longe da Capital espera mais, e isso é conteúdo, não atrito.
 *
 * Duas ordens importam, e não são acidente:
 *
 *  - **A vaga é conferida antes do Fert$ sair.** Ninguém paga 300 Fert$ por um caminhão que a sua
 *    Central de Transportes não comporta.
 *  - **O Fert$ sai antes de o caminhão mudar de dono**, e as duas coisas estão na mesma transação:
 *    não há instante em que o caminhão seja do colono sem estar pago, nem o contrário.
 */
class ComprarCaminhao
{
    public function __construct(
        private readonly Vagas $vagas,
        private readonly Tesouro $tesouro,
        private readonly Conservacao $conservacao,
    ) {}

    public function handle(Colony $colony): Vehicle
    {
        $this->vagas->exigirVagaLivre($colony);

        return DB::transaction(function () use ($colony) {
            // Relê sob lock: duas compras simultâneas do mesmo colono não podem furar o teto de
            // frota nem gastar o mesmo saldo duas vezes.
            $colony = Colony::whereKey($colony->id)->lockForUpdate()->firstOrFail();

            $this->vagas->exigirVagaLivre($colony);

            // O primeiro da prateleira, travado: duas compras simultâneas não levam o mesmo
            // caminhão. Quem chegar depois pega o próximo, ou a fila.
            $caminhao = Vehicle::whereNull('colony_id')
                ->where('status', 'estoque')
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if (! $caminhao) {
                throw new DomainRuleException(
                    'sem_caminhao_em_estoque',
                    'O Ministério não tem caminhão pronto. A linha de montagem repõe a prateleira; tente mais tarde.',
                );
            }

            if ($colony->fert_micro < Ministerio::PRECO_MICRO) {
                throw new DomainRuleException(
                    'fert_insuficiente',
                    'Fert$ insuficiente: o Caminhão de Carga custa '.Ministerio::precoFert().' Fert$.',
                );
            }

            $colony->decrement('fert_micro', Ministerio::PRECO_MICRO);
            $this->tesouro->creditarFert(Ministerio::PRECO_MICRO, "venda_caminhao:{$caminhao->id}");

            Ledger::create([
                'colony_id' => $colony->id,
                'type' => 'compra_veiculo',
                'amount' => -Ministerio::PRECO_MICRO,
                'resource_type' => null,
                'ref' => "ministerio:caminhao:{$caminhao->id}",
                'created_at' => now(),
            ]);

            return $this->entregar($colony, $caminhao);
        });
    }

    /**
     * O caminhão ganha dono e parte da Capital rumo à colônia.
     *
     * Ele nasce em `leg = volta` de propósito: para a máquina de viagem, "voltar" é justamente
     * "ir para casa e ficar ocioso ao chegar" — que é exatamente a entrega. O `ConcluirTrechos`
     * fecha este trecho sem uma linha de código nova: sem carga, ele não tributa nada e não credita
     * nada; só põe o veículo em `ocioso`, já na colônia.
     *
     * **A viagem de entrega não é uso ativo** (D-60): o caminhão chega com 100% de conservação. É o
     * `trip_purpose` que a Fatia 2 vai consultar para não cobrar desgaste deste trecho.
     */
    private function entregar(Colony $colony, Vehicle $caminhao): Vehicle
    {
        $distancia = MapaFertways::ateCapital($colony->x, $colony->y);
        $agora = now();

        $caminhao->forceFill([
            'colony_id' => $colony->id,
            'status' => 'em_rota',
            'leg' => 'volta',
            'trip_purpose' => 'entrega_de_fabrica',
            'destination_type' => 'colonia',
            'destination_id' => $colony->id,
            'distance_slots' => $distancia,
            'departs_at' => $agora,
            'arrives_at' => $agora->copy()->addSeconds($this->conservacao->segundosDoTrecho($caminhao, $distancia)),
            'cargo_json' => null,
            'ready_at' => null,
        ])->save();

        return $caminhao;
    }
}
