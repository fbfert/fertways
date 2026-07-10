<?php

namespace App\Domain\Ministry;

use App\Models\Ledger;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * §26.7: "Salário fixo diário — 50 Fert$, **independente do volume de casos**".
 *
 * O §9.3 diz "remunerado em Fert$ **por caso resolvido**", e os dois não podem valer ao mesmo tempo.
 * Pelo D-47 vence o §26.7: mesmo bloco do documento, número maior. E é a leitura que o próprio
 * §26.7 defende no título — "Remuneração por Qualidade, não Volume". Pagar por caso resolvido
 * premiaria o conciliador que despacha depressa, que é o incentivo que o §26.1 lista como abuso.
 *
 * O dinheiro é **emitido pelo Governo** (D-50), pelo mesmo caminho do subsídio das construções
 * essenciais do §24.7. Fica no ledger como emissão, então a inflação que o cargo cria é auditável —
 * o §06 exige "integridade monetária" e um ledger que a comprove.
 *
 * Conciliador suspenso não recebe: o §26.7 suspende "do cargo", e salário é do cargo.
 */
class PagarConciliadores
{
    /** @return int quantos conciliadores foram pagos */
    public function handle(): int
    {
        $agora = now();
        $pagos = 0;

        $elegiveis = User::whereNotNull('conciliador_desde')
            ->whereNull('conciliador_suspenso_em')
            ->where(fn ($q) => $q->whereNull('salario_pago_em')->orWhere('salario_pago_em', '<=', $agora->copy()->subDay()))
            ->orderBy('id')
            ->get();

        foreach ($elegiveis as $conciliador) {
            $pagos += $this->pagar($conciliador, $agora) ? 1 : 0;
        }

        return $pagos;
    }

    private function pagar(User $conciliador, $agora): bool
    {
        $colonia = $conciliador->colony;

        // Um conciliador sem colônia não tem onde receber. Não é erro: o cargo é do colono, e a
        // colônia pode ter sido apagada. Ele volta a receber quando fundar outra.
        if (! $colonia) {
            return false;
        }

        return DB::transaction(function () use ($conciliador, $colonia, $agora) {
            /*
             * A guarda contra o cron sobreposto: dois ticks no mesmo segundo não pagam dois
             * salários. Quem perder a corrida vê `salario_pago_em` já adiantado e sai.
             */
            $ganhou = User::whereKey($conciliador->id)
                ->where(fn ($q) => $q->whereNull('salario_pago_em')->orWhere('salario_pago_em', '<=', $agora->copy()->subDay()))
                ->update(['salario_pago_em' => $agora]);

            if ($ganhou === 0) {
                return false;
            }

            DB::table('colonies')->where('id', $colonia->id)->increment('fert_micro', PunicaoSpecs::SALARIO_DIARIO_MICRO);

            Ledger::create([
                'colony_id' => $colonia->id,
                'type' => 'salario_conciliador',
                'amount' => PunicaoSpecs::SALARIO_DIARIO_MICRO,
                'resource_type' => null,
                'ref' => 'ministerio:salario:'.$agora->toDateString(),
                'created_at' => $agora,
            ]);

            return true;
        });
    }
}
