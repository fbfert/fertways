<?php

namespace App\Domain\Transport;

use App\Domain\Logistics\VeiculoSpecs;
use App\Domain\Treasury\Tesouro;
use App\Models\Vehicle;
use Illuminate\Support\Facades\DB;

/**
 * A linha de montagem do Ministério dos Transportes (D-60). Roda no tick.
 *
 * Faz duas coisas, nesta ordem:
 *
 *  1. **Fecha o que ficou pronto.** Caminhão com `ready_at` vencido passa de `fabricando` a
 *     `estoque`: está na prateleira, à espera de comprador.
 *  2. **Repõe a prateleira.** Enquanto (prontos + em fabricação) < `ESTOQUE_ALVO`, encomenda mais
 *     um: debita o Tesouro e põe na fila de 1 h.
 *
 * **O caminhão do governo é um veículo sem dono** — `colony_id` nulo. É a "Frota Governamental" do
 * §16.2, e não uma tabela paralela: quando o colono compra, a mesma linha ganha um dono e vira
 * frota dele, com a mesma placa. Nada é copiado, nada é recriado.
 *
 * **A placa nasce aqui**, não na venda: o §16.3 diz "ao ser construído **ou** adquirido", e o
 * caminhão do governo já foi construído. Ele entra na prateleira já registrado.
 *
 * **Se o Tesouro não tiver os recursos, a prateleira simplesmente não se repõe.** Não há erro, não
 * há fila represada, não há dívida: o governo não fabrica o que não pode pagar. É a consequência
 * que o D-60 quis — a redistribuição do §2.1 passa a ter preço.
 */
class FabricarCaminhoes
{
    public function __construct(
        private readonly Tesouro $tesouro,
        private readonly Placas $placas,
    ) {}

    /** @return array{prontos: int, encomendados: int} o que este tick fez */
    public function handle(): array
    {
        $prontos = $this->concluirFabricacao();
        $encomendados = $this->reporPrateleira();

        return ['prontos' => $prontos, 'encomendados' => $encomendados];
    }

    /** Quem passou do `ready_at` vai para a prateleira. */
    private function concluirFabricacao(): int
    {
        return Vehicle::whereNull('colony_id')
            ->where('status', 'fabricando')
            ->where('ready_at', '<=', now())
            ->update(['status' => 'estoque', 'ready_at' => null]);
    }

    private function reporPrateleira(): int
    {
        $encomendados = 0;

        // Um laço, e não um `insert` em lote: cada caminhão é uma transação com o Tesouro, e o
        // primeiro que não couber no caixa interrompe a reposição sem desfazer os anteriores.
        while ($this->naFrotaDoGoverno() < Ministerio::ESTOQUE_ALVO) {
            if (! $this->encomendar()) {
                break;
            }

            $encomendados++;
        }

        return $encomendados;
    }

    /** Prontos na prateleira + os que estão na linha de montagem. Os vendidos já têm dono. */
    private function naFrotaDoGoverno(): int
    {
        return Vehicle::whereNull('colony_id')
            ->whereIn('status', ['estoque', 'fabricando'])
            ->count();
    }

    /** @return bool false se o Tesouro não pagou — e aí a reposição para por aqui */
    private function encomendar(): bool
    {
        return DB::transaction(function () {
            if (! $this->tesouro->gastar(Ministerio::custoFabricacao())) {
                return false;
            }

            $caminhao = Vehicle::create([
                'colony_id' => null,
                'type' => Ministerio::TIPO,
                'level' => 1,
                'status' => 'fabricando',
                'capacity' => VeiculoSpecs::CAPACIDADE[Ministerio::TIPO],
                'ready_at' => now()->addMinutes(Ministerio::MINUTOS_FABRICACAO),
            ]);

            $this->placas->registrar($caminhao);

            return true;
        });
    }
}
