<?php

namespace App\Domain\Federacao;

use App\Models\Federation;
use App\Models\FederationSetting;
use App\Models\NeutralZone;
use Illuminate\Support\Facades\DB;

/**
 * Quanto uma federação concentra (A2.5) — e **quanto falta para o limite bater**.
 *
 * ## O defeito que esta classe existe para corrigir
 *
 * O limite antimonopólio territorial existe desde o D-119 e funciona: `OcuparZonaNeutra` recusa a
 * ocupação quando a federação já tem 20% de todas as zonas do jogo. Mas ele **bloqueia sem avisar**
 * — o colono descobre o teto no instante em que bate nele, depois de ter transportado tropa e
 * material até a zona.
 *
 * O roadmap da A2.5 nomeia isso com precisão: *"criar proteções antimonopólio **observáveis**"*. A
 * proteção já era; o que faltava era poder vê-la chegando.
 *
 * ## ⚠️ A conta é a MESMA de `OcuparZonaNeutra`, e tem de continuar sendo
 *
 * Duas contas para o mesmo limite divergiriam no primeiro ajuste, e a tela passaria a dizer "você
 * pode" enquanto o domínio diz "você não pode" — o pior tipo de discordância, porque a tela é quem
 * o jogador acredita. Se um dia esta fórmula mudar, muda nos dois lugares, e há teste que compara os
 * dois resultados justamente para isso.
 */
class Concentracao
{
    public function __construct(private readonly Aliancas $aliancas) {}

    /**
     * @return array{
     *     zonas_da_federacao:int, zonas_do_jogo:int, ocupacao_bps:int, teto_bps:int,
     *     no_teto:bool, zonas_ate_o_teto:int, membros:int, membros_max:int, fert_micro:int,
     *     federacoes_no_bloco:int
     * }
     */
    public function de(Federation $federacao): array
    {
        $totalDeZonas = NeutralZone::whereNotNull('owner_colony_id')->count();

        /*
         * ⚠️ Conta o BLOCO, como `OcuparZonaNeutra` passou a contar (A2.5). Se a tela mostrasse só
         * as zonas da federação enquanto o domínio recusa pelo bloco, ela diria "cabem mais 3" e a
         * ocupação seria negada — a pior discordância possível, porque a tela é quem o jogador
         * acredita. Sem aliança, o bloco é a própria federação e nada muda.
         */
        $bloco = $this->aliancas->bloco($federacao->id);

        $daFederacao = NeutralZone::whereNotNull('owner_colony_id')
            ->whereHas('owner', fn ($q) => $q->whereIn('federation_id', $bloco))
            ->count();

        $tetoBps = (int) FederationSetting::singleton()->teto_ocupacao_zonas_bps;

        // Mesma expressão de `OcuparZonaNeutra::conferirTetoDaFederacao()`, inclusive o `intdiv`.
        $ocupacaoBps = $totalDeZonas === 0 ? 0 : intdiv($daFederacao * 10_000, $totalDeZonas);

        return [
            'zonas_da_federacao' => $daFederacao,
            'zonas_do_jogo' => $totalDeZonas,
            'ocupacao_bps' => $ocupacaoBps,
            'teto_bps' => $tetoBps,
            'no_teto' => $totalDeZonas > 0 && $ocupacaoBps >= $tetoBps,
            'zonas_ate_o_teto' => $this->zonasAteOTeto($daFederacao, $totalDeZonas, $tetoBps),
            'membros' => $federacao->membros()->count(),
            'membros_max' => Federation::MAX_COLONIAS,
            // Quantas federações o bloco reúne — 1 quando não há aliança. A tela precisa dizer de
            // quem é o número acima, senão "17%" parece errado para quem só contou as suas zonas.
            'federacoes_no_bloco' => count($bloco),
            'fert_micro' => (int) DB::table('colonies')
                ->where('federation_id', $federacao->id)->sum('fert_micro'),
        ];
    }

    /**
     * Quantas zonas ainda cabem antes de o teto travar.
     *
     * ⚠️ **O denominador cresce junto**, e é isto que torna a conta não-óbvia: cada zona que a
     * federação ocupa também aumenta o total de zonas ocupadas do jogo. Uma regra de três simples
     * daria um número errado — e errado para MENOS, o que faria a tela assustar sem motivo.
     *
     * Por isso o cálculo é iterativo: simula ocupar mais uma, e mais uma, até a expressão do domínio
     * recusar. É barato (o teto é de 20%, então a conta acaba em poucas voltas) e não pode divergir
     * da regra, porque usa exatamente a mesma expressão.
     */
    private function zonasAteOTeto(int $daFederacao, int $totalDeZonas, int $tetoBps): int
    {
        if ($totalDeZonas === 0) {
            return 0;
        }

        $cabem = 0;

        // O limite de segurança evita laço infinito se alguém puser o teto em 100%.
        while ($cabem < $totalDeZonas + 1) {
            $delas = $daFederacao + $cabem;
            $total = $totalDeZonas + $cabem;

            if (intdiv($delas * 10_000, $total) >= $tetoBps) {
                break;
            }

            $cabem++;
        }

        return $cabem;
    }
}
