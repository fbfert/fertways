<?php

namespace App\Domain\GuerraFederativa;

use App\Models\Combat;
use App\Models\Federation;
use App\Models\FederationWar;
use App\Models\WarSetting;
use Illuminate\Support\Facades\DB;

/**
 * O ranking federativo (A2.10, decisão 10 — GDD §14).
 *
 * *"O ranking mede guerras travadas, não guerras vencidas. Ranking que considera a diferença de
 * força entre os dois lados premia enfrentar quem é páreo."*
 *
 * ## A fórmula
 *
 *     esperado = 1 / (1 + 10^((rating_do_outro − meu_rating) / 400))
 *     rating  += K × (resultado − esperado)
 *
 * Vencer quem já se esperava vencer quase não move o número; vencer quem era favorito move muito. O
 * *"premia enfrentar quem é páreo"* do §14 **não precisa de peso inventado**: sai daí sozinho.
 *
 * ## ⚠️ Soma zero, e é por isso que esta fórmula foi escolhida
 *
 * O que um lado ganha o outro perde, exatamente. Contra o ataque da decisão 11 — duas federações
 * amigas guerreando entre si para subir juntas —, **o par não ganha nada líquido**. As alternativas
 * de pontos acumulados faziam as duas subirem, e dependeriam de detecção humana para o que aqui é
 * aritmética.
 *
 * ⚠️ **Por isso não há piso.** Um chão devolveria o ganho ao par encenado: o perdedor pararia de
 * cair e o vencedor continuaria a subir. O §12 proíbe perda permanente de território, não de posição
 * num placar.
 *
 * ## Os três desfechos, e de onde cada um vem
 *
 * | fim da guerra | resultado do declarante |
 * |---|---|
 * | capitulação  | 1 ou 0, conforme quem se rendeu |
 * | tratado      | 0,5 — ninguém venceu, e o tratado não move espólio |
 * | prazo        | pelo **saldo**: zonas tomadas; empatando, o saque; empatando, empate |
 *
 * O saldo por prazo é o motivo de `combats.war_id` existir: sem a marca, "quem levou a melhor nesta
 * guerra" não teria resposta, e uma guerra de sete dias de batalhas acabaria como empate técnico.
 */
class RatingFederativo
{
    public const INICIAL = 1000;

    /**
     * Aplica o resultado de uma guerra ao rating das duas federações.
     *
     * ⚠️ **Idempotente por construção**: quem chama é o encerramento, que só acontece uma vez porque
     * ele mesmo muda o `status` para fora de `ativa` dentro da mesma transação. Um segundo
     * encerramento é recusado antes de chegar aqui.
     *
     * @param  float  $resultadoDoDeclarante  1 (venceu), 0,5 (empate), 0 (perdeu)
     */
    public function aplicar(FederationWar $guerra, float $resultadoDoDeclarante): void
    {
        DB::transaction(function () use ($guerra, $resultadoDoDeclarante) {
            $declarante = Federation::whereKey($guerra->declarante_id)->lockForUpdate()->first();
            $alvo = Federation::whereKey($guerra->alvo_id)->lockForUpdate()->first();

            if (! $declarante || ! $alvo) {
                return;   // federação dissolvida no meio da guerra: não há a quem creditar.
            }

            $k = (int) WarSetting::singleton()->rating_k;

            $ra = (int) $declarante->rating_guerra;
            $rb = (int) $alvo->rating_guerra;

            $esperadoA = 1 / (1 + 10 ** (($rb - $ra) / 400));

            /*
             * ⚠️ O delta do alvo é o SIMÉTRICO do delta do declarante, e não uma segunda conta.
             *
             * Calcular os dois lados em separado com `round()` em cada um faria a soma dar ±1 por
             * arredondamento — e a soma zero, que é a razão de ser desta fórmula, deixaria de valer
             * exatamente onde mais importa: numa guerra encenada, repetida muitas vezes, o resíduo
             * viraria ganho.
             */
            $delta = (int) round($k * ($resultadoDoDeclarante - $esperadoA));

            $declarante->update(['rating_guerra' => $ra + $delta]);
            $alvo->update(['rating_guerra' => $rb - $delta]);

            $guerra->update(['rating_delta' => $delta]);
        });
    }

    /**
     * O resultado de uma guerra que acabou **pelo prazo** — ninguém se rendeu, ninguém assinou paz.
     *
     * Primeiro as zonas: é o que o §2 diz que a guerra disputa. Empatando, o saque, que é a outra
     * coisa que ela move. Empatando de novo, empate de verdade — sete dias sem que nenhum dos dois
     * tirasse nada do outro **é** um empate, e inventar um critério de desempate ali seria premiar
     * quem declarou por ter declarado.
     */
    public function resultadoPorSaldo(FederationWar $guerra): float
    {
        $porFederacao = $this->saldoDaGuerra($guerra);

        $a = $porFederacao[(int) $guerra->declarante_id] ?? ['zonas' => 0, 'saque' => 0];
        $b = $porFederacao[(int) $guerra->alvo_id] ?? ['zonas' => 0, 'saque' => 0];

        return match (true) {
            $a['zonas'] !== $b['zonas'] => $a['zonas'] > $b['zonas'] ? 1.0 : 0.0,
            $a['saque'] !== $b['saque'] => $a['saque'] > $b['saque'] ? 1.0 : 0.0,
            default => 0.5,
        };
    }

    /**
     * Quanto cada federação tirou da outra nesta guerra.
     *
     * Lê os combates **marcados com esta guerra** (`combats.war_id`, gravado no despacho). Zonas
     * tomadas são invasões vencidas pelo atacante; o saque é o que o próprio combate registrou no
     * resultado — inclui o cerco de colônia, que leva espólio sem tomar território.
     *
     * @return array<int, array{zonas:int, saque:int}>
     */
    public function saldoDaGuerra(FederationWar $guerra): array
    {
        $saldo = [];

        $combates = Combat::where('war_id', $guerra->id)
            ->where('status', 'vitoria_atacante')
            ->get(['attacker_colony_id', 'zone_id', 'tipo', 'resultado']);

        if ($combates->isEmpty()) {
            return $saldo;
        }

        $federacaoDe = DB::table('colonies')
            ->whereIn('id', $combates->pluck('attacker_colony_id')->unique())
            ->pluck('federation_id', 'id');

        foreach ($combates as $c) {
            $f = $federacaoDe[$c->attacker_colony_id] ?? null;

            if ($f === null) {
                continue;
            }

            $f = (int) $f;
            $saldo[$f] ??= ['zonas' => 0, 'saque' => 0];

            // Só a invasão de ZONA toma território; o cerco de colônia (zone_id nulo) leva espólio.
            if ($c->tipo === 'invasao' && $c->zone_id !== null) {
                $saldo[$f]['zonas']++;
            }

            $saldo[$f]['saque'] += (int) ($c->resultado['saque'] ?? 0);
        }

        return $saldo;
    }
}
