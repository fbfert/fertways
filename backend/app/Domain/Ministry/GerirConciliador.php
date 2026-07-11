<?php

namespace App\Domain\Ministry;

use App\Models\User;

/**
 * Gestão do cargo de conciliador (§9.3, §26.6). Ato da equipe: nomear, demitir, reintegrar, suspender.
 *
 * A lógica vivia embutida no `fertways:conciliador`; foi extraída para o comando e o painel de
 * administração compartilharem o mesmo domínio. Ver D-44 e D-56.
 */
class GerirConciliador
{
    /** Nomeia. Devolve false se já era conciliador (sem efeito). Zera reversões e suspensão. */
    public function nomear(User $colono): bool
    {
        if ($colono->conciliador_desde) {
            return false;
        }

        $colono->forceFill([
            'conciliador_desde' => now(),
            'conciliador_suspenso_em' => null,
            'reversoes' => 0,
            // Nasce sem salário pago: recebe os 50 F$ do §26.7 já no próximo tick.
            'salario_pago_em' => null,
        ])->save();

        return true;
    }

    /**
     * Demite. **Não** zera as reversões de propósito: um demitido que reentra com cinco reversões
     * penduradas seria suspenso no primeiro erro (a nomeação é que zera).
     */
    public function demitir(User $colono): void
    {
        $colono->forceFill(['conciliador_desde' => null, 'conciliador_suspenso_em' => null])->save();
    }

    /** Levanta a suspensão e zera o contador de reversões do §26.7. */
    public function reintegrar(User $colono): void
    {
        $colono->forceFill(['conciliador_suspenso_em' => null, 'reversoes' => 0])->save();
    }

    /** Suspende à mão (o automático é o `Apelacao::reverter` ao atingir o limite). */
    public function suspender(User $colono): void
    {
        $colono->forceFill(['conciliador_suspenso_em' => now()])->save();
    }
}
