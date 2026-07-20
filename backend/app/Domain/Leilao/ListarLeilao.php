<?php

namespace App\Domain\Leilao;

use App\Domain\Trade\AcessoAoMercado;
use App\Exceptions\DomainRuleException;
use App\Models\Auction;
use App\Models\Colony;
use App\Models\ColonyEnduranceItem;
use App\Models\EnduranceItem;
use App\Models\Ledger;
use App\Models\MarketAccount;
use App\Models\ResourceType;
use Illuminate\Support\Facades\DB;

/**
 * Anuncia um leilão (D-129 — desenho nosso, sem seção no GDD).
 *
 * O lote sai do MESMO depósito que o Mercado Central usa (`market_accounts`, §25.8): quem quer
 * leiloar já entregou a carga na doca da Capital, pela mesma exigência física do §07. Não inventa
 * um segundo depósito para um mecanismo que é, na essência, mais uma forma de vender na doca.
 */
class ListarLeilao
{
    public const DURACAO_MIN_HORAS = 1;
    public const DURACAO_MAX_HORAS = 72;

    public function handle(Colony $colonia, string $recurso, int $qtd, int $lanceMinimoMicro, int $duracaoHoras): Auction
    {
        // §26.2, mesmo texto que nomeia leilões: "reputação negativa bloqueia acesso a leilões,
        // Mercado Central e ao cargo de Fiscal de Mercado". Reaproveitamos o MESMO limiar (D-43).
        AcessoAoMercado::exigir($colonia);

        if ($qtd <= 0) {
            throw new DomainRuleException('quantidade_invalida', 'A quantidade tem de ser positiva.');
        }

        if ($lanceMinimoMicro <= 0) {
            throw new DomainRuleException('lance_minimo_invalido', 'O lance mínimo tem de ser positivo.');
        }

        if ($duracaoHoras < self::DURACAO_MIN_HORAS || $duracaoHoras > self::DURACAO_MAX_HORAS) {
            throw new DomainRuleException(
                'duracao_invalida',
                'O leilão dura entre '.self::DURACAO_MIN_HORAS.' e '.self::DURACAO_MAX_HORAS.' horas.',
            );
        }

        if (! ResourceType::whereKey($recurso)->exists()) {
            throw new DomainRuleException('recurso_desconhecido', "Recurso inexistente: {$recurso}");
        }

        return DB::transaction(function () use ($colonia, $recurso, $qtd, $lanceMinimoMicro, $duracaoHoras) {
            // Mesma trava de `ColocarOrdem` (venda): duas ações simultâneas não vendem o mesmo saldo.
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

            $leilao = Auction::create([
                'colony_id' => $colonia->id,
                'resource_type' => $recurso,
                'qty' => $qtd,
                'lance_minimo_micro' => $lanceMinimoMicro,
                'status' => 'aberto',
                'deadline_at' => now()->addHours($duracaoHoras),
            ]);

            $this->lancar($colonia->id, 'escrow_leilao', -$qtd, $recurso, "leilao:{$leilao->id}:anuncio");

            app(\App\Domain\Missoes\Progresso::class)->registrar($colonia->id, 'ordem_colocada');

            return $leilao;
        });
    }

    /**
     * A mesma máquina de `handle()`, mas o lote sai de `colony_endurance_items` (D-135, Fase 2) em
     * vez de `market_accounts` — o item precisa estar VENDÁVEL EM LEILÃO (o admin decide isso por
     * item no CRUD), e a colônia precisa possuir a quantidade anunciada de verdade.
     */
    public function handleItem(Colony $colonia, string $itemKey, int $qtd, int $lanceMinimoMicro, int $duracaoHoras): Auction
    {
        AcessoAoMercado::exigir($colonia);

        if ($qtd <= 0) {
            throw new DomainRuleException('quantidade_invalida', 'A quantidade tem de ser positiva.');
        }

        if ($lanceMinimoMicro <= 0) {
            throw new DomainRuleException('lance_minimo_invalido', 'O lance mínimo tem de ser positivo.');
        }

        if ($duracaoHoras < self::DURACAO_MIN_HORAS || $duracaoHoras > self::DURACAO_MAX_HORAS) {
            throw new DomainRuleException(
                'duracao_invalida',
                'O leilão dura entre '.self::DURACAO_MIN_HORAS.' e '.self::DURACAO_MAX_HORAS.' horas.',
            );
        }

        $item = EnduranceItem::where('item_key', $itemKey)->first();

        if (! $item) {
            throw new DomainRuleException('item_desconhecido', "Item inexistente: {$itemKey}");
        }

        if (! $item->vendavel_em_leilao) {
            throw new DomainRuleException('item_nao_vendavel', "«{$item->nome}» não pode ser vendido em Leilões.");
        }

        return DB::transaction(function () use ($colonia, $item, $qtd, $lanceMinimoMicro, $duracaoHoras) {
            $afetadas = ColonyEnduranceItem::where('colony_id', $colonia->id)
                ->where('endurance_item_id', $item->id)
                ->where('quantidade', '>=', $qtd)
                ->decrement('quantidade', $qtd);

            if ($afetadas === 0) {
                throw new DomainRuleException(
                    'posse_insuficiente',
                    "Você não tem {$qtd} unidade(s) de «{$item->nome}».",
                );
            }

            $leilao = Auction::create([
                'colony_id' => $colonia->id,
                'endurance_item_id' => $item->id,
                'qty' => $qtd,
                'lance_minimo_micro' => $lanceMinimoMicro,
                'status' => 'aberto',
                'deadline_at' => now()->addHours($duracaoHoras),
            ]);

            $this->lancar($colonia->id, 'escrow_leilao', -$qtd, null, "leilao:{$leilao->id}:anuncio");

            app(\App\Domain\Missoes\Progresso::class)->registrar($colonia->id, 'ordem_colocada');

            return $leilao;
        });
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
