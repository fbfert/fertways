<?php

namespace App\Domain\Telemetria;

use App\Models\TelemetryEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Os indicadores do painel de métricas (A2.0.2).
 *
 * ## O que este arquivo se recusa a fazer
 *
 * A lista da A2.0.2 pede quinze indicadores. **Nem todos têm de onde sair hoje**, porque os eventos
 * que os alimentam ainda não são emitidos por ninguém — o funil de onboarding quer
 * `onboarding_abandonado`, os gargalos de cadeia querem `falta_de_insumo`.
 *
 * Um painel que preenchesse esses com zero seria pior do que um painel incompleto: **zero e
 * "ninguém mediu" são a mesma imagem na tela e coisas opostas na realidade.** Alguém olharia o
 * funil zerado e concluiria que ninguém abandona o onboarding.
 *
 * Então cada indicador sem instrumentação aparece em `lacunas()`, nomeado, com o que falta para
 * existir. A tela mostra essa lista tão em destaque quanto os números — a ausência de medida é
 * informação de produto, não detalhe de implementação.
 *
 * ## Origem dos números
 *
 * Sessão sai de `telemetry_events`. Economia sai de `telemetry_daily`, que por sua vez deriva do
 * ledger (D-163). Riqueza e mundo saem do estado atual das tabelas de jogo. Nada é recontado a
 * partir de uma segunda fonte — quando duas fontes existem, a que manda é a mais antiga e
 * append-only.
 */
class Indicadores
{
    public function tudo(int $dias = 30): array
    {
        $desde = now()->subDays($dias)->startOfDay();

        return [
            'dias' => $dias,
            'desde' => $desde->toDateString(),
            'sessao' => $this->sessao($desde),
            'economia' => $this->economia($desde),
            'riqueza' => $this->riqueza(),
            'mundo' => $this->mundo(),
            'lacunas' => $this->lacunas(),
        ];
    }

    /**
     * DAU, WAU e o que se pode dizer sobre duração de sessão.
     *
     * ⚠️ **A duração mediana é enviesada, e o painel diz isso.** Ela sai de pares login→logout, e
     * quem fecha a aba nunca emite logout — a maioria, provavelmente. Por isso vem acompanhada da
     * cobertura: a fração de logins que teve um logout correspondente. Uma mediana de 20 minutos
     * com 15% de cobertura não é "a sessão típica dura 20 minutos"; é "das poucas sessões que
     * alguém encerrou de propósito, a metade durou menos que isso".
     */
    private function sessao(Carbon $desde): array
    {
        $logins = TelemetryEvent::where('type', 'login')
            ->where('origin', 'humano')
            ->where('created_at', '>=', $desde);

        $dau = TelemetryEvent::where('type', 'login')->where('origin', 'humano')
            ->where('created_at', '>=', now()->subDay())
            ->distinct()->count('user_id');

        $wau = TelemetryEvent::where('type', 'login')->where('origin', 'humano')
            ->where('created_at', '>=', now()->subWeek())
            ->distinct()->count('user_id');

        $totalLogins = (clone $logins)->count();
        $diasComLogin = (clone $logins)
            ->selectRaw('COUNT(DISTINCT DATE(created_at)) AS d')->value('d');

        $duracoes = $this->duracoesDeSessao($desde);

        return [
            'dau' => $dau,
            'wau' => $wau,
            'logins' => $totalLogins,
            'sessoes_por_dia' => $diasComLogin > 0 ? round($totalLogins / $diasComLogin, 1) : 0,
            'duracao_mediana_min' => $duracoes['mediana'],
            'cobertura_logout_pct' => $totalLogins > 0
                ? round(100 * $duracoes['pares'] / $totalLogins, 1)
                : 0,
            'pares_login_logout' => $duracoes['pares'],
        ];
    }

    /**
     * Emparelha cada login com o logout seguinte do mesmo jogador.
     *
     * Feito em PHP e não em SQL de propósito: a janela é de dezenas de milhares de linhas no pior
     * caso, e um `LATERAL`/subconsulta correlacionada por linha custaria mais do que ler tudo uma
     * vez. Se um dia não couber na memória, aí sim vira consulta — e aí será outra decisão.
     */
    private function duracoesDeSessao(Carbon $desde): array
    {
        $eventos = TelemetryEvent::whereIn('type', ['login', 'logout'])
            ->where('origin', 'humano')
            ->where('created_at', '>=', $desde)
            ->whereNotNull('user_id')
            ->orderBy('user_id')->orderBy('created_at')
            ->get(['user_id', 'type', 'created_at']);

        $duracoes = [];
        $abertoPor = [];

        foreach ($eventos as $e) {
            if ($e->type === 'login') {
                // Login sobre login: a sessão anterior nunca foi encerrada. Fica de fora — contar
                // como se durasse até o próximo login inventaria uma duração que ninguém observou.
                $abertoPor[$e->user_id] = $e->created_at;

                continue;
            }

            if (isset($abertoPor[$e->user_id])) {
                $duracoes[] = $abertoPor[$e->user_id]->diffInMinutes($e->created_at);
                unset($abertoPor[$e->user_id]);
            }
        }

        sort($duracoes);
        $n = count($duracoes);

        return [
            'pares' => $n,
            'mediana' => $n === 0 ? null : (int) round(
                $n % 2 ? $duracoes[intdiv($n, 2)] : ($duracoes[$n / 2 - 1] + $duracoes[$n / 2]) / 2
            ),
        ];
    }

    /**
     * Recursos e Fert$ gerados e destruídos, do retrato diário (D-163).
     *
     * `resource_type` nulo é Fert$ em micro — a mesma convenção do ledger, repetida de propósito.
     */
    private function economia(Carbon $desde): array
    {
        $porRecurso = DB::table('telemetry_daily')
            ->where('dia', '>=', $desde->toDateString())
            ->whereNotNull('resource_type')
            ->select('resource_type',
                DB::raw('SUM(produzido) AS gerado'),
                DB::raw('SUM(consumido) AS destruido'))
            ->groupBy('resource_type')
            ->orderByDesc(DB::raw('SUM(produzido)'))
            ->get();

        $fert = DB::table('telemetry_daily')
            ->where('dia', '>=', $desde->toDateString())
            ->whereNull('resource_type')
            ->selectRaw('COALESCE(SUM(produzido),0) AS emitido, COALESCE(SUM(consumido),0) AS destruido')
            ->first();

        return [
            'recursos' => $porRecurso,
            'fert_emitido_micro' => (int) ($fert->emitido ?? 0),
            'fert_destruido_micro' => (int) ($fert->destruido ?? 0),
            /*
             * Se o retrato diário estiver vazio, o painel não pode dizer "a economia parou": pode
             * ser que o agregador simplesmente não tenha rodado ainda. São coisas diferentes e a
             * tela precisa distingui-las.
             */
            'tem_retrato' => DB::table('telemetry_daily')->exists(),
        ];
    }

    /**
     * Concentração de riqueza, pela fatia dos 10% mais ricos.
     *
     * Escolhido em vez de Gini por ser lido sem treino: "os 10% mais ricos têm 60% do Fert$" diz
     * mais, para quem vai decidir balanceamento, do que "Gini 0,48". O Gini entra se e quando
     * alguém precisar comparar séries.
     */
    private function riqueza(): array
    {
        $saldos = DB::table('colonies')->orderByDesc('fert_micro')->pluck('fert_micro');
        $n = $saldos->count();

        if ($n === 0) {
            return ['colonias' => 0, 'topo_10_pct' => null, 'total_micro' => 0];
        }

        $total = (int) $saldos->sum();
        $quantos = max(1, (int) ceil($n * 0.10));
        $topo = (int) $saldos->take($quantos)->sum();

        return [
            'colonias' => $n,
            'topo_10_quantas' => $quantos,
            'topo_10_pct' => $total > 0 ? round(100 * $topo / $total, 1) : 0,
            'total_micro' => $total,
        ];
    }

    /**
     * O estado atual do mundo.
     *
     * ⚠️ Aqui mora uma lição cara: escrevi `colony_id` onde a coluna se chama `owner_colony_id`, e
     * **os treze testes passaram em verde**. O SQLite em memória não reclamou; o MariaDB reclamou
     * na primeira consulta. É a mesma regra que vale para migration (o verde do `artisan test` não
     * prova DDL) e vale também para NOME DE COLUNA em consulta crua.
     *
     * Quem mexer aqui: exercite contra o MariaDB antes de dar como pronto.
     */
    private function mundo(): array
    {
        return [
            'colonias' => DB::table('colonies')->count(),
            // `owner_colony_id`, e não `colony_id`. Escrevi o nome errado, os testes em SQLite
            // passaram, e só o MariaDB reclamou — ver o comentário no topo de `mundo()`.
            'zonas_ocupadas' => DB::table('neutral_zones')->whereNotNull('owner_colony_id')->count(),
            'zonas_totais' => DB::table('neutral_zones')->count(),
            'combates' => DB::table('combats')->count(),
            'federacoes' => DB::table('federations')->count(),
        ];
    }

    /**
     * O que a A2.0.2 pede e que **ainda não tem de onde sair**.
     *
     * Cada linha diz o indicador, o evento que falta e onde ele nasceria. É lista de trabalho, não
     * desculpa — e é o que impede alguém de ler um zero como resposta.
     *
     * @return list<array{indicador: string, falta: string, onde: string}>
     */
    public function lacunas(): array
    {
        $emitidos = TelemetryEvent::distinct()->pluck('type')->all();

        $exigidos = [
            ['indicador' => 'Funil do onboarding', 'evento' => 'onboarding_abandonado',
                'onde' => 'motor de Missões (A2.1)'],
            ['indicador' => 'Colônias fundadas por dia', 'evento' => 'colonia_fundada',
                'onde' => 'Domain\Colony\CreateColony'],
            ['indicador' => 'Gargalos de cadeia', 'evento' => 'falta_de_insumo',
                'onde' => 'EnqueueUpgrade e os demais pontos de custo'],
            ['indicador' => 'Paredes de energia', 'evento' => 'falta_de_energia',
                'onde' => 'Domain\Logistics\DespacharVeiculo'],
            ['indicador' => 'Tempo até primeiro transporte', 'evento' => 'transporte_concluido',
                'onde' => 'Domain\Logistics\ConcluirTrechos'],
            ['indicador' => 'Tempo até primeira Federação', 'evento' => 'federacao_entrou',
                'onde' => 'Domain\Federacao'],
            ['indicador' => 'Ataques enviados e recebidos', 'evento' => 'ataque_enviado',
                'onde' => 'Domain\Guerra'],
        ];

        return array_values(array_map(
            fn ($e) => [
                'indicador' => $e['indicador'],
                'falta' => $e['evento'],
                'onde' => $e['onde'],
            ],
            array_filter($exigidos, fn ($e) => ! in_array($e['evento'], $emitidos, true)),
        ));
    }
}
