<?php

namespace App\Domain\Trade;

use App\Models\TradeAgreement;

/**
 * Vence os Acordos de Troca cujo prazo passou. Chamado pelo tick.
 *
 * Fica fora do laço por colônia, como `ConcluirTrechos`: um acordo tem dois donos, e o relógio do
 * prazo não tem relação com o `last_tick_at` de nenhuma das duas colônias.
 *
 * §26.5: "Se o prazo do acordo expira sem cumprimento por uma das partes, isso vira automaticamente
 * evidência anexada a uma denúncia pré-preenchida no Ministério das Reputações". A denúncia ainda
 * não existe (D-44); o que existe é o registro de `quebrado` e a perda de Confiança Comercial.
 */
class ExpirarAcordos
{
    public function __construct(private Reputacao $reputacao) {}

    /** @return int quantos acordos venceram */
    public function handle(): int
    {
        $vencidos = TradeAgreement::whereIn('status', ['proposto', 'aceito'])
            ->where('deadline_at', '<=', now())
            ->orderBy('id')
            ->get();

        $fechados = 0;

        foreach ($vencidos as $acordo) {
            $fechados += $this->vencer($acordo) ? 1 : 0;
        }

        return $fechados;
    }

    private function vencer(TradeAgreement $acordo): bool
    {
        /*
         * Proposta que ninguém confirmou não pune ninguém: o §26.5 diz que ela "não tem valor de
         * evidência completa". Some do caminho como `cancelado`, sem tocar em reputação — daí
         * passar `reputation_applied` sem penalizadas e com status que não é `quebrado`.
         */
        if ($acordo->status === 'proposto') {
            $acordo->forceFill(['status' => 'cancelado', 'reputation_applied' => true])->save();

            return true;
        }

        $inadimplentes = $acordo->inadimplentes();

        // Os dois entregaram tudo e o tick chegou antes de `CreditarEntrega` fechar o acordo —
        // possível quando a última carga chega no mesmo segundo do prazo. Ninguém caloteou.
        if ($inadimplentes === []) {
            return $this->reputacao->fechar($acordo, 'executado', []);
        }

        return $this->reputacao->fechar($acordo, 'quebrado', $inadimplentes);
    }
}
