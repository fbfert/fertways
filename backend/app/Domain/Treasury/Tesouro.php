<?php

namespace App\Domain\Treasury;

use App\Exceptions\DomainRuleException;
use App\Models\Colony;
use App\Models\Ledger;
use App\Models\TreasuryHolding;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Ministério do Tesouro de Fertways (D-57): o caixa do governo.
 *
 * Um caixa real e mutável. Entra: o tributo cobrado no comércio (recursos na entrega, Fert$ na venda
 * de mercado) — é o "coleta e redistribuição" do §2.1, e unifica o Tesouro do D-55. Sai: a
 * distribuição do admin a um colono. Os colonos veem o saldo na Capital; só o admin move.
 *
 * Fert$ mora na chave sentinela FERT (amount em micro-Fert$); os recursos, no próprio code (unidades).
 */
class Tesouro
{
    /** Chave da linha de Fert$ (não é um recurso do catálogo). */
    public const FERT = '__fert__';

    /** Todo o saldo, recursos e Fert$. */
    public function saldos(): Collection
    {
        return TreasuryHolding::orderBy('resource_type')->get();
    }

    public function saldoFertMicro(): int
    {
        return (int) (TreasuryHolding::whereKey(self::FERT)->value('amount') ?? 0);
    }

    /** Tributo de transporte: recurso retido na entrega entra no Tesouro (§8.3, §2.1). */
    public function creditarRecurso(string $recurso, int $qtd): void
    {
        if ($qtd > 0) {
            $this->ajustar($recurso, $qtd);
        }
    }

    /** Tributo de mercado: Fert$ retido na venda entra no Tesouro. */
    public function creditarFert(int $micro): void
    {
        if ($micro > 0) {
            $this->ajustar(self::FERT, $micro);
        }
    }

    /**
     * O admin envia parte do Tesouro a um colono. `$recurso` é um code do catálogo, ou FERT para Fert$.
     * A quantidade é em unidades do recurso, ou em micro-Fert$ para FERT.
     */
    public function distribuir(Colony $destino, string $recurso, int $qtd): void
    {
        if ($qtd <= 0) {
            throw new DomainRuleException('quantidade_invalida', 'A quantidade tem de ser positiva.');
        }

        DB::transaction(function () use ($destino, $recurso, $qtd) {
            // `where amount >= qtd` no UPDATE: nunca distribui além do saldo, mesmo em corrida.
            $baixou = TreasuryHolding::whereKey($recurso)->where('amount', '>=', $qtd)->decrement('amount', $qtd);

            if ($baixou === 0) {
                throw new DomainRuleException('tesouro_insuficiente', 'O Tesouro não tem esse saldo.');
            }

            $ref = "tesouro:dist:{$destino->id}";

            if ($recurso === self::FERT) {
                DB::table('colonies')->where('id', $destino->id)->increment('fert_micro', $qtd);
                $this->lancar($destino->id, $qtd, null, $ref);
            } else {
                $destino->resources()->where('resource_type', $recurso)->increment('amount', $qtd);
                $this->lancar($destino->id, $qtd, $recurso, $ref);
            }
        });
    }

    /**
     * O governo gasta o próprio caixa (D-60): o Ministério dos Transportes consome recursos do
     * Tesouro para fabricar um caminhão.
     *
     * Diferente de `distribuir`, aqui não há colono de destino e não há lançamento no ledger de
     * ninguém — o recurso não vai para uma colônia, vira máquina. Quem registra o fato é o veículo
     * que nasce, com a sua placa.
     *
     * @param  array<string,int>  $custo  recurso => quantidade
     * @return bool false se o Tesouro não tinha tudo — e então **nada** foi debitado
     */
    public function gastar(array $custo): bool
    {
        try {
            DB::transaction(function () use ($custo) {
                foreach ($custo as $recurso => $qtd) {
                    if ($qtd <= 0) {
                        continue;
                    }

                    // `where amount >= qtd` no UPDATE: o saldo nunca fica negativo, nem em corrida.
                    $baixou = TreasuryHolding::whereKey($recurso)->where('amount', '>=', $qtd)->decrement('amount', $qtd);

                    if ($baixou === 0) {
                        // O throw é o rollback: um caminhão não sai pela metade. Ou o Tesouro tinha
                        // os três recursos, ou não gastou nenhum.
                        throw new TesouroSemSaldo;
                    }
                }
            });

            return true;
        } catch (TesouroSemSaldo) {
            return false;
        }
    }

    /** Tem saldo para este custo? Leitura pura — quem decide de verdade é o `gastar`, sob lock. */
    public function comporta(array $custo): bool
    {
        foreach ($custo as $recurso => $qtd) {
            if ((int) (TreasuryHolding::whereKey($recurso)->value('amount') ?? 0) < $qtd) {
                return false;
            }
        }

        return true;
    }

    /**
     * Reserva `$qtd` do Tesouro (D-87: o escrow de uma oferta do Governo no Mercado Central).
     * `false` sem debitar nada se não houver saldo — mesma guarda de `gastar`/`distribuir`.
     */
    public function debitar(string $recurso, int $qtd): bool
    {
        if ($qtd <= 0) {
            return true;
        }

        return TreasuryHolding::whereKey($recurso)->where('amount', '>=', $qtd)->decrement('amount', $qtd) > 0;
    }

    /** Devolve `$qtd` ao Tesouro — o lado inverso de `debitar`, sem guarda (somar nunca falha). */
    public function creditar(string $recurso, int $qtd): void
    {
        if ($qtd > 0) {
            $this->ajustar($recurso, $qtd);
        }
    }

    /** Cria a linha se faltar e soma o delta. */
    private function ajustar(string $chave, int $delta): void
    {
        $holding = TreasuryHolding::firstOrCreate(['resource_type' => $chave], ['amount' => 0]);
        $holding->increment('amount', $delta);
    }

    private function lancar(int $colonyId, int $valor, ?string $recurso, string $ref): void
    {
        Ledger::create([
            'colony_id' => $colonyId,
            'type' => 'transferencia_tesouro',
            'amount' => $valor,
            'resource_type' => $recurso,
            'ref' => $ref,
            'created_at' => now(),
        ]);
    }
}

/** Aborta a transação do `gastar` sem virar erro de verdade: o caixa não tinha o saldo. */
class TesouroSemSaldo extends \RuntimeException {}
