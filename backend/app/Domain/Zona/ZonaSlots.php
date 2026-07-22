<?php

namespace App\Domain\Zona;

use App\Exceptions\DomainRuleException;

/**
 * Os slots da zona neutra (docs/decisoes.md D-144) — o "visual como o da colônia" que o usuário
 * pediu, e o "crescimento por nível" que ele pediu junto, numa peça só.
 *
 * **A colmeia é a MESMA de `Domain\Colony\Slots`**: `LINHAS=[4,4,5,4,4,1]`, `TOTAL=22`. É a mesma
 * matemática de layout — mesmos hexágonos, mesmas proporções —, só desenhada em SVG em vez de
 * Phaser (a zona continua testável por e2e; ver `frontend/src/ui/Zona.tsx`). O slot 10 (centro) é
 * fixo para o Posto de Comando: o mesmo lugar que a colônia reserva para o Depósito Local (D-142) —
 * o centro pertence à construção mais essencial/mais aberta.
 *
 * **Crescimento por nível é conceito NOVO — a colônia não tem isto.** Os 22 slots da colônia
 * existem todos desde a fundação; aqui, o nível da zona (upgrade do Posto de Comando, §8.6) é que
 * desbloqueia slot por slot. O nível 1 já libera 12 dos 21 slots livres — o bastante para as 12
 * `Estruturas::CONSTRUIVEIS` de hoje, uma cada, para que NENHUMA zona já ocupada em produção
 * (120 delas) fique com estrutura órfã depois da migration, não importa o nível em que estava. Do
 * nível 2 ao 10 (o teto sobe de 5 para 10, D-144, mesmo precedente do Depósito da colônia no D-108)
 * desbloqueia +1 slot por nível, fechando em 21 livres + 1 fixo = 22 no nível 10 — o mesmo total da
 * colônia.
 */
class ZonaSlots
{
    public const LINHAS = [4, 4, 5, 4, 4, 1];

    public const TOTAL = 22;

    /** O centro da colmeia (meio da linha de 5) — sempre o Posto de Comando, nunca escolhível. */
    public const POSTO_SLOT = 10;

    /**
     * Os 12 slots livres já desbloqueados no nível 1 — a bijeção com o backfill da migration
     * (ver `2026_07_21_150000_slots_da_zona_neutra.php`): nenhuma zona existente pode ficar sem
     * lugar para uma estrutura que já tinha erguido.
     */
    public const NIVEL1_SLOTS = [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 11, 12];

    /** Os 9 slots restantes, um desbloqueado por nível, do nível 2 ao 10. */
    public const ORDEM_DESBLOQUEIO = [13, 14, 15, 16, 17, 18, 19, 20, 21];

    /** Todo slot livre (não-Posto), desbloqueado ou não — para desenhar a colmeia inteira. */
    public static function livres(): array
    {
        return [...self::NIVEL1_SLOTS, ...self::ORDEM_DESBLOQUEIO];
    }

    /** Quais slots já estão desbloqueados no nível `$nivelDaZona` (1 a 10). */
    public static function desbloqueadosAte(int $nivelDaZona): array
    {
        $extras = array_slice(self::ORDEM_DESBLOQUEIO, 0, max(0, $nivelDaZona - 1));

        return [...self::NIVEL1_SLOTS, ...$extras];
    }

    /** Recusa slot fora da colmeia, o slot do Posto, e slot ainda trancado no nível atual. */
    public static function exigirEscolhivel(int $slot, int $nivelDaZona): void
    {
        if ($slot < 0 || $slot >= self::TOTAL) {
            throw new DomainRuleException(
                'slot_inexistente',
                'A zona tem '.self::TOTAL.' slots, numerados de 0 a '.(self::TOTAL - 1).'.',
            );
        }

        if ($slot === self::POSTO_SLOT) {
            throw new DomainRuleException(
                'slot_do_posto',
                'Este slot é do Posto de Comando: nasce com a ocupação, e não pode ser trocado.',
            );
        }

        if (! in_array($slot, self::desbloqueadosAte($nivelDaZona), true)) {
            throw new DomainRuleException(
                'slot_trancado',
                'Este slot ainda está trancado. Suba o nível da zona para desbloqueá-lo.',
            );
        }
    }
}
