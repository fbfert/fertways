<?php

namespace App\Domain\Market;

use App\Exceptions\DomainRuleException;
use App\Models\ResourceType;
use Illuminate\Support\Facades\DB;

/**
 * O depósito que cada colônia tem no Mercado Central — a "doca" da tela (§25.8, D-32).
 *
 * Até o D-58 ele era ilimitado. Agora tem teto por recurso, e o teto é **arbitragem do usuário
 * (D-58), não valor do GDD** — por isso mora aqui, e não em `resource_types.json`, que é gerado do
 * GDD por `tools/extract_gdd_specs.py` e cujo cabeçalho promete que "nenhum valor é digitado".
 *
 * As classes reusam o `tax_class` do §8.3, que já separa exatamente os grupos que o usuário quis:
 * "industrial" são os 4 secundários do §18.2 mais os 8 minerais do §18.3 — todos `secundario`.
 *
 * **O que ocupa espaço** (D-58): o saldo livre, o que está preso em ofertas de venda **e** o que as
 * ofertas de compra abertas ainda vão receber. Sem contar os dois últimos, bastaria anunciar tudo a
 * preço absurdo para esvaziar o depósito e trazer mais — o teto viraria decoração.
 */
class Deposito
{
    private const TETOS = [
        'primario' => 10_000,
        'secundario' => 2_500,
        'raro' => 100,
    ];

    public static function teto(string $recurso): int
    {
        $tipo = ResourceType::find($recurso);

        if (! $tipo) {
            throw new DomainRuleException('recurso_desconhecido', "Recurso inexistente: {$recurso}");
        }

        return self::TETOS[$tipo->tax_class]
            ?? throw new DomainRuleException('classe_sem_teto', "Sem teto para a classe {$tipo->tax_class}.");
    }

    /** Saldo livre + escrow das vendas anunciadas + o que as compras abertas vão receber. */
    public static function ocupado(int $colonyId, string $recurso): int
    {
        $saldo = (int) DB::table('market_accounts')
            ->where('colony_id', $colonyId)
            ->where('resource_type', $recurso)
            ->value('amount');

        $emOfertas = (int) DB::table('market_orders')
            ->where('colony_id', $colonyId)
            ->where('resource_type', $recurso)
            ->whereIn('status', ['aberta', 'parcial'])
            // Na venda o que ocupa é o lote reservado; na compra, o que vai chegar.
            ->selectRaw('COALESCE(SUM(CASE WHEN side = ? THEN escrow_resource_qty ELSE qty END), 0) AS total', ['sell'])
            ->value('total');

        return $saldo + $emOfertas;
    }

    public static function livre(int $colonyId, string $recurso): int
    {
        return max(0, self::teto($recurso) - self::ocupado($colonyId, $recurso));
    }

    /** Recusa quando não cabe, dizendo quanto cabe — o colono não deve descobrir o teto por tentativa. */
    public static function exigirEspaco(int $colonyId, string $recurso, int $qtd): void
    {
        $livre = self::livre($colonyId, $recurso);

        if ($qtd > $livre) {
            throw new DomainRuleException(
                'deposito_sem_espaco',
                "Seu depósito de {$recurso} na Capital comporta {$livre} unidade(s) a mais (teto de ".self::teto($recurso).').',
            );
        }
    }

    /**
     * Quanto do bruto `$qtd` pode ser entregue de modo que o **líquido** caiba em `$livre`.
     *
     * O tributo é retido em unidades do próprio recurso (D-12), então quem cabe no depósito é o
     * líquido, e é o bruto que decide o tributo. Inverter `liquido = bruto - trunc(bruto*bps/10000)`
     * na mão erraria por um; então estima-se e corrige-se, que é exato e custa duas iterações.
     */
    public static function brutoQueCabe(int $qtd, int $bps, int $livre): int
    {
        if ($livre <= 0) {
            return 0;
        }

        $liquidoDe = fn (int $bruto) => $bruto - intdiv($bruto * $bps, 10_000);

        if ($liquidoDe($qtd) <= $livre) {
            return $qtd;
        }

        $bruto = min($qtd, intdiv($livre * 10_000, 10_000 - $bps) + 1);

        while ($bruto > 0 && $liquidoDe($bruto) > $livre) {
            $bruto--;
        }

        while ($bruto < $qtd && $liquidoDe($bruto + 1) <= $livre) {
            $bruto++;
        }

        return $bruto;
    }
}
