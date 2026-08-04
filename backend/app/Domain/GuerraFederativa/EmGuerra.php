<?php

namespace App\Domain\GuerraFederativa;

use App\Models\FederationWar;
use Illuminate\Support\Facades\DB;

/**
 * A pergunta "estes dois estão em guerra?", num lugar só (A2.10).
 *
 * ## ⚠️ Por que não é uma linha inline em cada chamador
 *
 * Já são três os que precisam dela — `AtacarColonia` (a revogação do §01), o `WarController`
 * (a lista de inimigos) e agora o saque total da zona conquistada —, e as três perguntam a mesma
 * coisa de jeitos ligeiramente diferentes. A `Neutralidade` tem uma quarta versão, privada.
 *
 * Se a definição de "em guerra" mudar (uma trégua por evento, uma guerra suspensa), quem tiver a
 * cópia esquecida passa a responder o contrário das outras — e o jogador vê a mesma guerra existir
 * numa tela e não existir na outra. É o mesmo argumento que manteve `defensorOffline()` com uma
 * definição só.
 *
 * ## ⚠️ Lê a federação do BANCO, não do modelo em mãos
 *
 * `$colony->federation_id` num modelo carregado há minutos pode estar velho — e neste caso o erro é
 * caro nos dois sentidos: saquear tudo de quem já saiu da guerra, ou proteger quem já entrou nela.
 * O combate marcha por horas; entre o despacho e a chegada dá tempo de a federação mudar.
 */
class EmGuerra
{
    /** As duas federações estão em guerra ativa? Sem federação, ou na mesma, não estão. */
    public function entreFederacoes(?int $a, ?int $b): bool
    {
        if ($a === null || $b === null || $a === $b) {
            return false;
        }

        return FederationWar::entre($a, $b)->where('status', 'ativa')->exists();
    }

    /**
     * Esta federação está em guerra com **alguém**?
     *
     * É a pergunta do lado do defensor: as minhas zonas estão sob a regra do saque total? Ele não
     * sabe de quem virá o ataque, e não precisa saber — se há uma guerra ativa, o Depósito dele
     * deixou de proteger, e é isso que a tela tem de dizer (D-205).
     */
    public function federacaoEmGuerra(?int $federationId): bool
    {
        if ($federationId === null) {
            return false;
        }

        return FederationWar::where('status', 'ativa')
            ->where(fn ($q) => $q
                ->where('declarante_id', $federationId)
                ->orWhere('alvo_id', $federationId))
            ->exists();
    }

    /** O mesmo, a partir dos ids das colônias — relendo a federação de cada uma agora. */
    public function entreColonias(?int $a, ?int $b): bool
    {
        return $this->guerraEntreColonias($a, $b) !== null;
    }

    /**
     * QUAL guerra, e não só se há uma (D-207).
     *
     * O saque total (D-205) só precisava do sim-ou-não. O rating precisa do id: é ele que o
     * `combats.war_id` grava no despacho, e sem essa marca nenhuma batalha é atribuível à guerra
     * que a causou — o que deixaria o desfecho por prazo sem como ser decidido.
     */
    public function guerraEntreColonias(?int $a, ?int $b): ?FederationWar
    {
        if ($a === null || $b === null || $a === $b) {
            return null;
        }

        $federacoes = DB::table('colonies')
            ->whereIn('id', [$a, $b])
            ->pluck('federation_id', 'id');

        $fa = $federacoes[$a] ?? null;
        $fb = $federacoes[$b] ?? null;

        if ($fa === null || $fb === null || $fa === $fb) {
            return null;
        }

        return FederationWar::entre((int) $fa, (int) $fb)->where('status', 'ativa')->first();
    }
}
