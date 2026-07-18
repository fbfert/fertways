<?php

namespace App\Domain\Federacao;

use App\Domain\Treasury\Tesouro;
use App\Models\Colony;
use App\Models\Federation;
use App\Models\FederationHolding;
use App\Models\FederationLedger;
use Illuminate\Support\Facades\DB;

/**
 * Dissolve uma federação (docs/decisoes.md D-114) — chamada quando o último membro sai
 * (`SairDaFederacao`, onde ele já se desligou antes de chamar aqui) ou pelo admin em emergência
 * (onde a federação pode ainda ter vários membros ativos, ao contrário do caminho normal). Por
 * isso este método sempre limpa `federation_id`/`federation_role` de QUALQUER colônia que ainda
 * aponte para ela — idempotente mesmo quando já não sobra ninguém.
 *
 * ⚠️ Julgamento do desenvolvedor, não decisão do GDD: o saldo remanescente do fundo vai para o
 * Tesouro, não para os membros. Evita o exploit óbvio (expulsar todo mundo e sair por último para
 * embolsar o fundo sozinho) e segue a convenção do resto do jogo — valor não reclamado sempre cai
 * no Tesouro (tributo de transporte, taxa de mercado, hora do Pátio).
 */
class DissolverFederacao
{
    public function __construct(private readonly Tesouro $tesouro) {}

    public function handle(Federation $federation): void
    {
        DB::transaction(function () use ($federation) {
            $federation = Federation::whereKey($federation->id)->lockForUpdate()->firstOrFail();

            if ($federation->dissolvida()) {
                return;
            }

            $saldos = FederationHolding::where('federation_id', $federation->id)
                ->where('amount', '>', 0)
                ->lockForUpdate()
                ->get();

            foreach ($saldos as $saldo) {
                $ref = "federacao_dissolvida:{$federation->id}:{$saldo->resource_type}";

                $this->tesouro->creditarRecurso($saldo->resource_type, $saldo->amount, $ref);

                FederationLedger::create([
                    'federation_id' => $federation->id,
                    'colony_id' => null,
                    'type' => 'dissolucao',
                    'amount' => -$saldo->amount,
                    'resource_type' => $saldo->resource_type,
                    'ref' => $ref,
                    'created_at' => now(),
                ]);

                $saldo->update(['amount' => 0]);
            }

            // Caminho normal: ninguém mais aponta pra cá (o último saiu antes de chamar). Dissolução
            // de emergência pelo admin: pode sobrar gente — todo mundo é desligado aqui.
            Colony::where('federation_id', $federation->id)
                ->update(['federation_id' => null, 'federation_role' => null]);

            $federation->forceFill(['disbanded_at' => now()])->save();
        });
    }
}
