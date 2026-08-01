<?php

namespace App\Console\Commands;

use App\Domain\Logistics\VeiculoSpecs;
use App\Domain\Transport\Manutencao;
use App\Domain\Transport\UpgradeVeiculo;
use App\Models\Colony;
use App\Models\TransportSetting;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * TRILHA A2.S — a rodada da A2.7, item 6: **o upgrade de veículo é escolha ou é botão?**
 *
 * ## A pergunta que a fase manda responder
 *
 * O critério de saída da A2.7 é *"upgrade de veículo apresenta escolha econômica mensurável e não
 * apenas aumento nominal de nível"*. Isso não se verifica lendo o código: verifica-se medindo os
 * dois lados e vendo se algum jogador racional escolheria **não** subir.
 *
 * ## A conta que ninguém tinha feito
 *
 * A manutenção é cobrada **por reparo**, e reparo vem do desgaste, que vem das **horas de estrada**
 * (§16.4: 0,5%/h de uso ativo). Então subir de nível mexe nos dois lados ao mesmo tempo:
 *
 * - capacidade **+15%/nível** → menos viagens para a mesma tonelagem → **menos desgaste**;
 * - manutenção **+20%/nível** → **cada reparo mais caro**.
 *
 * O que decide não é nenhum dos dois sozinho, é o produto. Este comando o calcula com o domínio
 * real, e a unidade que ele imprime é a única honesta aqui: **custo de manutenção por unidade
 * entregue**.
 *
 * ## As quatro regras da trilha
 *
 * 1. **Reusa o domínio.** `UpgradeVeiculo::capacidade()`, `::custo()`, `Manutencao::custo()` e
 *    `VeiculoSpecs::segundosDoTrecho()` — as mesmas que o jogo chama.
 * 2. **Parâmetros da mesma fonte.** Lê `transport_settings`, nunca uma cópia digitada aqui.
 * 3. **Não toca produção.** Roda dentro de uma transação que termina em `rollBack()`.
 * 4. **Saída legível**, pronta para colar no `BALANCEAMENTO.md`.
 *
 * ## ⚠️ Por que a tonelagem de referência é enorme
 *
 * O número de viagens é `ceil(tonelagem / capacidade)`, e com poucas viagens **o arredondamento
 * domina a medida**: 100 mil unidades dão 4 viagens no nível 1 e 3 no nível 5, uma razão de 1,33
 * que não é economia nenhuma — é o `ceil()`. Com volume grande, o degrau some e sobra o efeito real.
 *
 *     php84 artisan fertways:simular-upgrade-veiculo --tipo=caminhao_de_carga --slots=10
 */
class SimularUpgradeVeiculo extends Command
{
    protected $signature = 'fertways:simular-upgrade-veiculo
        {--tipo=caminhao_de_carga : o tipo de veículo}
        {--slots=10 : distância de um trecho, em slots}
        {--entregar=10000000 : tonelagem de referência, em unidades — grande de propósito, ver o docblock}
        {--capacidade-bps= : sobrepõe o ganho de capacidade por nível}
        {--manutencao-bps= : sobrepõe o aumento de manutenção por nível}';

    protected $description = 'Trilha A2.S: mede se o upgrade de veículo é escolha econômica ou aumento nominal';

    public function handle(UpgradeVeiculo $upgrade, Manutencao $manutencao): int
    {
        $tipo = (string) $this->option('tipo');

        if (! isset(VeiculoSpecs::CAPACIDADE[$tipo])) {
            $this->error("Tipo desconhecido: {$tipo}. Conhecidos: ".implode(', ', array_keys(VeiculoSpecs::CAPACIDADE)));

            return self::FAILURE;
        }

        $slots = max(1, (int) $this->option('slots'));
        $entregar = max(1, (int) $this->option('entregar'));

        DB::beginTransaction();

        try {
            $this->aplicarSobreposicoes();
            $config = TransportSetting::singleton()->fresh();
            $colonia = $this->coloniaDescartavel();

            $this->linhaDeParametros($config, $tipo, $slots, $entregar);
            $tabela = $this->medir($upgrade, $manutencao, $colonia, $config, $tipo, $slots, $entregar);
            $this->veredito($tabela);
        } finally {
            // Regra 3 da trilha: nada do que este comando escreveu sobrevive a ele.
            DB::rollBack();
        }

        return self::SUCCESS;
    }

    /**
     * A medida, nível a nível.
     *
     * @return list<array<string,mixed>>
     */
    private function medir(
        UpgradeVeiculo $upgrade,
        Manutencao $manutencao,
        Colony $colonia,
        TransportSetting $config,
        string $tipo,
        int $slots,
        int $entregar,
    ): array {
        $maximo = (int) $config->upgrade_nivel_maximo;
        $desgastePorHora = (int) $config->desgaste_bps_por_hora;

        /*
         * Quanto desgaste um reparo recupera. O teto cai `perda_de_teto_bps` a cada manutenção, mas
         * aqui interessa a média de longo prazo, e ela é a janela cheia: da conservação cheia até o
         * piso de desempenho. Usar a janela do primeiro reparo superestimaria a vida de um veículo
         * que já foi reparado dez vezes.
         */
        $janela = 10_000 - (int) $config->piso_desempenho_bps;
        $horasPorReparo = $janela / $desgastePorHora;

        // Ida e volta: o veículo volta vazio, e o desgaste é por hora de uso ATIVO nos dois sentidos.
        $horasPorViagem = VeiculoSpecs::segundosDoTrecho($tipo, $slots) * 2 / 3600;

        $tabela = [];

        for ($nivel = 1; $nivel <= $maximo; $nivel++) {
            $veiculo = $this->veiculoDescartavel($colonia, $tipo, $nivel, $upgrade, $config);

            $capacidade = $upgrade->capacidade($tipo, $nivel, $config);
            $viagens = (int) ceil($entregar / $capacidade);
            $horas = $viagens * $horasPorViagem;
            $reparos = $horas / $horasPorReparo;
            $porReparo = array_sum($manutencao->custo($veiculo));

            $tabela[] = [
                'nivel' => $nivel,
                'capacidade' => $capacidade,
                'viagens' => $viagens,
                'horas' => $horas,
                'reparos' => $reparos,
                'por_reparo' => $porReparo,
                // A unidade honesta: o que custa manter, por tudo o que foi entregue.
                'custo_total' => $reparos * $porReparo,
                'entrada' => $nivel === 1 ? 0 : array_sum($upgrade->custo($tipo, $nivel, $config)),
            ];
        }

        $this->newLine();
        $this->line(sprintf(
            '%-6s %-11s %-9s %-11s %-13s %-13s %s',
            'nível', 'capacidade', 'viagens', 'h de estrada', 'por reparo', 'manutenção', 'entrada',
        ));

        foreach ($tabela as $l) {
            $this->line(sprintf(
                '%-6d %-11d %-9d %-11.0f %-13d %-13.1f %d',
                $l['nivel'], $l['capacidade'], $l['viagens'], $l['horas'],
                $l['por_reparo'], $l['custo_total'], $l['entrada'],
            ));
        }

        return $tabela;
    }

    /**
     * A leitura, que é o produto deste comando — e não a tabela.
     *
     * ⚠️ O veredito compara o nível 1 com o máximo pelo **custo de manutenção da mesma tonelagem**.
     * Se subir sair mais barato por unidade, o upgrade é aumento nominal disfarçado: ninguém teria
     * razão para não subir, e a fase falha o próprio critério de saída.
     *
     * @param  list<array<string,mixed>>  $tabela
     */
    private function veredito(array $tabela): void
    {
        $base = $tabela[0];
        $topo = $tabela[count($tabela) - 1];

        $razaoCusto = $topo['custo_total'] / max(1e-9, $base['custo_total']);
        $razaoViagens = $base['viagens'] / max(1, $topo['viagens']);

        $this->newLine();
        $this->line(sprintf(
            'Do nível %d ao %d: a mesma tonelagem custa %+.1f%% de manutenção e cabe em %.2f× menos viagens.',
            $base['nivel'], $topo['nivel'], ($razaoCusto - 1) * 100, $razaoViagens,
        ));

        if ($razaoCusto <= 1.0) {
            $this->error('⚠️ AUMENTO NOMINAL: subir sai MAIS BARATO por unidade entregue.');
            $this->line('   Ninguém teria razão para não subir, e o critério de saída da A2.7 falha.');
            $this->line('   O aumento de manutenção precisa superar o ganho de capacidade.');

            return;
        }

        $this->info('✔ ESCOLHA ECONÔMICA: subir custa mais por unidade e entrega mais por veículo.');
        $this->line('   Vale a pena para quem tem VAGA DE FROTA escassa e carga sobrando — o teto da');
        $this->line('   frota é o nível da Central de Transportes. Não vale para quem já tem veículo');
        $this->line('   ocioso: aí se paga a manutenção maior sem usar a capacidade maior.');
        $this->newLine();
        $this->line(sprintf(
            '   Entrada de %d unidades de recurso para o topo, que não se recuperam: é aposta em volume.',
            array_sum(array_column($tabela, 'entrada')),
        ));
    }

    private function linhaDeParametros(TransportSetting $c, string $tipo, int $slots, int $entregar): void
    {
        $this->info('Trilha A2.S — upgrade de veículo (A2.7 item 6)');
        $this->line(sprintf(
            'tipo=%s · trecho=%d slots · tonelagem=%d · capacidade=+%.0f%%/nível · manutenção=+%.0f%%/nível',
            $tipo, $slots, $entregar,
            $c->upgrade_capacidade_bps_por_nivel / 100,
            $c->upgrade_manutencao_bps_por_nivel / 100,
        ));
    }

    private function aplicarSobreposicoes(): void
    {
        $mapa = [
            'capacidade-bps' => 'upgrade_capacidade_bps_por_nivel',
            'manutencao-bps' => 'upgrade_manutencao_bps_por_nivel',
        ];

        $mudancas = [];

        foreach ($mapa as $opcao => $coluna) {
            if (($v = $this->option($opcao)) !== null && $v !== '') {
                $mudancas[$coluna] = (int) $v;
            }
        }

        if ($mudancas !== []) {
            TransportSetting::query()->update($mudancas);
        }
    }

    private function coloniaDescartavel(): Colony
    {
        $u = User::create([
            'name' => 'simulador', 'nickname' => 'sim-a27',
            'email' => 'sim-a27@simulador.invalid', 'password' => Hash::make(bin2hex(random_bytes(8))),
        ]);

        return Colony::create(['user_id' => $u->id, 'name' => 'Descartável', 'x' => 0, 'y' => 0]);
    }

    private function veiculoDescartavel(
        Colony $c,
        string $tipo,
        int $nivel,
        UpgradeVeiculo $upgrade,
        TransportSetting $config,
    ): Vehicle {
        return $c->vehicles()->create([
            'type' => $tipo,
            'level' => $nivel,
            'status' => 'ocioso',
            'capacity' => $upgrade->capacidade($tipo, $nivel, $config),
        ]);
    }
}
