<?php

namespace App\Console\Commands;

use App\Domain\Marco\Curva;
use App\Models\Colony;
use App\Models\MilestoneSetting;
use App\Models\XpEntry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * O XP retroativo (D-75): os veteranos acordam no marco certo.
 *
 * O Marco nasceu com o servidor já andando — há colônias com prédios, zonas, acordos e guerras nas
 * costas, e XP zero. A arbitragem do usuário foi "posse preservada + XP retroativo": este comando
 * lê o que cada colônia FEZ (do estado e dos registros que sempre existiram) e credita como se o
 * ledger de XP tivesse estado lá desde o início.
 *
 *     artisan fertways:marco               # simula: mostra o que cada colônia receberia
 *     artisan fertways:marco --aplicar     # credita
 *
 * **Idempotente por reescrita:** as linhas retroativas têm `ref` prefixado com `retro:` e são
 * apagadas e reescritas a cada `--aplicar` — rodar duas vezes não dobra nada. As linhas VIVAS
 * (pós-D-75) nunca são tocadas; o `colonies.xp` é recomposto do somatório inteiro ao final.
 *
 * O que se conta, e as aproximações assumidas:
 *
 *   obras      os níveis DE PÉ hoje (o demolido não conta — o ledger vivo também não devolve XP
 *              de demolição; registrado como aproximação aceitável)
 *   zonas      as que a colônia possui hoje (a que foi perdida em guerra não conta para quem a
 *              perdeu — e o conquistador a conta pela vitória, não pela ocupação)
 *   combates   vitórias registradas em `combats`: conquistas, defesas seguradas (menos sabotagem
 *              detectada, que tem `resultado.detectado`) e rupturas vencidas
 *   acordos    `executado` acima do piso do D-43, para os dois lados
 *   mercado    execuções em `tax_events` (kind mercado_venda) acima do piso — só o VENDEDOR:
 *              o comprador histórico não deixou rastro barato de recuperar, e aproximar para
 *              menos é mais honesto que inventar
 */
class RecalcularMarco extends Command
{
    protected $signature = 'fertways:marco {--aplicar : credita; sem isto, só simula}';

    protected $description = 'Recalcula o XP retroativo do Marco (D-75) a partir do histórico';

    public function handle(): int
    {
        $config = MilestoneSetting::singleton();
        $aplicar = (bool) $this->option('aplicar');
        $agora = now();

        foreach (Colony::orderBy('id')->get() as $colony) {
            $fontes = $this->fontes($colony, $config);
            $total = array_sum(array_column($fontes, 'xp'));

            if ($aplicar) {
                DB::transaction(function () use ($colony, $fontes, $agora) {
                    XpEntry::where('colony_id', $colony->id)->where('ref', 'like', 'retro:%')->delete();

                    foreach ($fontes as $acao => $f) {
                        if ($f['xp'] > 0) {
                            XpEntry::create([
                                'colony_id' => $colony->id,
                                'acao' => $acao,
                                'xp' => $f['xp'],
                                'ref' => "retro:{$f['detalhe']}",
                                'created_at' => $agora,
                            ]);
                        }
                    }

                    // O cache é recomposto do LEDGER INTEIRO, não incrementado: é a única conta
                    // que não deriva à medida que o retro é reescrito.
                    $colony->forceFill([
                        'xp' => (int) XpEntry::where('colony_id', $colony->id)->sum('xp'),
                    ])->save();
                });

                $colony->refresh();
            }

            $xpFinal = $aplicar ? (int) $colony->xp : $total;
            $marco = Curva::marco($xpFinal);

            $this->line(sprintf(
                '%-24s %s%6d XP → marco %d (%s)   [%s]',
                $colony->name,
                $aplicar ? '' : '~',
                $xpFinal,
                $marco,
                Curva::titulo($marco),
                collect($fontes)->map(fn ($f, $a) => "{$a}:{$f['xp']}")->implode(' '),
            ));
        }

        if (! $aplicar) {
            $this->warn('Simulação. Rode com --aplicar.');
        }

        return self::SUCCESS;
    }

    /**
     * ⚠️ **O retro DESCONTA o que o ledger vivo já pagou**, ação por ação. Sem isso, uma colônia
     * fundada DEPOIS do D-75 (que já tem os 5 níveis da fundação no ledger) receberia os mesmos
     * níveis de novo no `--aplicar` — o dobro-pagamento apareceu num teste antes de aparecer em
     * produção. A conta é em XP (total calculado − XP vivo da ação, piso zero): aproximada se o
     * operador mudou valores no meio do caminho, e aproximar para MENOS é o lado certo do erro.
     *
     * @return array<string, array{xp: int, detalhe: string}>
     */
    private function fontes(Colony $colony, MilestoneSetting $config): array
    {
        $vivo = XpEntry::where('colony_id', $colony->id)
            ->where(fn ($q) => $q->whereNull('ref')->orWhere('ref', 'not like', 'retro:%'))
            ->selectRaw('acao, SUM(xp) as xp')->groupBy('acao')->pluck('xp', 'acao');

        $niveis = (int) $colony->buildings()->sum('level');

        $zonas = (int) DB::table('neutral_zones')->where('owner_colony_id', $colony->id)->count();

        $conquistas = (int) DB::table('combats')
            ->where('attacker_colony_id', $colony->id)->where('status', 'vitoria_atacante')->count();
        $defesas = (int) DB::table('combats')
            ->where('defender_colony_id', $colony->id)->where('status', 'repelido')
            // Sabotagem detectada não é batalha vencida — o ledger vivo também não a paga.
            ->whereNot('resultado', 'like', '%detectado%')->count();

        $acordos = (int) DB::table('trade_agreements')
            ->where('status', 'executado')
            ->where('value_micro', '>=', \App\Domain\Trade\AcordoSpecs::PISO_REPUTACAO_MICRO)
            ->where(fn ($q) => $q->where('colony_a_id', $colony->id)->orWhere('colony_b_id', $colony->id))
            ->count();

        $vendas = (int) DB::table('tax_events')
            ->where('kind', 'mercado_venda')->where('colony_id', $colony->id)
            ->where('base_amount', '>=', \App\Domain\Trade\AcordoSpecs::PISO_REPUTACAO_MICRO)
            ->count();

        $bruto = [
            'obra_concluida' => ['xp' => $niveis * $config->xp_obra_por_nivel, 'detalhe' => "{$niveis} niveis"],
            'zona_ocupada' => ['xp' => $zonas * $config->xp_zona_ocupada, 'detalhe' => "{$zonas} zonas"],
            'combate_vencido' => ['xp' => ($conquistas + $defesas) * $config->xp_combate_vencido, 'detalhe' => ($conquistas + $defesas).' vitorias'],
            'acordo_executado' => ['xp' => $acordos * $config->xp_acordo_executado, 'detalhe' => "{$acordos} acordos"],
            'mercado_executado' => ['xp' => $vendas * $config->xp_mercado_executado, 'detalhe' => "{$vendas} vendas"],
        ];

        foreach ($bruto as $acao => $f) {
            $bruto[$acao]['xp'] = max(0, $f['xp'] - (int) ($vivo[$acao] ?? 0));
        }

        return $bruto;
    }
}
