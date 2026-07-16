<?php

namespace App\Domain\Market;

use App\Domain\Treasury\Tesouro;
use App\Exceptions\DomainRuleException;
use App\Models\MarketOrder;
use App\Models\TreasuryHolding;
use Illuminate\Support\Facades\DB;

/**
 * O Governo vende no Mercado Central (docs/decisoes.md D-87).
 *
 * `colony_id` nulo é o Governo — o mesmo padrão da frota pública (`vehicles.colony_id`, D-60). A
 * oferta é uma linha de verdade em `market_orders`, na mesma vitrine que as dos colonos: quando
 * alguém compra, `ExecutarOrdem` desconta dela como desconta de qualquer venda.
 *
 * **`definir()` não é "publicar uma oferta nova" — é "isto é o que deve estar à venda agora".**
 * O painel do admin lista os 26 recursos de uma vez; salvar reconcilia cada linha com o que já
 * está na vitrine, em vez de empilhar ordens (decisão do usuário): o número digitado é quanto
 * deve estar disponível NESTE INSTANTE, não quanto somar. Subir o número reserva mais do Tesouro;
 * descer devolve a diferença; zerar cancela a oferta.
 */
class OfertarComoGoverno
{
    public function __construct(private Tesouro $tesouro) {}

    /**
     * @param  int  $qtdAlvo  quanto deve estar à venda agora (0 cancela a oferta deste recurso)
     * @param  int  $precoMicro  preço por unidade, em micro-Fert$ — ignorado quando `$qtdAlvo` é 0
     */
    public function definir(string $recurso, int $qtdAlvo, int $precoMicro): ?MarketOrder
    {
        if ($qtdAlvo < 0) {
            throw new DomainRuleException('quantidade_invalida', 'A quantidade não pode ser negativa.');
        }

        if ($qtdAlvo > 0 && $precoMicro <= 0) {
            throw new DomainRuleException('preco_invalido', 'O preço tem de ser positivo.');
        }

        return DB::transaction(function () use ($recurso, $qtdAlvo, $precoMicro) {
            $ordem = MarketOrder::whereNull('colony_id')
                ->where('resource_type', $recurso)
                ->where('side', 'sell')
                ->whereIn('status', ['aberta', 'parcial'])
                ->lockForUpdate()
                ->first();

            $atual = $ordem->qty ?? 0;
            $delta = $qtdAlvo - $atual;

            if ($delta > 0) {
                if (! $this->tesouro->debitar($recurso, $delta, "oferta_governo:{$recurso}")) {
                    $saldo = (int) (TreasuryHolding::whereKey($recurso)->value('amount') ?? 0);

                    throw new DomainRuleException(
                        'tesouro_insuficiente',
                        "O Tesouro tem só {$saldo} de {$recurso} — não dá para anunciar {$qtdAlvo}.",
                    );
                }
            } elseif ($delta < 0) {
                $this->tesouro->creditar($recurso, -$delta, "oferta_governo:{$recurso}");
            }

            if ($qtdAlvo === 0) {
                $ordem?->forceFill(['status' => 'cancelada', 'qty' => 0, 'escrow_resource_qty' => 0])->save();

                return null;
            }

            if ($ordem) {
                $ordem->update(['qty' => $qtdAlvo, 'escrow_resource_qty' => $qtdAlvo, 'price_micro' => $precoMicro]);

                return $ordem->fresh();
            }

            return MarketOrder::create([
                'colony_id' => null,
                'resource_type' => $recurso,
                'side' => 'sell',
                'price_micro' => $precoMicro,
                'qty' => $qtdAlvo,
                'escrow_resource_qty' => $qtdAlvo,
                'status' => 'aberta',
            ]);
        });
    }

    /** As ofertas do Governo hoje, por recurso — para o painel pré-preencher o formulário. */
    public function ofertas(): array
    {
        return MarketOrder::whereNull('colony_id')
            ->where('side', 'sell')
            ->whereIn('status', ['aberta', 'parcial'])
            ->get()
            ->keyBy('resource_type')
            ->all();
    }
}
