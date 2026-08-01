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
        {--estoque-inicial=100 : de cada essencial, no começo}
        {--consumo= : sobrepõe o consumo por colono/hora, em milésimos: agua:100,oxigenio:120,biomassa:80,energia:60}
        {--crescimento= : sobrepõe o crescimento por hora, em bps (50 = 0,5%/h)}
        {--capacidade-base= : sobrepõe a capacidade da Estrutura de Sobrevivência no nível 1}
        {--operadores= : operadores exigidos por NÍVEL de construção produtora (torna mensurável o §7.3)}
        {--predios= : a colônia simulada, ex.: fazenda:3,captacao_de_agua:3,gerador_de_atmosfera:3,reator_de_energia:3}';

    protected $description = 'Trilha A2.S: exercita o modelo de população real num mundo descartável';

    public function handle(Parametros $parametros, Populacao $populacao, Ciclo $ciclo): int
    {
        $dias = max(1, (int) $this->option('dias'));
        $passo = max(0.01, (float) $this->option('passo-horas'));
        $producao = $this->parseProducao((string) ($this->option('producao') ?? ''));

        /*
         * ⚠️ As sobreposições são gravadas DENTRO da transação que será revertida — ver `handle()`.
         * É o que permite comparar configurações sem tocar no `population_settings` de verdade: a
         * arbitragem precisa de várias rodadas, e nenhuma delas pode deixar rastro.
         */
        DB::beginTransaction();

        $this->aplicarSobreposicoes();
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

    /**
     * Grava as sobreposições da rodada. Dentro da transação, então nada sobrevive.
     *
     * Existe para a **arbitragem**: comparar quatro configurações exige quatro rodadas, e mexer no
     * `population_settings` de verdade entre elas deixaria o banco num estado que ninguém escolheu.
     */
    private function aplicarSobreposicoes(): void
    {
        $mudanca = [];

        foreach ($this->parseConsumo((string) ($this->option('consumo') ?? '')) as $recurso => $milli) {
            $mudanca[$recurso.'_milli_por_colono_hora'] = $milli;
        }

        if ($this->option('crescimento') !== null) {
            $mudanca['crescimento_bps_hora'] = (int) $this->option('crescimento');
        }

        if ($this->option('capacidade-base') !== null) {
            $mudanca['capacidade_base'] = (int) $this->option('capacidade-base');
        }

        if ($mudanca !== []) {
            DB::table('population_settings')->where('id', 1)->update($mudanca);
        }

        /*
         * Requisitos de operador, para o §7.3 deixar de sair como "não medível". A regra é a mais
         * simples que respeita o princípio do §7.4 — "poucos humanos operam muitos robôs": N
         * operadores por NÍVEL de construção produtora. É HIPÓTESE como todo o resto.
         */
        if ($this->option('operadores') !== null) {
            $porNivel = max(0, (int) $this->option('operadores'));
            $linhas = [];

            foreach (DB::table('building_specs')->whereNotNull('producao_hora_json')->get() as $spec) {
                $linhas[] = [
                    'building_type' => $spec->building_type,
                    'level' => $spec->level,
                    'operadores' => $porNivel * (int) $spec->level,
                    'created_at' => now(), 'updated_at' => now(),
                ];
            }

            /*
             * `upsert`, e não `insert`: desde que o `BuildingOperatorRequirementSeeder` existe, a
             * tabela já vem preenchida — e um `insert` colide com a chave única (tipo, nível). A
             * sobreposição precisa PODER substituir o que está lá, senão não é sobreposição.
             */
            foreach (array_chunk($linhas, 500) as $lote) {
                DB::table('building_operator_requirements')
                    ->upsert($lote, ['building_type', 'level'], ['operadores', 'updated_at']);
            }
        }
    }

    /** @return array<string,int> */
    private function parseConsumo(string $texto): array
    {
        $out = [];

        foreach (array_filter(explode(',', $texto)) as $par) {
            [$r, $v] = array_pad(explode(':', $par, 2), 2, null);
            $r = trim((string) $r);

            if (in_array($r, ['agua', 'oxigenio', 'biomassa', 'energia'], true)) {
                $out[$r] = (int) $v;
            }
        }

        return $out;
    }

    private function rodar(int $dias, float $passo, array $producao, Populacao $populacao, Ciclo $ciclo): array
    {
        $colonia = $this->coloniaDaRodada = $this->mundoDescartavel();

        $estoque = [];
        foreach (['agua', 'oxigenio', 'biomassa', 'energia'] as $r) {
            $estoque[$r] = (float) $this->option('estoque-inicial');
        }

        $capacidade = $populacao->capacidade($colonia);
        $linhas = [];
        $this->consumoAcumulado = 0.0;
        $this->producaoAcumulada = 0.0;
        $this->diaMetadeDoTeto = null;
        $this->diaTetoCheio = null;
        $horasTotais = $dias * 24;

        for ($h = 0; $h < $horasTotais; $h += $passo) {
            // Produção do intervalo entra antes do consumo — a colônia produz e depois se sustenta.
            foreach ($producao as $recurso => $porHora) {
                $estoque[$recurso] = ($estoque[$recurso] ?? 0) + $porHora * $passo;
                $this->producaoAcumulada += $porHora * $passo;
            }

            $r = $ciclo->avancar($colonia, $estoque, $passo);

            foreach ($r['consumo'] as $recurso => $quanto) {
                $estoque[$recurso] = max(0, $estoque[$recurso] - $quanto);
                $this->consumoAcumulado += $quanto;
            }

            /*
             * Marcas para medir a RECUPERAÇÃO: quanto tempo leva de metade do teto até o teto.
             * É a pergunta "quão rápido se recupera de uma escassez" traduzida em algo observável.
             */
            $dia = ($h + $passo) / 24;

            if ($this->diaMetadeDoTeto === null && $capacidade > 0 && $r['populacao_nova'] >= $capacidade / 2) {
                $this->diaMetadeDoTeto = $dia;
            }

            if ($this->diaTetoCheio === null && $capacidade > 0 && $r['populacao_nova'] >= $capacidade) {
                $this->diaTetoCheio = $dia;
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
    /** Guardada para o veredito medir a população comprometida sobre a colônia real da rodada. */
    private ?Colony $coloniaDaRodada = null;

    private float $consumoAcumulado = 0.0;

    private float $producaoAcumulada = 0.0;

    private ?float $diaMetadeDoTeto = null;

    private ?float $diaTetoCheio = null;

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

        $predios = ['estrutura_de_sobrevivencia' => (int) $this->option('nivel-habitacao')];

        foreach (array_filter(explode(',', (string) ($this->option('predios') ?? ''))) as $par) {
            [$t, $n] = array_pad(explode(':', $par, 2), 2, 1);
            $predios[trim((string) $t)] = max(1, (int) $n);
        }

        $slot = 0;

        foreach ($predios as $tipo => $nivel) {
            DB::table('buildings')->insert([
                'colony_id' => $colonyId, 'type' => $tipo, 'level' => $nivel, 'slot' => $slot++,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

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
         * ── Quanto da produção a POPULAÇÃO come.
         *
         * É a pergunta "quanto tempo uma colônia abandonada aguenta" reformulada em algo que o
         * modelo realmente decide. Com a produção rodando, a colônia não definha sozinha; o que
         * importa é quanto sobra para construir e comerciar depois de alimentar quem já está lá.
         */
        if ($this->producaoAcumulada > 0) {
            $fatia = (int) round(100 * $this->consumoAcumulado / $this->producaoAcumulada);

            /*
             * ⚠️ A faixa baixa NÃO é reprovação — é a decisão do D-177.
             *
             * População no FERTWAYS é **restrição de mão de obra**, não economia de comida. Consumo
             * per capita alto duplicaria o que a energia já faz (toda construção já consome energia
             * por hora), e o §7.2 proíbe "virar The Sims dentro de Fertways". A métrica que decide é
             * a do §7.3, logo abaixo — comprometimento, que é trabalho.
             *
             * O número continua sendo medido e mostrado: quem quiser mudar a decisão precisa ver o
             * que está mudando. Mas a ferramenta não vai chamar de defeito o que foi escolhido.
             */
            $leitura = match (true) {
                $fatia < 10 => ['tempero, não economia — decisão do D-177', 'gray'],
                $fatia <= 35 => ['CUSTO REAL que ainda deixa expandir', 'green'],
                $fatia < 60 => ['pesada: sobra pouco para construir', 'yellow'],
                default => ['a população come a colônia', 'red'],
            };

            $this->line("  · A população consome <options=bold>{$fatia}%</> da produção de essenciais");
            $this->line("  <fg={$leitura[1]}>    → {$leitura[0]}</>");
        }

        // ── Recuperação: de metade do teto até o teto.
        if ($this->diaMetadeDoTeto !== null && $this->diaTetoCheio !== null) {
            $dias = $this->diaTetoCheio - $this->diaMetadeDoTeto;

            $leitura = match (true) {
                $dias < 1.5 => ['instantânea — escassez sem consequência', 'yellow'],
                $dias <= 5.5 => ['DIAS, não horas nem semanas', 'green'],
                default => ['lenta: um erro custa semanas', 'red'],
            };

            $this->line('  · Recuperação de metade do teto até o teto: <options=bold>'
                .number_format($dias, 1, ',', '.').' dias</>');
            $this->line("  <fg={$leitura[1]}>    → {$leitura[0]}</>");
        }

        /*
         * A métrica-chave da §7.3 — percentual de população comprometida em operação.
         *
         * Continua saindo como "não mensurável" quando não há requisito de operador cadastrado:
         * imprimir "0%" seria ausência de dado com cara de resultado, e é exatamente a confusão que
         * o painel de métricas (D-165) existe para evitar.
         */
        $this->newLine();

        if (DB::table('building_operator_requirements')->count() === 0) {
            $this->line('<fg=yellow>  ⚠️ População comprometida (§7.3): não mensurável nesta rodada.</>');
            $this->line('<fg=gray>     Sem `--operadores`, não há requisito em que incidir, e "0%" seria</>');
            $this->line('<fg=gray>     ausência de dado com cara de resultado.</>');

            return;
        }

        $estado = $populacao->estado($this->coloniaDaRodada);
        $comprometida = $estado['em_construcoes'] + $estado['em_zonas'];
        $pct = $estado['total'] > 0 ? (int) round(100 * $comprometida / $estado['total']) : 0;

        $faixa = match (true) {
            $pct < 20 => ['população irrelevante', 'yellow'],
            $pct <= 70 => ['DECISÃO ESTRATÉGICA — a faixa que o §7.3 quer', 'green'],
            $pct < 95 => ['apertada, perto do gargalo', 'yellow'],
            default => ['FRUSTRAÇÃO — gargalo excessivo', 'red'],
        };

        $this->line("  · População comprometida (§7.3): <options=bold>{$pct}%</> ".
            "({$comprometida} de {$estado['total']} colonos)");
        $this->line("  <fg={$faixa[1]}>    → {$faixa[0]}</>");
    }
}
