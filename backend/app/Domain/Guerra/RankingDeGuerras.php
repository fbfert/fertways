<?php

namespace App\Domain\Guerra;

use App\Models\Colony;
use App\Models\Combat;
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
 * **O sexto sub-ranking do documento, "Guerras Vencidas (Federação)" (10%), fica de fora desta
 * fatia.** O próprio texto diz que ele é "só no ranking de federações" — e o jogo não tem o
 * conceito de "guerra da federação": todo combate é sempre entre DUAS COLÔNIAS, nunca entre
 * federações como partes. Preenchê-lo exigiria inventar ou uma nova mecânica (guerra declarada
 * entre federações, que o GDD não descreve em lugar nenhum) ou uma leitura arbitrária (somar as
 * vitórias dos membros por federação, duplicando o sub-ranking 2 num agregado sem base clara) —
 * as duas são arbitragem nova, não extensão de uma regra já escrita. **Julgamento do
 * desenvolvedor, sinalizado para o usuário revisitar**: os cinco pesos publicados (25+20+20+15+10)
 * somam 90, não 100 — não renormalizamos para 100: é a régua do documento, não uma correção nossa.
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

    public function geral(): Collection
    {
        $zonas = $this->zonasConquistadas();
        $guerra = $this->vitoriasESequencia();
        $tempo = $this->tempoDeControleHoras();
        $saque = $this->saqueEmFert();

        $linhas = Colony::orderBy('id')->pluck('name', 'id')->map(fn ($nome, $id) => [
            'colony_id' => $id,
            'colony_name' => $nome,
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

        $percentil = fn (int|float $valor, int|float $max) => $max > 0 ? ($valor / $max) * 100 : 0.0;

        return $linhas->map(function ($l) use ($percentil, $maxZonas, $maxVitorias, $maxTempo, $maxSaque, $maxSequencia) {
            $pZonas = $percentil($l['zonas_conquistadas'], $maxZonas);
            $pVitorias = $percentil($l['vitorias'], $maxVitorias);
            $pTempo = $percentil($l['tempo_de_controle_horas'], $maxTempo);
            $pSaque = $percentil($l['saque_fert'], $maxSaque);
            $pSequencia = $percentil($l['sequencia'], $maxSequencia);

            $geral = $pZonas * self::PESO_ZONAS_CONQUISTADAS
                + $pVitorias * self::PESO_VITORIAS
                + $pTempo * self::PESO_TEMPO_DE_CONTROLE
                + $pSaque * self::PESO_SAQUE
                + $pSequencia * self::PESO_SEQUENCIA;

            return array_merge($l, [
                'percentil' => [
                    'zonas_conquistadas' => round($pZonas, 1),
                    'vitorias' => round($pVitorias, 1),
                    'tempo_de_controle' => round($pTempo, 1),
                    'saque' => round($pSaque, 1),
                    'sequencia' => round($pSequencia, 1),
                ],
                'geral' => round($geral / 100, 1),
            ]);
        })
            ->sortByDesc('geral')
            ->values();
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
