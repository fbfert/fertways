<?php

namespace App\Domain\Transport;

use App\Domain\Logistics\VeiculoSpecs;
use App\Domain\Treasury\Tesouro;
use App\Models\Vehicle;
use Illuminate\Support\Facades\DB;

/**
 * A linha de montagem do Ministério dos Transportes (D-60, generalizada por tipo no D-109). Roda
 * no tick, para cada tipo que `Ministerio::TIPOS` lista — hoje o Caminhão de Carga e o Furgão de
 * Comércio.
 *
 * Faz duas coisas, nesta ordem, POR TIPO:
 *
 *  1. **Fecha o que ficou pronto.** Veículo com `ready_at` vencido passa de `fabricando` a
 *     `estoque`: está na prateleira, à espera de comprador.
 *  2. **Repõe a prateleira.** Enquanto (prontos + em fabricação) daquele tipo < estoque-alvo dele,
 *     encomenda mais um: debita o Tesouro e põe na fila.
 *
 * **O veículo do governo é um veículo sem dono** — `colony_id` nulo. É a "Frota Governamental" do
 * §16.2, e não uma tabela paralela: quando o colono compra, a mesma linha ganha um dono e vira
 * frota dele, com a mesma placa. Nada é copiado, nada é recriado.
 *
 * **A placa nasce aqui**, não na venda: o §16.3 diz "ao ser construído **ou** adquirido", e o
 * veículo do governo já foi construído. Ele entra na prateleira já registrado.
 *
 * **Se o Tesouro não tiver os recursos, a prateleira daquele tipo simplesmente não se repõe.** Não
 * há erro, não há fila represada, não há dívida: o governo não fabrica o que não pode pagar. É a
 * consequência que o D-60 quis — a redistribuição do §2.1 passa a ter preço.
 */
class FabricarVeiculos
{
    public function __construct(
        private readonly Tesouro $tesouro,
        private readonly Placas $placas,
    ) {}

    /** @return array{prontos: int, encomendados: int} o que este tick fez, somando todos os tipos */
    public function handle(): array
    {
        $prontos = $this->concluirFabricacao();
        $encomendados = 0;

        foreach (Ministerio::TIPOS as $tipo) {
            $encomendados += $this->reporPrateleira($tipo);
        }

        return ['prontos' => $prontos, 'encomendados' => $encomendados];
    }

    /** Quem passou do `ready_at` vai para a prateleira — de qualquer tipo, de uma vez. */
    private function concluirFabricacao(): int
    {
        return Vehicle::whereNull('colony_id')
            ->where('status', 'fabricando')
            ->where('ready_at', '<=', now())
            ->update(['status' => 'estoque', 'ready_at' => null]);
    }

    private function reporPrateleira(string $tipo): int
    {
        $encomendados = 0;
        $alvo = Ministerio::config($tipo)['estoque_alvo'];

        // Um laço, e não um `insert` em lote: cada veículo é uma transação com o Tesouro, e o
        // primeiro que não couber no caixa interrompe a reposição sem desfazer os anteriores.
        while ($this->naFrotaDoGoverno($tipo) < $alvo) {
            if (! $this->encomendar($tipo)) {
                break;
            }

            $encomendados++;
        }

        return $encomendados;
    }

    /** Prontos na prateleira + os que estão na linha de montagem, DAQUELE tipo. Os vendidos já têm dono. */
    private function naFrotaDoGoverno(string $tipo): int
    {
        return Vehicle::whereNull('colony_id')
            ->where('type', $tipo)
            ->whereIn('status', ['estoque', 'fabricando'])
            ->count();
    }

    /** @return bool false se o Tesouro não pagou — e aí a reposição daquele tipo para por aqui */
    private function encomendar(string $tipo): bool
    {
        $config = Ministerio::config($tipo);

        return DB::transaction(function () use ($tipo, $config) {
            if (! $this->tesouro->gastar($config['custo'], "fabricacao_veiculo:{$tipo}")) {
                return false;
            }

            $veiculo = Vehicle::create([
                'colony_id' => null,
                'type' => $tipo,
                'level' => 1,
                'status' => 'fabricando',
                'capacity' => VeiculoSpecs::CAPACIDADE[$tipo],
                'ready_at' => now()->addMinutes($config['minutos_fabricacao']),
            ]);

            $this->placas->registrar($veiculo);

            return true;
        });
    }
}
