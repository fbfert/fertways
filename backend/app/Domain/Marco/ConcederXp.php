<?php

namespace App\Domain\Marco;

use App\Models\Colony;
use App\Models\MilestoneSetting;
use App\Models\XpEntry;

/**
 * Concede XP por um ato — a ÚNICA porta de entrada do ledger de XP (D-75).
 *
 * Cada ato registrado vale o que o painel do operador declara (`milestone_settings`), e deixa uma
 * linha em `xp_entries`: XP não nasce sem história, pela mesma regra do ledger de recursos. O
 * `colonies.xp` é só o cache do somatório, incrementado na mesma escrita.
 *
 * As ações e onde elas disparam:
 *
 *   obra_concluida       ColonyTick::concluir (por nível) e CreateColony (as 5 essenciais, ×5)
 *   zona_ocupada         OcuparZonaNeutra
 *   combate_vencido      ResolverCombates — conquista (atacante), defesa que segura (defensor),
 *                        cerco rompido (o sitiado que saiu a campo e venceu)
 *   acordo_executado     Reputacao::fechar — os DOIS lados, e só acima do piso do D-43: o XP herda
 *                        o anti-farm da reputação (uma unidade de minério mil vezes não sobe marco)
 *   mercado_executado    ExecutarOrdem — os dois lados
 *
 * Quando as missões do §06 existirem, pagam XP por AQUI — é o gancho que o GDD pediu.
 */
class ConcederXp
{
    private const CAMPO = [
        'obra_concluida' => 'xp_obra_por_nivel',
        'zona_ocupada' => 'xp_zona_ocupada',
        'combate_vencido' => 'xp_combate_vencido',
        'acordo_executado' => 'xp_acordo_executado',
        'mercado_executado' => 'xp_mercado_executado',
    ];

    public function handle(int $colonyId, string $acao, ?string $ref = null, int $vezes = 1): void
    {
        $porVez = (int) MilestoneSetting::singleton()->{self::CAMPO[$acao]};
        $xp = $porVez * max(1, $vezes);

        // Zerar um valor no painel DESLIGA a fonte — sem linhas vazias sujando o ledger.
        if ($xp <= 0) {
            return;
        }

        XpEntry::create([
            'colony_id' => $colonyId,
            'acao' => $acao,
            'xp' => $xp,
            'ref' => $ref !== null ? mb_substr($ref, 0, 80) : null,
            'created_at' => now(),
        ]);

        Colony::whereKey($colonyId)->increment('xp', $xp);
    }
}
