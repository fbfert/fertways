<?php

namespace App\Domain\Transport;

use App\Exceptions\DomainRuleException;
use App\Models\Colony;
use App\Models\Ledger;
use App\Models\Vehicle;
use Illuminate\Support\Facades\DB;

/**
 * A manutenção de um veículo (D-60, fatia 2 — GDD §16.4).
 *
 * "**Manutenção realizada:** restaura desempenho, mas reduz permanentemente a vida útil total e o
 * valor máximo de revenda." O GDD descreve isto e **não publica nem o custo, nem quanto restaura,
 * nem quanto corrói**. O usuário decidiu:
 *
 *  - **Onde:** na **Central de Transportes do colono**, não na Capital. O veículo não viaja: é
 *    serviço da colônia, e é a segunda função real que a Central ganha (a primeira é a vaga).
 *  - **Custo:** **10% do custo do veículo**, em recursos. Não é número novo — é uma **fração da
 *    tabela publicada** (§21.2 para o Furgão, §21.3 para o Caminhão), então acompanha o GDD em vez
 *    de virar constante mágica e apodrecer. A porcentagem sai de `transport_settings` (o painel do
 *    operador); a tabela, do documento.
 *  - **Restaura até o teto**, não até 100%.
 *  - **O teto cai 5 pontos a cada manutenção.** É a "vida útil total" que o §16.4 diz que a
 *    manutenção corrói. Depois de ~14 manutenções o teto encosta no piso de desempenho, e o veículo
 *    não tem mais o que recuperar — é aí que o dono decide sucatear.
 *
 * **Sem Central de Transportes não há manutenção** — e uma colônia nova não tem Central (D-59),
 * embora tenha o Furgão do kit. **Arbitragem do assistente**, registrada no D-60: não é armadilha,
 * porque o desgaste é de 0,5%/h de uso *ativo*, o piso é 25% e **nada trava**. O Furgão do novato
 * leva ~150 h de estrada até encostar no piso, e continua andando depois disso. É pressão para
 * erguer a Central, não sentença de morte.
 */
class Manutencao
{
    public function __construct(private readonly Conservacao $conservacao) {}

    /** O custo em recursos, derivado da tabela do GDD. @return array<string,int> */
    public function custo(Vehicle $veiculo): array
    {
        $bps = $this->conservacao->config()->manutencao_bps_do_custo;

        /*
         * A2.7: a manutenção sobe com o NÍVEL, e é essa a contrapartida que torna o upgrade uma
         * escolha em vez de um aumento de graça. Um veículo grande parado custa caro — quem roda
         * pouco não deve querer subir. Ver `UpgradeVeiculo::manutencaoBps()`.
         */
        $doNivel = app(UpgradeVeiculo::class)->manutencaoBps((int) $veiculo->level);
        $custo = [];

        foreach (VeiculoCustos::nivel1($veiculo->type) as $recurso => $qtd) {
            // Arredonda para CIMA: uma fração de 10% de 25 Componentes dá 2,5, e cobrar 2 daria
            // manutenção mais barata do que a regra manda. Serviço nenhum sai de graça por
            // truncamento.
            $custo[$recurso] = (int) ceil($qtd * $bps * $doNivel / (Conservacao::CHEIO * 10_000));
        }

        return $custo;
    }

    public function handle(Colony $colony, Vehicle $veiculo): Vehicle
    {
        if ($veiculo->colony_id !== $colony->id) {
            throw new DomainRuleException('veiculo_de_outra_colonia', 'Este veículo não é seu.');
        }

        if (! $this->conservacao->deprecia($veiculo)) {
            throw new DomainRuleException(
                'veiculo_sem_desgaste',
                'Só o Furgão e o Caminhão de Carga têm depreciação (§16.4).',
            );
        }

        if ($veiculo->status !== 'ocioso') {
            throw new DomainRuleException(
                'veiculo_em_rota',
                'O veículo está em rota. A manutenção é feita no pátio, com ele parado.',
            );
        }

        if ($colony->buildings()->where('type', 'central_de_transportes')->where('level', '>', 0)->doesntExist()) {
            throw new DomainRuleException(
                'sem_central_de_transportes',
                'A manutenção é feita na Central de Transportes. Erga uma na sua colônia.',
            );
        }

        return DB::transaction(function () use ($colony, $veiculo) {
            $colony = Colony::whereKey($colony->id)->lockForUpdate()->firstOrFail();
            $veiculo = Vehicle::whereKey($veiculo->id)->lockForUpdate()->firstOrFail();

            $config = $this->conservacao->config();
            $teto = (int) $veiculo->teto_conservacao_bps;

            if ((int) $veiculo->conservacao_bps >= $teto) {
                throw new DomainRuleException(
                    'nada_a_reparar',
                    'Este veículo já está no teto de conservação dele. A manutenção não o levaria a lugar nenhum.',
                );
            }

            $this->cobrar($colony, $veiculo, $this->custo($veiculo));

            $veiculo->forceFill([
                'conservacao_bps' => $teto,
                // A vida útil encolhe, e não volta. É o preço de continuar rodando.
                'teto_conservacao_bps' => max(0, $teto - $config->perda_de_teto_bps),
                'manutencoes' => (int) $veiculo->manutencoes + 1,
            ])->save();

            app(\App\Domain\Missoes\Progresso::class)->registrar($colony->id, 'manutencao');

            return $veiculo;
        });
    }

    /** @param  array<string,int>  $custo */
    private function cobrar(Colony $colony, Vehicle $veiculo, array $custo): void
    {
        foreach ($custo as $recurso => $qtd) {
            // `where amount >= qtd` no UPDATE: o estoque nunca fica negativo, nem em corrida.
            $baixou = $colony->resources()
                ->where('resource_type', $recurso)
                ->where('amount', '>=', $qtd)
                ->decrement('amount', $qtd);

            if ($baixou === 0) {
                throw new DomainRuleException(
                    'recursos_insuficientes',
                    'A sua colônia não tem os recursos da manutenção.',
                );
            }

            Ledger::create([
                'colony_id' => $colony->id,
                'type' => 'manutencao_veiculo',
                'amount' => -$qtd,
                'resource_type' => $recurso,
                'ref' => "manutencao:{$veiculo->id}:".((int) $veiculo->manutencoes + 1),
                'created_at' => now(),
            ]);
        }
    }
}
