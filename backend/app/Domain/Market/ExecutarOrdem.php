<?php

namespace App\Domain\Market;

use App\Domain\Trade\AcessoAoMercado;
use App\Domain\Treasury\Tesouro;
use App\Exceptions\DomainRuleException;
use App\Models\Colony;
use App\Models\Ledger;
use App\Models\MarketOrder;
use App\Models\ResourceType;
use Illuminate\Support\Facades\DB;

/**
 * Fecha uma oferta que está na vitrine do Mercado Central (D-58).
 *
 * É o ato explícito que substituiu o casamento automático. Quem executa é o **tomador**: ele clica
 * numa oferta que já estava lá, e o preço é o **da oferta** — nunca o dele. Execução parcial é
 * permitida: uma oferta de 500 pode ser levada de 100 em 100.
 *
 * Nada aqui envolve veículo. As duas pontas do negócio já estão na Capital: o recurso sai do
 * depósito de um e entra no do outro, e os Fert$ trocam de mão. É o §25.8 — "o recurso comprado
 * entra na conta do colono no Mercado, e ele precisa enviar um veículo próprio para retirá-lo".
 * A retirada continua sendo outra viagem, e outro fato tributável.
 */
class ExecutarOrdem
{
    public function __construct(private Tesouro $tesouro) {}

    public function handle(Colony $tomador, int $ordemId, int $qtd): MarketOrder
    {
        AcessoAoMercado::exigir($tomador);

        if ($qtd <= 0) {
            throw new DomainRuleException('quantidade_invalida', 'A quantidade tem de ser positiva.');
        }

        return DB::transaction(function () use ($tomador, $ordemId, $qtd) {
            // O lock serializa dois tomadores na mesma oferta: o segundo relê `qty` já baixado e
            // ou executa o que sobrou, ou é recusado. É também o que torna a chave do `tax_event`
            // única, já que ela deriva do `qty` de antes da execução.
            $ordem = MarketOrder::whereKey($ordemId)->lockForUpdate()->first();

            if (! $ordem || ! in_array($ordem->status, ['aberta', 'parcial'], true)) {
                throw new DomainRuleException('oferta_indisponivel', 'Esta oferta não está mais aberta.');
            }

            // §26.4 trata conta-alternativa como fraude; executar a própria oferta seria a versão
            // trivial disso, e só serviria para simular volume.
            if ($ordem->colony_id === $tomador->id) {
                throw new DomainRuleException('oferta_propria', 'Você não pode executar a sua própria oferta.');
            }

            if ($qtd > $ordem->qty) {
                throw new DomainRuleException(
                    'quantidade_acima_da_oferta',
                    "Esta oferta tem só {$ordem->qty} unidade(s) restante(s).",
                );
            }

            return $ordem->side === 'sell'
                ? $this->comprarDaOferta($tomador, $ordem, $qtd)
                : $this->venderParaOferta($tomador, $ordem, $qtd);
        });
    }

    /** O tomador COMPRA de uma oferta de venda: paga Fert$ do bolso e recebe o recurso no depósito. */
    private function comprarDaOferta(Colony $comprador, MarketOrder $ordem, int $qtd): MarketOrder
    {
        $recurso = $ordem->resource_type;
        $valor = $qtd * $ordem->price_micro;

        // Ele não reservou espaço (não há oferta dele por trás): tem de caber agora.
        Deposito::exigirEspaco($comprador->id, $recurso, $qtd);

        $pagou = DB::table('colonies')
            ->where('id', $comprador->id)
            ->where('fert_micro', '>=', $valor)
            ->decrement('fert_micro', $valor);

        if ($pagou === 0) {
            throw new DomainRuleException('fert_insuficiente', 'Fert$ insuficiente para fechar esta oferta.');
        }

        $chave = $this->fechar($ordem, $ordem->colony_id, $comprador->id, $recurso, $qtd, $valor);

        $this->lancar($comprador->id, 'compra_mercado', -$valor, null, $chave);
        $this->creditarDeposito($comprador->id, $recurso, $qtd);
        $this->lancar($comprador->id, 'compra_mercado', $qtd, $recurso, $chave);

        // A venda entrega do escrow: o lote já saíra do depósito quando a oferta foi anunciada.
        $this->baixar($ordem, $qtd, escrowRecurso: $qtd, escrowFert: 0);

        return $ordem->fresh();
    }

    /** O tomador VENDE para uma oferta de compra: entrega do seu depósito e recebe do escrow dela. */
    private function venderParaOferta(Colony $vendedor, MarketOrder $ordem, int $qtd): MarketOrder
    {
        $recurso = $ordem->resource_type;
        $valor = $qtd * $ordem->price_micro;

        // §07: quem vende no Mercado Central vende do que já está na doca. O estoque da colônia
        // não alcança este canal — para isso existe o Acordo entre colonos (D-58, regra 3).
        $entregou = DB::table('market_accounts')
            ->where('colony_id', $vendedor->id)
            ->where('resource_type', $recurso)
            ->where('amount', '>=', $qtd)
            ->decrement('amount', $qtd);

        if ($entregou === 0) {
            throw new DomainRuleException(
                'saldo_mercado_insuficiente',
                "Seu depósito na Capital não tem {$qtd} de {$recurso}. Leve a carga até lá primeiro.",
            );
        }

        $chave = $this->fechar($ordem, $vendedor->id, $ordem->colony_id, $recurso, $qtd, $valor);

        // O comprador já reservou o espaço ao anunciar; a reserva encolhe na mesma medida em que o
        // saldo cresce, então o ocupado dele não muda.
        $this->creditarDeposito($ordem->colony_id, $recurso, $qtd);
        $this->lancar($ordem->colony_id, 'compra_mercado', $qtd, $recurso, $chave);

        $this->baixar($ordem, $qtd, escrowRecurso: 0, escrowFert: $valor);

        return $ordem->fresh();
    }

    /**
     * A parte comum das duas pontas: o tributo, o crédito líquido ao vendedor e o Tesouro.
     *
     * §07: "o sistema transfere o crédito líquido ao vendedor e registra a taxa de mercado". A taxa
     * é em Fert$ e recai sobre **quem vende** — confirmado pelo usuário no D-58, para não a apagar
     * por omissão. Ela não some: vai ao Ministério do Tesouro (§2.1, D-57).
     *
     * ⚠️ **`$vendedorId` nulo é o Governo** (D-87, uma oferta sem colônia dona). Não há colônia
     * para lançar no ledger nem XP a conceder a "quem vendeu" — e não há por que separar líquido de
     * taxa: os dois terminam no mesmo Tesouro, então credita-se o `$valor` inteiro de uma vez. O
     * `tax_events` ainda é gravado (com a alíquota real, para o relatório de tributo bater), porque
     * é ele que impede a mesma execução de ser processada duas vezes.
     *
     * @return string a chave do fato econômico, usada como `ref` no ledger
     */
    private function fechar(MarketOrder $ordem, ?int $vendedorId, int $compradorId, string $recurso, int $qtd, int $valor): string
    {
        /*
         * "Lote não pode ser vendido duas vezes" é critério de aceite do MVP (§16). A oferta e o
         * `qty` que ela tinha **antes** desta execução identificam o negócio: o `qty` só decresce,
         * e o `lockForUpdate` serializa quem o lê. Um retry com o mesmo estado colide no índice
         * único de `tax_events` e não tributa duas vezes.
         */
        $chave = "exec:{$ordem->id}:{$ordem->qty}";

        $bps = (int) ResourceType::find($recurso)->tax_bps;
        $taxa = intdiv($valor * $bps, 10_000);

        $inserido = DB::table('tax_events')->insertOrIgnore([
            'economic_event_key' => $chave,
            'kind' => 'mercado_venda',
            'colony_id' => $vendedorId,
            'resource_type' => $recurso,
            // Em `mercado_venda` a base é o valor em micro-Fert$, não o volume.
            'base_amount' => $valor,
            'tax_bps' => $bps,
            'tax_amount' => $taxa,
            'created_at' => now(),
        ]);

        if ($inserido === 0) {
            throw new DomainRuleException('execucao_repetida', 'Esta execução já foi registrada.');
        }

        $liquido = $valor - $taxa;

        if ($vendedorId === null) {
            // O Governo vendeu (D-87): líquido e taxa terminam no mesmo Tesouro — credita tudo
            // de uma vez, sem colônia nenhuma para lançar no ledger.
            $this->tesouro->creditarFert($valor, "venda_mercado_governo:{$chave}");
        } else {
            DB::table('colonies')->where('id', $vendedorId)->increment('fert_micro', $liquido);
            $this->lancar($vendedorId, 'venda_mercado', $liquido, null, $chave);
        }

        /*
         * O Marco anda com o comércio (D-75) — e com o MESMO piso do D-43 que protege a reputação:
         * execução abaixo de 500 Fert$ não rende XP, senão duas contas fariam volume de mentira a
         * 1 unidade por vez (a taxa de 3% tornaria o farm caro, mas caro não é impossível). O
         * Governo não é colônia: sem XP nem missão para o lado dele, só para quem comprou.
         */
        if ($valor >= \App\Domain\Trade\AcordoSpecs::PISO_REPUTACAO_MICRO) {
            $xp = app(\App\Domain\Marco\ConcederXp::class);
            $missoes = app(\App\Domain\Missoes\Progresso::class);

            if ($vendedorId !== null) {
                $xp->handle($vendedorId, 'mercado_executado', $chave);
                $missoes->registrar($vendedorId, 'mercado_executado');
            }

            $xp->handle($compradorId, 'mercado_executado', $chave);
            $missoes->registrar($compradorId, 'mercado_executado');

            // Pedido do usuário: uma missão específica para comprar do Governo, não de outro colono.
            if ($vendedorId === null) {
                $missoes->registrar($compradorId, 'compra_governo_mercado');
            }
        }

        if ($taxa > 0 && $vendedorId !== null) {
            $this->lancar($vendedorId, 'tributo', -$taxa, null, $chave);
            $this->tesouro->creditarFert($taxa, "tributo_mercado:{$chave}");
        }

        return $chave;
    }

    /** Consome quantidade e escrow da oferta, e a fecha quando não resta nada. */
    private function baixar(MarketOrder $ordem, int $qtd, int $escrowRecurso, int $escrowFert): void
    {
        $ordem->qty -= $qtd;
        $ordem->escrow_resource_qty -= $escrowRecurso;
        $ordem->escrow_fert_micro -= $escrowFert;
        $ordem->status = $ordem->qty > 0 ? 'parcial' : 'executada';
        $ordem->save();
    }

    private function creditarDeposito(int $colonyId, string $recurso, int $qtd): void
    {
        DB::table('market_accounts')->insertOrIgnore([
            'colony_id' => $colonyId, 'resource_type' => $recurso, 'amount' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('market_accounts')
            ->where('colony_id', $colonyId)
            ->where('resource_type', $recurso)
            ->increment('amount', $qtd);
    }

    private function lancar(int $colonyId, string $tipo, int $valor, ?string $recurso, string $ref): void
    {
        Ledger::create([
            'colony_id' => $colonyId,
            'type' => $tipo,
            'amount' => $valor,
            'resource_type' => $recurso,
            'ref' => $ref,
            'created_at' => now(),
        ]);
    }
}
