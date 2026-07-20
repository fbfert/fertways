<?php

namespace App\Domain\Cargos;

use App\Models\CivicPost;
use App\Models\Ledger;
use Illuminate\Support\Facades\DB;

/**
 * Salário diário dos Cargos Públicos (§14.2, D-130) — chamado pelo tick, mesmo molde do
 * `PagarConciliadores` (§26.7, D-50), respeitando o teto semanal do §14.2/v32.
 *
 * Cargo suspenso não recebe — mesma leitura do §26.7 para o Conciliador: suspensão é do cargo, e
 * salário é do cargo.
 */
class PagarCargosCivicos
{
    /** @return int quantos pagamentos foram feitos */
    public function handle(): int
    {
        $agora = now();
        $pagos = 0;

        $elegiveis = CivicPost::whereNull('suspenso_em')
            ->where(fn ($q) => $q->whereNull('salario_pago_em')->orWhere('salario_pago_em', '<=', $agora->copy()->subDay()))
            ->orderBy('id')
            ->get();

        foreach ($elegiveis as $cargo) {
            $pagos += $this->pagar($cargo, $agora) ? 1 : 0;
        }

        return $pagos;
    }

    private function pagar(CivicPost $cargo, $agora): bool
    {
        $colono = $cargo->user;
        $colonia = $colono?->colony;

        // Sem colônia, não há onde receber — mesma leitura do Conciliador: não é erro, é pausa.
        if (! $colonia) {
            return false;
        }

        return DB::transaction(function () use ($cargo, $colono, $colonia, $agora) {
            // Guarda contra o cron sobreposto, como o Conciliador: quem perde a corrida sai.
            $ganhou = CivicPost::whereKey($cargo->id)
                ->where(fn ($q) => $q->whereNull('salario_pago_em')->orWhere('salario_pago_em', '<=', $agora->copy()->subDay()))
                ->update(['salario_pago_em' => $agora]);

            if ($ganhou === 0) {
                return false;
            }

            $livre = TetoSemanal::livre($colono->id, $cargo->kind);
            $valor = min(CargosCivicosSpecs::SALARIO_DIARIO_MICRO, $livre);

            // O teto já foi batido esta semana (por bônus, por exemplo): o cargo pausa, não erra.
            if ($valor <= 0) {
                return true;
            }

            DB::table('colonies')->where('id', $colonia->id)->increment('fert_micro', $valor);

            Ledger::create([
                'colony_id' => $colonia->id,
                'type' => 'salario_cargo_civico',
                'amount' => $valor,
                'resource_type' => null,
                'ref' => "cargo:{$cargo->kind}:{$colono->id}:salario:{$agora->toDateString()}",
                'created_at' => $agora,
            ]);

            return true;
        });
    }
}
