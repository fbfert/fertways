<?php

namespace App\Console\Commands;

use App\Domain\Endurance\EfeitosDaEndurance;
use App\Domain\Pesquisa\Efeitos;
use App\Domain\Pesquisa\Pesquisar;
use App\Domain\Pesquisa\Vagas;
use App\Exceptions\DomainRuleException;
use App\Models\Colony;
use App\Models\Technology;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * TRILHA A2.S — segunda entrega: custo e duração de tecnologia (A2.3).
 *
 * Responde à pergunta que o `BALANCEAMENTO.md` §8.3 usa como critério de fracasso:
 * **"se a maioria dos jogadores pesquisar a mesma sequência, a árvore falhou"**.
 *
 * ## Como ele responde isso
 *
 * Monta **arquétipos de colônia** — perfis de construção diferentes — e faz cada um escolher
 * gulosamente a melhor tecnologia disponível, várias vezes. Se todos escolherem o mesmo primeiro
 * degrau, e depois a mesma sequência, a árvore é dominante: não há escolha, há um caminho ótimo que
 * qualquer um descobre.
 *
 * A escolha usa **números reais do jogo**, não inventados aqui:
 *
 * - o benefício sai de `building_specs.producao_hora_json` (quanto o prédio produz) vezes
 *   `resource_types.preco_base_micro` (quanto aquilo vale) vezes o bônus em bps da tecnologia;
 * - o custo sai do `custo_json` da tecnologia, também convertido pelo preço base;
 * - e a **disponibilidade** sai do `Pesquisar` de verdade — nível de Laboratório, pré-requisito,
 *   vaga e recurso. Regra 1 da trilha: reusa o domínio, não o reimplementa.
 *
 * ## ⚠️ O que aqui é MODELO, e não verdade do jogo
 *
 * Duas coisas, e é importante não confundi-las com resultado:
 *
 * 1. **Os arquétipos são invenção minha.** "Colônia energética", "agrícola", "mineradora" — o jogo
 *    não tem esses perfis; são um recorte para ver se perfis diferentes escolhem diferente.
 * 2. **A função de valor é uma tese sobre o jogador**: ele maximiza retorno por Fert$ investido,
 *    penalizado pelo tempo. Um jogador real pode pesquisar defesa por medo, não por payback.
 *
 * O que o comando mede com honestidade é: **dado um jogador que otimiza retorno, a árvore o obriga
 * a escolher, ou entrega a todos a mesma resposta?**
 *
 *     php84 artisan fertways:simular-pesquisa --passos=3
 */
class SimularPesquisa extends Command
{
    protected $signature = 'fertways:simular-pesquisa
        {--passos=3 : quantas tecnologias cada arquétipo pesquisa em sequência}
        {--nivel-laboratorio=3 : nível do Laboratório em todos os arquétipos}';

    protected $description = 'Trilha A2.S: existe sequência dominante na árvore de pesquisa?';

    /**
     * Os perfis. Cada um é um conjunto de construções com níveis — nada além disso.
     *
     * @var array<string,array<string,int>>
     */
    private const ARQUETIPOS = [
        'energética' => ['reator_de_energia' => 4, 'fazenda' => 1, 'mina_local' => 1, 'refinaria_quimica' => 1],
        'agrícola' => ['fazenda' => 4, 'reator_de_energia' => 1, 'mina_local' => 1, 'refinaria_quimica' => 1],
        'mineradora' => ['mina_local' => 4, 'reator_de_energia' => 1, 'fazenda' => 1, 'refinaria_quimica' => 1],
        'industrial' => ['refinaria_quimica' => 4, 'reator_de_energia' => 2, 'fazenda' => 1, 'mina_local' => 1],
        'generalista' => ['reator_de_energia' => 2, 'fazenda' => 2, 'mina_local' => 2, 'refinaria_quimica' => 2],
    ];

    public function handle(Pesquisar $pesquisar, Vagas $vagas): int
    {
        $passos = max(1, (int) $this->option('passos'));
        $nivelLab = max(1, (int) $this->option('nivel-laboratorio'));

        $this->line('<options=bold>TRILHA A2.S — a árvore de pesquisa tem sequência dominante?</>');
        $this->line("Arquétipos: ".count(self::ARQUETIPOS)." · passos: {$passos} · Laboratório: {$nivelLab}");
        $this->newLine();

        $precos = DB::table('resource_types')->pluck('preco_base_micro', 'code');
        $producao = $this->producaoPorTipoENivel();

        DB::beginTransaction();

        try {
            $sequencias = [];

            foreach (self::ARQUETIPOS as $nome => $predios) {
                $sequencias[$nome] = $this->sequenciaDe($nome, $predios, $nivelLab, $passos, $precos, $producao, $pesquisar, $vagas);
            }

            $this->imprimir($sequencias, $passos);
            $this->imprimirPorque($precos, $producao);
        } finally {
            DB::rollBack();
            $this->newLine();
            $this->line('<fg=gray>Mundo descartado: a transação foi revertida, nada foi gravado.</>');
        }

        return self::SUCCESS;
    }

    /**
     * A escolha gulosa de um arquétipo, passo a passo.
     *
     * Cada passo pergunta ao **domínio real** o que é pesquisável (chamando `Pesquisar::handle()`
     * e vendo o que ele aceita) e escolhe, entre os aceitos, o de melhor retorno.
     */
    private function sequenciaDe(string $nome, array $predios, int $nivelLab, int $passos, $precos, array $producao, Pesquisar $pesquisar, Vagas $vagas): array
    {
        $colonia = $this->colonia($nome, $predios, $nivelLab);
        $escolhidas = [];

        for ($passo = 0; $passo < $passos; $passo++) {
            $candidatas = [];

            foreach (Technology::where('ativa', true)->orderBy('id')->get() as $t) {
                if (in_array($t->chave, $escolhidas, true)) {
                    continue;
                }

                /*
                 * A disponibilidade é decidida pelo domínio: tentamos iniciar de verdade dentro de
                 * um savepoint e desfazemos. Reimplementar as portas aqui seria justamente o que a
                 * regra 1 da trilha proíbe — a cópia divergiria do jogo na primeira mudança.
                 */
                if (! $this->consegueIniciar($colonia, $t, $pesquisar)) {
                    continue;
                }

                $candidatas[$t->chave] = $this->retornoEmHoras($t, $predios, $precos, $producao);
            }

            if ($candidatas === []) {
                break;
            }

            // Melhor retorno = menor tempo de payback. Empate resolve pela ordem do catálogo.
            asort($candidatas);
            $melhor = array_key_first($candidatas);
            $escolhidas[] = $melhor;

            // Conclui na hora para liberar a vaga e destravar o pré-requisito do passo seguinte.
            $this->concluirAgora($colonia, Technology::where('chave', $melhor)->firstOrFail(), $pesquisar);
        }

        return $escolhidas;
    }

    private function consegueIniciar(Colony $colonia, Technology $t, Pesquisar $pesquisar): bool
    {
        DB::beginTransaction();

        try {
            $pesquisar->handle($colonia, $t);

            return true;
        } catch (DomainRuleException) {
            return false;
        } finally {
            DB::rollBack();
        }
    }

    private function concluirAgora(Colony $colonia, Technology $t, Pesquisar $pesquisar): void
    {
        $pesquisar->handle($colonia, $t);

        DB::table('colony_technologies')
            ->where('colony_id', $colonia->id)->where('technology_id', $t->id)
            ->update(['status' => 'concluida', 'nivel' => DB::raw('nivel + 1'), 'finishes_at' => now()]);
    }

    /**
     * Quantas horas a tecnologia leva para se pagar, somadas à duração da própria pesquisa.
     *
     * Retorno em horas: quanto menor, melhor. Uma tecnologia sem benefício mensurável para este
     * arquétipo devolve infinito — e é assim que a colônia agrícola descarta a pesquisa de reator.
     */
    private function retornoEmHoras(Technology $t, array $predios, $precos, array $producao): float
    {
        $custoFert = 0.0;

        foreach ($t->custo_json as $recurso => $qtd) {
            $custoFert += $qtd * (float) ($precos[$recurso] ?? 0);
        }

        $ganhoPorHora = 0.0;

        foreach ($t->efeitos_json ?? [] as $efeito) {
            /*
             * ⚠️ Só `producao_bonus` é avaliado. Desconto de tributo, velocidade e capacidade de
             * veículo dependem de volume de comércio e de logística, que este recorte não modela —
             * e chutar um volume seria inventar o número que decide a resposta. As tecnologias
             * dessas trilhas saem com retorno infinito, e o relatório diz isso em vez de fingir.
             */
            if (($efeito['tipo'] ?? null) !== EfeitosDaEndurance::PRODUCAO_BONUS) {
                continue;
            }

            $alvo = $efeito['alvo'] ?? '';
            $nivel = (int) ($predios[$alvo] ?? 0);

            if ($nivel < 1) {
                continue; // o arquétipo não tem esse prédio: o bônus não vale nada para ele
            }

            foreach ($producao[$alvo][$nivel] ?? [] as $recurso => $porHora) {
                $ganhoPorHora += $porHora * (float) ($precos[$recurso] ?? 0)
                    * ((int) ($efeito['valor_bps'] ?? 0)) / 10000;
            }
        }

        if ($ganhoPorHora <= 0) {
            return INF;
        }

        return $custoFert / $ganhoPorHora + $t->duracao_segundos / 3600;
    }

    /**
     * O "por quê" do veredito — um veredito sem causa é difícil de agir.
     *
     * Mostra, por tecnologia, o custo em Fert$ e o melhor retorno entre todos os arquétipos. É aqui
     * que costuma aparecer que a escolha não é decidida pelo EFEITO, e sim pela COMPOSIÇÃO DO CUSTO:
     * uma tecnologia que pede um recurso caro perde para qualquer outra, por melhor que seja.
     */
    private function imprimirPorque($precos, array $producao): void
    {
        $linhas = [];

        foreach (Technology::where('ativa', true)->orderBy('id')->get() as $t) {
            $custo = 0.0;
            $caro = ['', 0.0];

            foreach ($t->custo_json as $recurso => $qtd) {
                $parcela = $qtd * (float) ($precos[$recurso] ?? 0);
                $custo += $parcela;

                if ($parcela > $caro[1]) {
                    $caro = [$recurso, $parcela];
                }
            }

            $melhor = INF;
            foreach (self::ARQUETIPOS as $predios) {
                $melhor = min($melhor, $this->retornoEmHoras($t, $predios, $precos, $producao));
            }

            $linhas[] = [
                $t->chave,
                number_format($custo / 1_000_000, 2, ',', '.'),
                $caro[0].' ('.round(100 * $caro[1] / max(1, $custo)).'%)',
                is_infinite($melhor) ? $this->porQueNaoMedivel($t) : number_format($melhor, 0, ',', '.').' h',
            ];
        }

        $this->newLine();
        $this->line('<options=bold>Por quê — custo e retorno de cada tecnologia</>');
        $this->table(['tecnologia', 'custo (Fert$)', 'recurso que domina o custo', 'melhor retorno'], $linhas);
    }

    /**
     * Por que uma tecnologia saiu como "não medível" — e são razões DIFERENTES.
     *
     * Confundi-las seria o erro: "sem consumidor" quer dizer que o efeito não faz nada no jogo hoje
     * (defeito a corrigir); "sem volume modelado" quer dizer que ele faz, mas este recorte não sabe
     * medir (limitação da ferramenta); e "alvo não produz" é bônus de produção em prédio que não
     * produz — inerte por construção.
     */
    private function porQueNaoMedivel(Technology $t): string
    {
        foreach ($t->efeitos_json ?? [] as $e) {
            $tipo = $e['tipo'] ?? '';

            if ($tipo === Efeitos::DEFESA_BONUS) {
                return 'sem consumidor';
            }

            if (in_array($tipo, [Efeitos::DURACAO_PESQUISA], true)) {
                return 'outra unidade';
            }

            if ($tipo !== EfeitosDaEndurance::PRODUCAO_BONUS) {
                return 'sem volume modelado';
            }
        }

        return 'alvo não produz';
    }

    /** @return array<string,array<int,array<string,float>>> */
    private function producaoPorTipoENivel(): array
    {
        $out = [];

        foreach (DB::table('building_specs')->get(['building_type', 'level', 'producao_hora_json']) as $s) {
            $out[$s->building_type][(int) $s->level] = json_decode($s->producao_hora_json ?? '{}', true) ?: [];
        }

        return $out;
    }

    private function colonia(string $nome, array $predios, int $nivelLab): Colony
    {
        $userId = DB::table('users')->insertGetId([
            'name' => $nome, 'nickname' => 'sim'.random_int(100000, 999999),
            'email' => 'sim'.random_int(100000, 999999).'@simulador.local',
            'password' => 'x', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $colonyId = DB::table('colonies')->insertGetId([
            'user_id' => $userId, 'name' => $nome,
            'x' => random_int(-40, 40), 'y' => random_int(-40, 40),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach ($predios + ['laboratorio' => $nivelLab] as $tipo => $nivel) {
            DB::table('buildings')->insert([
                'colony_id' => $colonyId, 'type' => $tipo, 'level' => $nivel,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        // Recurso à vontade: o que se está medindo é ESCOLHA, não capacidade de pagar.
        foreach (DB::table('resource_types')->pluck('code') as $code) {
            DB::table('resources')->insert([
                'colony_id' => $colonyId, 'resource_type' => $code, 'amount' => 10_000_000,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        // Liga a pesquisa só dentro do mundo descartável.
        DB::table('research_settings')->where('id', 1)->update(['ativo' => true]);

        return Colony::with('buildings')->findOrFail($colonyId);
    }

    private function imprimir(array $sequencias, int $passos): void
    {
        $linhas = [];

        foreach ($sequencias as $nome => $seq) {
            $linhas[] = [$nome, $seq === [] ? '(nada pesquisável)' : implode(' → ', $seq)];
        }

        $this->table(['arquétipo', 'sequência escolhida'], $linhas);

        // ── o veredito
        $primeiras = array_filter(array_map(fn ($s) => $s[0] ?? null, $sequencias));
        $contagem = array_count_values($primeiras);
        arsort($contagem);

        $total = count($sequencias);
        $maisComum = array_key_first($contagem) ?? null;
        $quantos = $maisComum ? $contagem[$maisComum] : 0;

        $this->newLine();
        $this->line('<options=bold>Veredito (§8.3)</>');

        if ($maisComum === null) {
            $this->line('  Nenhum arquétipo conseguiu pesquisar nada — confira o nível de Laboratório.');

            return;
        }

        $pct = round(100 * $quantos / $total);
        $this->line("  · Primeira escolha mais comum: <options=bold>{$maisComum}</> — {$quantos}/{$total} arquétipos ({$pct}%).");

        $sequenciasUnicas = count(array_unique(array_map(fn ($s) => implode('>', $s), $sequencias)));
        $this->line("  · Sequências distintas: <options=bold>{$sequenciasUnicas}</> de {$total}.");

        if ($pct >= 80) {
            $this->line('  <fg=red>⚠️ ÁRVORE DOMINANTE pelo §8.3: quase todo perfil começa igual.</>');
            $this->line('  <fg=gray>     Não há escolha — há um caminho ótimo que qualquer um descobre.</>');
        } elseif ($sequenciasUnicas === $total) {
            $this->line('  <fg=green>✓ Cada perfil escolhe uma sequência própria: a árvore obriga a escolher.</>');
        } else {
            $this->line('  <fg=yellow>· Há convergência parcial. Vale olhar quais perfis coincidem e por quê.</>');
        }

        $this->newLine();
        $this->line('<fg=yellow>  ⚠️ O que este comando NÃO mede:</>');
        $this->line('<fg=gray>     Só `producao_bonus` é avaliado. Desconto de tributo, velocidade e</>');
        $this->line('<fg=gray>     capacidade de veículo dependem de volume de comércio e de logística,</>');
        $this->line('<fg=gray>     que este recorte não modela — e chutar um volume seria inventar o</>');
        $this->line('<fg=gray>     número que decide a resposta. As trilhas de Comércio e Logística</>');
        $this->line('<fg=gray>     ficam, portanto, fora do páreo aqui, e não porque sejam ruins.</>');
    }
}
