<?php

namespace App\Console\Commands;

use App\Domain\Populacao\Ciclo;
use App\Domain\Populacao\Parametros;
use App\Domain\Populacao\Populacao;
use App\Models\Colony;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * TRILHA A2.S — o simulador de balanceamento, primeira entrega (escopada a população).
 *
 * ## Por que existe
 *
 * O passo 6 do fluxo da §3 do `BALANCEAMENTO.md` — *exercitar um número em escala antes de
 * promovê-lo* — ficou sem executor quando os bots saíram do escopo. Este comando é o executor.
 *
 * E o critério de saída da A2.2 é categórico: **nenhum parâmetro populacional sai de HIPÓTESE sem
 * uma rodada registrada daqui.** Os valores em `population_settings` hoje são chute com forma de
 * número; o que os transforma em decisão é a curva que este comando produz.
 *
 * ## As quatro regras da trilha, e como cada uma é cumprida
 *
 * 1. **Reusa o domínio, não o reimplementa.** Chama `Ciclo::avancar()` e `Populacao::capacidade()`
 *    — as mesmas classes que o tick do jogo chamará. Um simulador que reescreve a fórmula diverge
 *    do jogo na primeira mudança e passa a mentir com aparência de autoridade.
 * 2. **Parâmetros da mesma fonte que o jogo usa.** Lê `population_settings` pelo `Parametros`,
 *    nunca de uma cópia digitada aqui.
 * 3. **Nunca toca produção nem staging.** Roda inteiro dentro de uma transação que termina em
 *    `rollBack()` — ver `handle()`. Nada do que ele escreve sobrevive.
 * 4. **Saída legível**: curva por dia, ponto de saturação, gargalo, e os parâmetros que a
 *    produziram, impressos junto para a rodada poder ser colada no BALANCEAMENTO.
 *
 *     php84 artisan fertways:simular-populacao --dias=60 --nivel-habitacao=3 --producao=agua:40,oxigenio:30
 */
class SimularPopulacao extends Command
{
    protected $signature = 'fertways:simular-populacao
        {--dias=60 : quantos dias de tick avançar}
        {--passo-horas=1 : granularidade do avanço, em horas}
        {--populacao-inicial=5 : com quantos colonos a colônia começa}
        {--nivel-habitacao=1 : nível da Estrutura de Sobrevivência}
        {--producao= : produção por hora, ex.: agua:40,oxigenio:30,biomassa:20,energia:50}
        {--estoque-inicial=100 : de cada essencial, no começo}';

    protected $description = 'Trilha A2.S: exercita o modelo de população real num mundo descartável';

    public function handle(Parametros $parametros, Populacao $populacao, Ciclo $ciclo): int
    {
        $dias = max(1, (int) $this->option('dias'));
        $passo = max(0.01, (float) $this->option('passo-horas'));
        $producao = $this->parseProducao((string) ($this->option('producao') ?? ''));

        $parametros->recarregar();
        $p = $parametros->todos();

        $this->imprimirParametros($p, $producao);

        /*
         * ⚠️ A trava da regra 3, e ela é a mais importante do arquivo.
         *
         * O simulador PRECISA escrever — ele cria uma colônia e mexe na população dela para o
         * domínio real ter em que operar. A transação com `rollBack()` garantido no `finally` é o
         * que torna isso seguro: nada sobrevive, nem em erro, nem em interrupção.
         *
         * Não uso banco em memória separado porque isso exigiria migrar um esquema inteiro a cada
         * rodada; a transação dá a mesma garantia por um custo muito menor. O que ele NÃO pode é
         * rodar contra produção com `--aplicar` nenhum — e por isso não existe `--aplicar`.
         */
        DB::beginTransaction();

        try {
            $linhas = $this->rodar($dias, $passo, $producao, $populacao, $ciclo);
            $this->imprimirCurva($linhas, $dias);
            $this->imprimirVeredito($linhas, $populacao);
        } finally {
            DB::rollBack();
            $this->newLine();
            $this->line('<fg=gray>Mundo descartado: a transação foi revertida, nada foi gravado.</>');
        }

        return self::SUCCESS;
    }

    private function rodar(int $dias, float $passo, array $producao, Populacao $populacao, Ciclo $ciclo): array
    {
        $colonia = $this->mundoDescartavel();

        $estoque = [];
        foreach (['agua', 'oxigenio', 'biomassa', 'energia'] as $r) {
            $estoque[$r] = (float) $this->option('estoque-inicial');
        }

        $capacidade = $populacao->capacidade($colonia);
        $linhas = [];
        $horasTotais = $dias * 24;

        for ($h = 0; $h < $horasTotais; $h += $passo) {
            // Produção do intervalo entra antes do consumo — a colônia produz e depois se sustenta.
            foreach ($producao as $recurso => $porHora) {
                $estoque[$recurso] = ($estoque[$recurso] ?? 0) + $porHora * $passo;
            }

            $r = $ciclo->avancar($colonia, $estoque, $passo);

            foreach ($r['consumo'] as $recurso => $quanto) {
                $estoque[$recurso] = max(0, $estoque[$recurso] - $quanto);
            }

            $colonia->populacao = $r['populacao_nova'];
            // O resto viaja entre passos — é ele que faz a colônia pequena crescer.
            $colonia->populacao_resto_milli = $r['resto_milli'];

            // Uma linha por dia, no fim do dia — sessenta linhas se leem, 1.440 não.
            if ((int) (($h + $passo) / 24) > (int) ($h / 24) || $h + $passo >= $horasTotais) {
                $linhas[] = [
                    'dia' => (int) (($h + $passo) / 24),
                    'populacao' => $r['populacao_nova'],
                    'capacidade' => $capacidade,
                    'razao' => $r['razao_suprimento_bps'],
                    'eficiencia' => $r['eficiencia_bps'],
                    'faltou' => $r['faltou'],
                    'estoque' => $estoque,
                ];
            }
        }

        return $linhas;
    }

    /**
     * Uma colônia mínima, só com o que a população precisa enxergar.
     *
     * Não passa pelo `CreateColony`: ele monta kit inicial, veículos, ledger e missões — tudo
     * irrelevante aqui e tudo custo. O que a população lê é a Estrutura de Sobrevivência e as zonas.
     */
    private function mundoDescartavel(): Colony
    {
        $userId = DB::table('users')->insertGetId([
            'name' => 'sim', 'nickname' => 'sim'.random_int(100000, 999999),
            'email' => 'sim'.random_int(100000, 999999).'@simulador.local',
            'password' => 'x', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $colonyId = DB::table('colonies')->insertGetId([
            'user_id' => $userId, 'name' => 'Simulada', 'x' => 0, 'y' => 0,
            'populacao' => (int) $this->option('populacao-inicial'),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('buildings')->insert([
            'colony_id' => $colonyId, 'type' => 'estrutura_de_sobrevivencia',
            'level' => (int) $this->option('nivel-habitacao'),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return Colony::with('buildings')->findOrFail($colonyId);
    }

    private function parseProducao(string $texto): array
    {
        $out = [];

        foreach (array_filter(explode(',', $texto)) as $par) {
            [$r, $v] = array_pad(explode(':', $par, 2), 2, null);
            $r = trim((string) $r);

            if ($r !== '') {
                $out[$r] = (float) $v;
            }
        }

        return $out;
    }

    private function imprimirParametros(object $p, array $producao): void
    {
        $this->line('<options=bold>TRILHA A2.S — simulação de população</>');
        $this->line('Os parâmetros desta rodada (todos HIPÓTESE — BALANCEAMENTO §7.1):');
        $this->table(['parâmetro', 'valor'], [
            ['capacidade_base', $p->capacidade_base],
            ['capacidade_fator', $p->capacidade_fator_milesimos.'/1000'],
            ['crescimento_bps_hora', $p->crescimento_bps_hora],
            ['agua/colono/h (milli)', $p->agua_milli_por_colono_hora],
            ['oxigenio/colono/h (milli)', $p->oxigenio_milli_por_colono_hora],
            ['biomassa/colono/h (milli)', $p->biomassa_milli_por_colono_hora],
            ['energia/colono/h (milli)', $p->energia_milli_por_colono_hora],
            ['escassez_eficiencia_bps', $p->escassez_eficiencia_bps],
            ['crescimento_min_suprimento_bps', $p->crescimento_min_suprimento_bps],
            ['produção/h desta rodada', $producao === [] ? '(nenhuma)' : json_encode($producao)],
        ]);
    }

    private function imprimirCurva(array $linhas, int $dias): void
    {
        // Amostra, não despejo: dez pontos contam a curva; sessenta linhas ninguém lê.
        $passo = max(1, (int) ceil(count($linhas) / 10));
        $amostra = [];

        foreach ($linhas as $i => $l) {
            if ($i % $passo === 0 || $i === count($linhas) - 1) {
                $amostra[] = [
                    $l['dia'],
                    $l['populacao'].' / '.$l['capacidade'],
                    number_format($l['razao'] / 100, 1).'%',
                    number_format($l['eficiencia'] / 100, 1).'%',
                    $l['faltou'] === [] ? '—' : implode(', ', $l['faltou']),
                ];
            }
        }

        $this->newLine();
        $this->table(['dia', 'população/teto', 'suprimento', 'eficiência', 'faltando'], $amostra);
    }

    /** As quatro perguntas que a primeira entrega da trilha precisa responder. */
    private function imprimirVeredito(array $linhas, Populacao $populacao): void
    {
        $this->newLine();
        $this->line('<options=bold>Veredito</>');

        $saturou = null;
        $primeiraFalta = null;

        foreach ($linhas as $l) {
            if ($saturou === null && $l['capacidade'] > 0 && $l['populacao'] >= $l['capacidade']) {
                $saturou = $l['dia'];
            }

            if ($primeiraFalta === null && $l['faltou'] !== []) {
                $primeiraFalta = [$l['dia'], $l['faltou'][0]];
            }
        }

        $this->line($saturou === null
            ? '  · Teto habitacional: NÃO foi atingido no período simulado.'
            : "  · Teto habitacional atingido no dia <options=bold>{$saturou}</>.");

        $this->line($primeiraFalta === null
            ? '  · Nenhum essencial faltou: a produção desta rodada sustenta a população.'
            : "  · Primeiro gargalo: <options=bold>{$primeiraFalta[1]}</>, a partir do dia {$primeiraFalta[0]}.");

        $ultima = end($linhas);
        $this->line('  · Eficiência ao fim: '.number_format($ultima['eficiencia'] / 100, 1).'%');

        /*
         * A métrica-chave da §7.3 — percentual de população comprometida em operação. Aqui ela sai
         * zero por construção: o mundo descartável tem só a Estrutura de Sobrevivência e nenhum
         * requisito de operador cadastrado. Dizer isso é mais honesto do que imprimir "0%" como se
         * fosse resultado — a métrica só ganha sentido quando a A2.2.4 tiver requisitos semeados.
         */
        $this->newLine();
        $this->line('<fg=yellow>  ⚠️ Percentual de população comprometida (§7.3): ainda não mensurável.</>');
        $this->line('<fg=gray>     O mundo desta rodada não tem requisitos de operador cadastrados</>');
        $this->line('<fg=gray>     (building_operator_requirements está vazia). Sem eles, "0%" seria</>');
        $this->line('<fg=gray>     ausência de dado com cara de resultado.</>');
    }
}
