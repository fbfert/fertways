<?php

namespace App\Domain\Market;

use App\Domain\Trade\AcessoAoMercado;
use App\Exceptions\DomainRuleException;
use App\Models\Colony;
use App\Models\Ledger;
use App\Models\MarketAccount;
use App\Models\MarketOrder;
use App\Models\PriceIntervention;
use App\Models\ResourceType;
use Illuminate\Support\Facades\DB;

/**
 * Livro de ofertas do Mercado Central (GDD §07, "Mercado Central — canal oficial").
 *
 * O §06 diz que o preço-base "é faixa de segurança, **não preço obrigatório de compra e venda**",
 * e que "jogadores podem negociar dentro da faixa". Logo o Mercado não compra nem vende: ele só
 * hospeda as ofertas dos colonos. O preço-base fica como referência exibida — teto e piso ficam
 * para a Secretaria (§06), e o MVP não os impõe. Ver docs/decisoes.md D-35.
 *
 * Esta classe **abre** a oferta e prende o escrow. Quem a fecha é `ExecutarOrdem`, num ato
 * explícito de quem clica. Até o D-58 havia casamento automático aqui dentro, e era ele que fazia
 * a vitrine parecer deserta: a oferta que cruzava era consumida antes de qualquer um a ver.
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

        // §06: enquanto a Secretaria de Finanças mantém uma intervenção vigente, o preço fica preso
        // à faixa declarada. Fora dela, a ordem é recusada. Sem intervenção, o mercado é livre (D-35).
        $this->exigirFaixa($recurso, $precoMicro);

        return DB::transaction(function () use ($colonia, $lado, $recurso, $qtd, $precoMicro) {
            // D-58: a oferta **repousa**. Não há mais casamento automático — quem quiser fechar
            // clica nela (`ExecutarOrdem`). Foi o preço que o usuário aceitou pagar para que as
            // ofertas fossem visíveis: antes, uma oferta que cruzava era consumida no ato e
            // ninguém jamais a via.
            return $this->abrirComEscrow($colonia, $lado, $recurso, $qtd, $precoMicro);
        });
    }

    /** Recusa a ordem se houver intervenção vigente e o preço cair fora do teto/piso (§06). */
    private function exigirFaixa(string $recurso, int $precoMicro): void
    {
        $intervencao = PriceIntervention::vigenteDe($recurso);

        if (! $intervencao) {
            return;
        }

        if ($intervencao->floor_micro !== null && $precoMicro < $intervencao->floor_micro) {
            throw new DomainRuleException(
                'preco_fora_da_faixa',
                'A Secretaria de Finanças fixou piso de '.$this->fert($intervencao->floor_micro).' Fert$ para este recurso.',
            );
        }

        if ($intervencao->ceil_micro !== null && $precoMicro > $intervencao->ceil_micro) {
            throw new DomainRuleException(
                'preco_fora_da_faixa',
                'A Secretaria de Finanças fixou teto de '.$this->fert($intervencao->ceil_micro).' Fert$ para este recurso.',
            );
        }
    }

    private function fert(int $micro): string
    {
        return number_format($micro / Colony::MICRO_POR_FERT, 4, ',', '.');
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

        // D-58: a compra vai **receber** mercadoria no depósito. Se não reservasse o espaço agora, a
        // execução falharia na cara do vendedor por culpa do comprador. O escrow de venda já ocupava
        // espaço por decisão do usuário; a simetria é consequência dela.
        Deposito::exigirEspaco($colonia->id, $recurso, $qtd);

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
