<?php

namespace App\Domain\Market;

use App\Domain\Trade\AcessoAoMercado;
use App\Exceptions\DomainRuleException;
use App\Models\Colony;
use App\Models\Ledger;
use App\Models\MarketAccount;
use App\Models\MarketOrder;
use App\Models\ResourceType;
use Illuminate\Support\Facades\DB;

/**
 * Livro de ofertas do Mercado Central (GDD §07, "Mercado Central — canal oficial").
 *
 * O §06 diz que o preço-base "é faixa de segurança, **não preço obrigatório de compra e venda**",
 * e que "jogadores podem negociar dentro da faixa". Logo o Mercado não compra nem vende: ele casa
 * ordens de colonos. O preço-base fica como referência exibida — teto e piso ficam para a
 * Secretaria (§06), e o MVP não os impõe. Ver docs/decisoes.md D-35.
 *
 * O escrow é o que distingue este canal do comércio informal (§07): "Escrow de recursos e Fert$;
 * o sistema assegura entrega financeira e reserva do lote." Vender exige que o recurso **já esteja
 * na conta do Mercado** — o §07 é explícito: "O vendedor transporta o lote até a doca de mercado.
 * Ao chegar, o lote é reservado em escrow e a listagem é criada."
 */
class ColocarOrdem
{
    public function handle(Colony $colonia, string $lado, string $recurso, int $qtd, int $precoMicro): MarketOrder
    {
        // §26.2: Confiança Comercial baixa fecha o Mercado Central. Ver D-43.
        AcessoAoMercado::exigir($colonia);

        if (! in_array($lado, ['buy', 'sell'], true)) {
            throw new DomainRuleException('lado_invalido', "Lado desconhecido: {$lado}");
        }

        if ($qtd <= 0) {
            throw new DomainRuleException('quantidade_invalida', 'A quantidade tem de ser positiva.');
        }

        // Preço zero transformaria a venda em doação e fugiria da taxa de mercado.
        if ($precoMicro <= 0) {
            throw new DomainRuleException('preco_invalido', 'O preço tem de ser positivo.');
        }

        if (! ResourceType::whereKey($recurso)->exists()) {
            throw new DomainRuleException('recurso_desconhecido', "Recurso inexistente: {$recurso}");
        }

        return DB::transaction(function () use ($colonia, $lado, $recurso, $qtd, $precoMicro) {
            $ordem = $this->abrirComEscrow($colonia, $lado, $recurso, $qtd, $precoMicro);

            $this->casar($ordem);

            return $ordem->fresh();
        });
    }

    /** Reserva o que a ordem promete, antes de ela existir para os outros. */
    private function abrirComEscrow(Colony $colonia, string $lado, string $recurso, int $qtd, int $precoMicro): MarketOrder
    {
        $ref = 'ordem:'.$colonia->id.':'.now()->getTimestamp();

        if ($lado === 'sell') {
            // `where amount >= qtd` no UPDATE: duas ordens simultâneas não vendem o mesmo saldo.
            $afetadas = MarketAccount::where('colony_id', $colonia->id)
                ->where('resource_type', $recurso)
                ->where('amount', '>=', $qtd)
                ->decrement('amount', $qtd);

            if ($afetadas === 0) {
                throw new DomainRuleException(
                    'saldo_mercado_insuficiente',
                    "Sua conta no Mercado não tem {$qtd} de {$recurso}. Entregue a carga na doca primeiro.",
                );
            }

            $this->lancar($colonia->id, 'escrow_mercado', -$qtd, $recurso, $ref);

            return MarketOrder::create([
                'colony_id' => $colonia->id, 'resource_type' => $recurso, 'side' => 'sell',
                'price_micro' => $precoMicro, 'qty' => $qtd,
                'escrow_resource_qty' => $qtd, 'status' => 'aberta',
            ]);
        }

        $custo = $qtd * $precoMicro;
        $afetadas = DB::table('colonies')
            ->where('id', $colonia->id)
            ->where('fert_micro', '>=', $custo)
            ->decrement('fert_micro', $custo);

        if ($afetadas === 0) {
            throw new DomainRuleException('fert_insuficiente', 'Fert$ insuficiente para esta ordem.');
        }

        // Fert$ não é recurso do catálogo: `resource_type` fica nulo e o valor é em micro-Fert$.
        $this->lancar($colonia->id, 'escrow_mercado', -$custo, null, $ref);

        return MarketOrder::create([
            'colony_id' => $colonia->id, 'resource_type' => $recurso, 'side' => 'buy',
            'price_micro' => $precoMicro, 'qty' => $qtd,
            'escrow_fert_micro' => $custo, 'status' => 'aberta',
        ]);
    }

    /**
     * Casa a ordem nova contra o livro, por melhor preço e, no empate, por antiguidade.
     *
     * O preço de execução é o da **ordem em repouso**, não o da que chegou: quem esperou no livro
     * definiu o preço, e quem cruza aceita. É a convenção de qualquer livro de ofertas, e o GDD
     * não diz outra coisa.
     */
    private function casar(MarketOrder $nova): void
    {
        $ehCompra = $nova->side === 'buy';

        $contrarias = MarketOrder::where('resource_type', $nova->resource_type)
            ->where('side', $ehCompra ? 'sell' : 'buy')
            ->whereIn('status', ['aberta', 'parcial'])
            // §26.4 trata conta-alternativa como fraude; casar consigo mesmo seria a versão
            // trivial disso, e só serviria para simular volume. O escrow voltaria ao dono.
            ->where('colony_id', '!=', $nova->colony_id)
            ->when($ehCompra, fn ($q) => $q->where('price_micro', '<=', $nova->price_micro))
            ->when(! $ehCompra, fn ($q) => $q->where('price_micro', '>=', $nova->price_micro))
            ->orderBy('price_micro', $ehCompra ? 'asc' : 'desc')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($contrarias as $contraria) {
            if ($nova->qty <= 0) {
                break;
            }

            $qtdExec = min($nova->qty, $contraria->qty);
            $precoExec = $contraria->price_micro;

            $venda = $ehCompra ? $contraria : $nova;
            $compra = $ehCompra ? $nova : $contraria;

            $this->executar($venda, $compra, $qtdExec, $precoExec);
        }
    }

    private function executar(MarketOrder $venda, MarketOrder $compra, int $qtd, int $precoExec): void
    {
        $recurso = $venda->resource_type;
        $valor = $qtd * $precoExec;
        $chave = "venda:{$venda->id}:{$compra->id}";

        /*
         * "Lote não pode ser vendido duas vezes" é critério de aceite do MVP (§16). Duas ordens
         * só se cruzam uma vez — a execução esgota pelo menos uma delas —, então o par
         * (venda, compra) identifica o negócio. O índice único de `tax_events` é a garantia.
         */
        $bps = (int) ResourceType::find($recurso)->tax_bps;
        $taxa = intdiv($valor * $bps, 10_000);

        $inserido = DB::table('tax_events')->insertOrIgnore([
            'economic_event_key' => $chave,
            'kind' => 'mercado_venda',
            'colony_id' => $venda->colony_id,
            'resource_type' => $recurso,
            // Em `mercado_venda` a base é o valor em micro-Fert$, não o volume — é o que a
            // migration de tax_events já previa.
            'base_amount' => $valor,
            'tax_bps' => $bps,
            'tax_amount' => $taxa,
            'created_at' => now(),
        ]);

        if ($inserido === 0) {
            return;
        }

        // §07: "o sistema transfere o crédito líquido ao vendedor e registra a taxa de mercado".
        // A taxa é em Fert$ e recai sobre quem vende. §25.9: o tributo de Fert$ "não se sobrepõe
        // ao tributo de recurso — incide apenas sobre a movimentação de Fert$ em si".
        $liquido = $valor - $taxa;
        DB::table('colonies')->where('id', $venda->colony_id)->increment('fert_micro', $liquido);
        $this->lancar($venda->colony_id, 'venda_mercado', $liquido, null, $chave);

        if ($taxa > 0) {
            $this->lancar($venda->colony_id, 'tributo', -$taxa, null, $chave);
        }

        /*
         * §25.8: "o recurso comprado entra na conta do colono no Mercado, e ele precisa enviar um
         * veículo próprio para retirá-lo". Não vai para o estoque: vai para a conta, e de lá sai
         * por `DespacharVeiculo::retirar()`. §07 concorda: "a carga continua na doca até a retirada".
         */
        $this->creditarConta($compra->colony_id, $recurso, $qtd);
        $this->lancar($compra->colony_id, 'compra_mercado', $qtd, $recurso, $chave);

        // O comprador escrowou ao **seu** preço. Se cruzou uma venda mais barata, a diferença
        // volta: ele nunca paga mais do que a ordem em repouso pedia.
        $devolucao = $qtd * ($compra->price_micro - $precoExec);

        if ($devolucao > 0) {
            DB::table('colonies')->where('id', $compra->colony_id)->increment('fert_micro', $devolucao);
            $this->lancar($compra->colony_id, 'estorno', $devolucao, null, $chave);
        }

        $this->baixar($venda, $qtd, $qtd, 0);
        $this->baixar($compra, $qtd, 0, $qtd * $compra->price_micro);
    }

    /** Consome quantidade e escrow da ordem, e a fecha quando não resta nada. */
    private function baixar(MarketOrder $ordem, int $qtd, int $escrowRecurso, int $escrowFert): void
    {
        $ordem->qty -= $qtd;
        $ordem->escrow_resource_qty -= $escrowRecurso;
        $ordem->escrow_fert_micro -= $escrowFert;
        $ordem->status = $ordem->qty > 0 ? 'parcial' : 'executada';
        $ordem->save();
    }

    private function creditarConta(int $colonyId, string $recurso, int $qtd): void
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
