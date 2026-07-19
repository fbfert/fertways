<?php

namespace App\Domain\Federacao;

use App\Domain\Chat\ContaSistema;
use App\Domain\Chat\EnviarMensagem;
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
    public function __construct(
        private readonly Tesouro $tesouro,
        private readonly EnviarMensagem $chat,
    ) {}

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

            // Caminho normal: ninguém mais aponta pra cá (o último saiu antes de chamar) — a lista
            // abaixo vem vazia, e não há ninguém para avisar (SairDaFederacao já avisou quem
            // ficou, antes de chegar até aqui). Dissolução de emergência pelo admin: pode sobrar
            // gente ativa — é esse o caso em que o aviso realmente importa.
            $restantes = Colony::where('federation_id', $federation->id)->get();

            Colony::where('federation_id', $federation->id)
                ->update(['federation_id' => null, 'federation_role' => null]);

            $federation->forceFill(['disbanded_at' => now()])->save();

            // D-121.
            foreach ($restantes as $membro) {
                if ($usuario = $membro->user) {
                    $this->chat->sistema(
                        ContaSistema::federacao(),
                        $usuario,
                        "A federação «{$federation->name}» foi dissolvida.",
                    );
                }
            }
        });
    }
}
