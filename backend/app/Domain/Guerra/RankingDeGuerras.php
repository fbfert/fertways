<?php

namespace App\Domain\Guerra;

use App\Models\Colony;
use App\Models\Combat;
use App\Models\Federation;
use App\Models\Ledger;
use App\Models\ResourceType;
use App\Models\ZoneEvent;
use Illuminate\Support\Collection;

/**
 * O Ranking de Guerras (GDD §27.13) — normalização por percentil.
 *
 * O documento publica a fórmula por inteiro, com exemplo: cada sub-ranking vira um "percentil" —
 * na prática, o valor do jogador dividido pelo MÁXIMO do servidor, vezes 100 (não é um rank
 * estatístico de verdade; é a escala linear que o próprio GDD chama assim, e seguimos o texto ao pé
 * da letra: "jogador com 5 vitórias, máximo no servidor = 200 → percentil = 2,5"). O Ranking Geral
 * é a soma ponderada desses cinco percentis.
 *
 * ## ✅ O sexto sub-ranking, que ficou vazio por quatro fases e enfim tem de onde sair
 *
 * *"Guerras Vencidas (Federação)"* (10%) ficava de fora porque **não existia guerra federativa**:
 * todo combate era entre duas colônias, nunca entre federações como partes. Preenchê-lo somando as
 * vitórias dos membros teria sido duplicar o sub-ranking 2 num agregado sem base — arbitragem nova
 * disfarçada de extensão. Ficou registrado aqui como pendência para o usuário revisitar.
 *
 * A A2.10 criou o conceito que faltava. Preenchido no D-207 com o **rating Elo da federação**
 * (`GuerraFederativa\RatingFederativo`), que é o que o próprio §27.13 sugere ao dizer que este
 * sub-ranking é *"só no ranking de federações"*: é estatística da federação, e cada membro herda o
 * percentil da dela.
 *
 * **E com isso os pesos passam a somar 100.** Os cinco publicados (25+20+20+15+10) somavam 90, e
 * esta classe recusou renormalizar para não corrigir a régua do documento por conta própria — a
 * recusa estava certa: não faltava correção, faltava a sexta parcela.
 *
 * **"Vitórias" e "sequência" seguem a régua que o próprio jogo já usa para "vitória" — o
 * `combate_vencido` do Marco (D-75, `ConcederXp`)**: invasão vencida pelo atacante, invasão
 * repelida pelo defensor, cerco rompido por quem socorreu. Não inventamos uma segunda definição de
 * vitória: cerco por prazo (`rendido`, o atacante fica com 30% de saque mas a zona não muda de
 * dono) e sabotagem/apreensão NUNCA disparam `combate_vencido` no motor de combate — ficam de fora
 * daqui pelo mesmo motivo.
 */
class RankingDeGuerras
{
    private const PESO_ZONAS_CONQUISTADAS = 25;

    private const PESO_VITORIAS = 20;

    private const PESO_TEMPO_DE_CONTROLE = 20;

    private const PESO_SAQUE = 15;

    private const PESO_SEQUENCIA = 10;

    /**
     * ⚠️ O sexto sub-ranking, vazio desde o D-128 e preenchido agora (D-207).
     *
     * O §27.13 lhe dá peso 10 e diz que ele é *"só no ranking de federações"*. O docblock desta
     * classe registrou que ficava de fora porque **não existia guerra federativa** — e que
     * preenchê-lo somando as vitórias dos membros seria duplicar o sub-ranking 2 num agregado sem
     * base. A A2.10 criou o conceito que faltava, e a resposta honesta é a que o próprio §27.13
     * aponta: **é uma estatística da federação**, então cada membro herda o percentil da dela.
     *
     * Com ele, os pesos passam a somar **100** — os 25+20+20+15+10 do documento somavam 90, e a
     * classe recusou renormalizar justamente para não corrigir a régua do documento por conta
     * própria. Não havia o que corrigir: faltava a sexta parcela, e ela chegou.
     */
    private const PESO_FEDERACAO = 10;

    public function geral(): Collection
    {
        $zonas = $this->zonasConquistadas();
        $guerra = $this->vitoriasESequencia();
        $tempo = $this->tempoDeControleHoras();
        $saque = $this->saqueEmFert();
        $ratings = $this->ratingDaFederacao();

        $linhas = Colony::orderBy('id')->pluck('name', 'id')->map(fn ($nome, $id) => [
            'colony_id' => $id,
            'colony_name' => $nome,
            'rating_federacao' => $ratings[$id] ?? 0,
            'zonas_conquistadas' => $zonas[$id] ?? 0,
            'vitorias' => $guerra[$id]['vitorias'] ?? 0,
            'sequencia' => $guerra[$id]['sequencia'] ?? 0,
            'tempo_de_controle_horas' => round($tempo[$id] ?? 0, 1),
            'saque_fert' => round($saque[$id] ?? 0, 2),
        ])->values();

        $maximo = fn (string $campo) => $linhas->max($campo) ?: 0;
        $maxZonas = $maximo('zonas_conquistadas');
        $maxVitorias = $maximo('vitorias');
        $maxTempo = $maximo('tempo_de_controle_horas');
        $maxSaque = $maximo('saque_fert');
        $maxSequencia = $maximo('sequencia');
        $maxRating = $maximo('rating_federacao');

        $percentil = fn (int|float $valor, int|float $max) => $max > 0 ? ($valor / $max) * 100 : 0.0;

        return $linhas->map(function ($l) use ($percentil, $maxZonas, $maxVitorias, $maxTempo, $maxSaque, $maxSequencia, $maxRating) {
            $pZonas = $percentil($l['zonas_conquistadas'], $maxZonas);
            $pVitorias = $percentil($l['vitorias'], $maxVitorias);
            $pTempo = $percentil($l['tempo_de_controle_horas'], $maxTempo);
            $pSaque = $percentil($l['saque_fert'], $maxSaque);
            $pSequencia = $percentil($l['sequencia'], $maxSequencia);
            $pFederacao = $percentil($l['rating_federacao'], $maxRating);

            $geral = $pZonas * self::PESO_ZONAS_CONQUISTADAS
                + $pVitorias * self::PESO_VITORIAS
                + $pTempo * self::PESO_TEMPO_DE_CONTROLE
                + $pSaque * self::PESO_SAQUE
                + $pSequencia * self::PESO_SEQUENCIA
                + $pFederacao * self::PESO_FEDERACAO;

            return array_merge($l, [
                'percentil' => [
                    'zonas_conquistadas' => round($pZonas, 1),
                    'vitorias' => round($pVitorias, 1),
                    'tempo_de_controle' => round($pTempo, 1),
                    'saque' => round($pSaque, 1),
                    'sequencia' => round($pSequencia, 1),
                    'federacao' => round($pFederacao, 1),
                ],
                'geral' => round($geral / 100, 1),
            ]);
        })
            ->sortByDesc('geral')
            ->values();
    }

    /**
     * O rating da federação de cada colônia (D-207) — o sexto sub-ranking.
     *
     * Colônia sem federação fica com zero, e não com o rating inicial: quem não está em federação
     * nenhuma não tem posição no ranking **federativo**, e dar-lhe os 1.000 de partida faria um
     * solitário empatar com uma federação que nunca perdeu uma guerra.
     *
     * @return Collection<int,int> rating por colony_id
     */
    private function ratingDaFederacao(): Collection
    {
        $porFederacao = Federation::whereNull('disbanded_at')->pluck('rating_guerra', 'id');

        return Colony::whereNotNull('federation_id')
            ->pluck('federation_id', 'id')
            ->map(fn ($f) => (int) ($porFederacao[$f] ?? 0));
    }

    private function zonasConquistadas(): Collection
    {
        return ZoneEvent::where('type', 'conquistada')
            ->selectRaw('colony_id, count(*) as total')
            ->groupBy('colony_id')
            ->pluck('total', 'colony_id');
    }

    private function vencedorDe(Combat $c): ?int
    {
        return match (true) {
            $c->tipo === 'invasao' && $c->status === 'vitoria_atacante' => $c->attacker_colony_id,
            $c->tipo === 'invasao' && $c->status === 'repelido' => $c->defender_colony_id,
            $c->tipo === 'ruptura' && $c->status === 'vitoria_atacante' => $c->attacker_colony_id,
            default => null,
        };
    }

    private function perdedorDe(Combat $c): ?int
    {
        return match (true) {
            $c->tipo === 'invasao' && $c->status === 'vitoria_atacante' => $c->defender_colony_id,
            $c->tipo === 'invasao' && $c->status === 'repelido' => $c->attacker_colony_id,
            $c->tipo === 'ruptura' && $c->status === 'vitoria_atacante' => $c->defender_colony_id,
            // O socorro que falha (D-70, `socorroDestruido`) não credita vitória ao sitiante — o
            // próprio jogo não faz isso — mas É uma derrota pra sequência de quem tentou socorrer.
            $c->tipo === 'ruptura' && $c->status === 'repelido' => $c->attacker_colony_id,
            default => null,
        };
    }

    /**
     * Uma passada só, em ordem cronológica (`updated_at` — todo combate terminal para de ser
     * tocado, D-70/`Combat::vivo()`): soma vitórias e anda a sequência atual de cada colônia,
     * zerando a de quem perde.
     */
    private function vitoriasESequencia(): array
    {
        $combates = Combat::whereIn('tipo', ['invasao', 'ruptura'])
            ->whereIn('status', ['vitoria_atacante', 'repelido'])
            ->orderBy('updated_at')
            ->get(['tipo', 'status', 'attacker_colony_id', 'defender_colony_id']);

        $vitorias = [];
        $sequenciaAtual = [];
        $sequenciaMaxima = [];

        foreach ($combates as $c) {
            $vencedor = $this->vencedorDe($c);
            $perdedor = $this->perdedorDe($c);

            if ($vencedor !== null) {
                $vitorias[$vencedor] = ($vitorias[$vencedor] ?? 0) + 1;
                $sequenciaAtual[$vencedor] = ($sequenciaAtual[$vencedor] ?? 0) + 1;
                $sequenciaMaxima[$vencedor] = max($sequenciaMaxima[$vencedor] ?? 0, $sequenciaAtual[$vencedor]);
            }

            if ($perdedor !== null) {
                $sequenciaAtual[$perdedor] = 0;
            }
        }

        $resultado = [];
        foreach ($vitorias as $id => $total) {
            $resultado[$id] = ['vitorias' => $total, 'sequencia' => $sequenciaMaxima[$id]];
        }

        return $resultado;
    }

    /**
     * Tempo de controle: reconstruído do histórico de posse da zona (D-86, `ZoneEvent`). Cada
     * intervalo vai de "começou a controlar" (`ocupada`/`conquistada`/`cedida`) até "parou"
     * (tomada por outro, abandonada, ou AGORA — se ainda é o dono). Zona por zona, em ordem
     * cronológica.
     *
     * ⚠️ **`cedida` entra aqui e NÃO em `zonasConquistadas()`** (D-206). Uma zona entregue numa
     * capitulação muda de dono de verdade — ignorá-la faria o tempo de controle continuar a correr
     * para quem já não a tem —, mas não foi **conquistada**. Contá-la como conquista deixaria duas
     * federações amigas encenarem uma guerra e trocarem zonas para subir no ranking.
     */
    private function tempoDeControleHoras(): array
    {
        $eventos = ZoneEvent::whereIn('type', ['ocupada', 'conquistada', 'cedida', 'abandonada'])
            ->orderBy('zone_id')
            ->orderBy('created_at')
            ->get(['zone_id', 'type', 'colony_id', 'created_at']);

        $horas = [];
        $atual = null;
        $zonaAtual = null;
        $agora = now();

        $fechar = function ($ate) use (&$atual, &$horas) {
            if ($atual === null) {
                return;
            }
            $horas[$atual['colony_id']] = ($horas[$atual['colony_id']] ?? 0)
                + $atual['desde']->diffInSeconds($ate) / 3600;
        };

        foreach ($eventos as $e) {
            if ($e->zone_id !== $zonaAtual) {
                $fechar($agora);
                $atual = null;
                $zonaAtual = $e->zone_id;
            }

            if (in_array($e->type, ['ocupada', 'conquistada', 'cedida'], true)) {
                $fechar($e->created_at);
                $atual = ['colony_id' => $e->colony_id, 'desde' => $e->created_at];
            } elseif ($e->type === 'abandonada') {
                $fechar($e->created_at);
                $atual = null;
            }
        }
        $fechar($agora);

        return $horas;
    }

    /** Recursos saqueados (§27.13), convertidos a Fert$ pelo preço do catálogo (D-34). */
    private function saqueEmFert(): array
    {
        $precos = ResourceType::pluck('preco_base_micro', 'code');

        return Ledger::where('type', 'saque_de_guerra')
            ->get(['colony_id', 'resource_type', 'amount'])
            ->groupBy('colony_id')
            ->map(fn ($linhas) => $linhas->sum(
                fn ($l) => $l->amount * ($precos[$l->resource_type] ?? 0) / 1_000_000,
            ))
            ->all();
    }
}
