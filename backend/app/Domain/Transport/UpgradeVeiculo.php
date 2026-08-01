<?php

namespace App\Domain\Transport;

use App\Domain\Logistics\VeiculoSpecs;
use App\Exceptions\DomainRuleException;
use App\Models\Colony;
use App\Models\Ledger;
use App\Models\TransportSetting;
use App\Models\Vehicle;
use Illuminate\Support\Facades\DB;

/**
 * Sobe o nível de um veículo (A2.7) — a rota que faltava.
 *
 * `vehicles.level` existe no banco desde sempre e **nunca teve caminho para subir**. O roadmap diz
 * exatamente isso: *"o nível existe sem caminho para subir. É isso que esta fase fecha."*
 *
 * ## Um eixo, com contrapartida
 *
 * Capacidade sobe **e manutenção sobe junto**, e a manutenção sobe mais. É o que transforma o
 * upgrade em escolha econômica em vez de aumento nominal — que é, literalmente, o critério de saída
 * da fase. Quem roda pouco não deve querer subir; um veículo grande parado custa caro.
 *
 * ## ⚠️ Velocidade não é tocada aqui, e nunca deve ser
 *
 * Velocidade é **traço do tipo** — é o que diferencia Furgão de Caminhão. Se o nível acelerasse, a
 * **distância** encolheria a cada upgrade, e distância é pilar declarado do jogo ("logística sem
 * teleporte"). Há teste guardando isso.
 */
class UpgradeVeiculo
{
    public function handle(Colony $colonia, Vehicle $veiculo): Vehicle
    {
        return DB::transaction(function () use ($colonia, $veiculo) {
            if ($veiculo->colony_id !== $colonia->id) {
                throw new DomainRuleException('veiculo_de_outra_colonia', 'Este veículo não é seu.');
            }

            /*
             * Só no pátio. Um veículo em rota tem carga e destino calculados com a capacidade
             * atual — mudá-la no meio da viagem faria a carga não caber no próprio veículo que a
             * transporta. Mesma trava que a manutenção já usa.
             */
            if ($veiculo->status !== 'ocioso') {
                throw new DomainRuleException(
                    'veiculo_em_rota',
                    'Só dá para melhorar veículo parado no pátio.',
                );
            }

            $config = TransportSetting::singleton();
            $alvo = (int) $veiculo->level + 1;

            if ($alvo > (int) $config->upgrade_nivel_maximo) {
                throw new DomainRuleException(
                    'nivel_maximo',
                    "Este veículo já está no nível máximo ({$config->upgrade_nivel_maximo}).",
                );
            }

            $this->debitar($colonia, $veiculo, $alvo, $config);

            $veiculo->update([
                'level' => $alvo,
                /*
                 * A capacidade é REESCRITA a partir da base do tipo, e não incrementada sobre a
                 * atual. Incrementar acumularia erro de arredondamento a cada nível, e — pior —
                 * ficaria errado para sempre se alguém ajustasse o parâmetro depois: a coluna
                 * guardaria o resultado de uma curva que não existe mais.
                 */
                'capacity' => $this->capacidade($veiculo->type, $alvo, $config),
            ]);

            return $veiculo->fresh();
        });
    }

    /**
     * A capacidade de um tipo num nível.
     *
     * Base do tipo mais `bps` por nível acima do primeiro. O nível 1 é sempre a capacidade
     * publicada — subir é ganho sobre ela, nunca redefinição dela.
     */
    public function capacidade(string $tipo, int $nivel, ?TransportSetting $config = null): int
    {
        $base = VeiculoSpecs::CAPACIDADE[$tipo] ?? 0;
        $bps = (int) ($config ?? TransportSetting::singleton())->upgrade_capacidade_bps_por_nivel;

        return (int) floor($base * (10_000 + $bps * max(0, $nivel - 1)) / 10_000);
    }

    /**
     * O multiplicador de manutenção do nível, em pontos-base.
     *
     * Consumido por `Manutencao::custo()`. Fica aqui, e não lá, porque é a **contrapartida do
     * upgrade** — quem for entender por que a manutenção subiu precisa achar a explicação junto da
     * decisão que a causou.
     */
    public function manutencaoBps(int $nivel, ?TransportSetting $config = null): int
    {
        $bps = (int) ($config ?? TransportSetting::singleton())->upgrade_manutencao_bps_por_nivel;

        return 10_000 + $bps * max(0, $nivel - 1);
    }

    /**
     * Custo em recursos: uma fração do custo de compra, multiplicada pelo nível alvo.
     *
     * Não é número novo — é fração de um número que o GDD publica, a mesma forma que o D-60 usou
     * para a manutenção. E multiplica pelo alvo porque, sem isso, o último nível sairia pelo preço
     * do primeiro.
     *
     * @return array<string,int>
     */
    public function custo(string $tipo, int $alvo, ?TransportSetting $config = null): array
    {
        $bps = (int) ($config ?? TransportSetting::singleton())->upgrade_custo_bps_do_custo;
        $custo = [];

        foreach (VeiculoCustos::nivel1($tipo) as $recurso => $qtd) {
            // Para CIMA, como a manutenção: serviço nenhum sai de graça por truncamento.
            $custo[$recurso] = (int) ceil($qtd * $bps * ($alvo - 1) / 10_000);
        }

        return $custo;
    }

    private function debitar(Colony $colonia, Vehicle $veiculo, int $alvo, TransportSetting $config): void
    {
        $custo = $this->custo($veiculo->type, $alvo, $config);
        $estoque = $colonia->resources()->lockForUpdate()->get()->keyBy('resource_type');

        foreach ($custo as $recurso => $qtd) {
            $tem = (int) ($estoque[$recurso]->amount ?? 0);

            if ($tem < $qtd) {
                throw new DomainRuleException(
                    'recurso_insuficiente',
                    "Faltam recursos para melhorar o veículo: {$recurso} exige {$qtd}, você tem {$tem}.",
                );
            }
        }

        foreach ($custo as $recurso => $qtd) {
            $estoque[$recurso]->decrement('amount', $qtd);

            Ledger::create([
                'colony_id' => $colonia->id,
                'type' => 'upgrade_veiculo',
                'amount' => -$qtd,
                'resource_type' => $recurso,
                'ref' => "veiculo:{$veiculo->id}:n{$alvo}",
                'created_at' => now(),
            ]);
        }
    }
}
