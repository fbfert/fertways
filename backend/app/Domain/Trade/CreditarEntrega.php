<?php

namespace App\Domain\Trade;

use App\Models\TradeAgreement;
use Illuminate\Support\Facades\DB;

/**
 * Abate de um Acordo de Troca a carga que acabou de chegar. Chamado por `ConcluirTrechos`.
 *
 * D-41: **conta o líquido**, o que de fato entrou no estoque de quem recebe, já descontado o
 * tributo de transporte do §25.2. Prometer 1.000 e despachar 1.000 não cumpre — chegam menos de
 * 1.000. Quem despacha manda a mais e arca com o tributo; o acordo publica o bruto necessário.
 *
 * D-41: só abate a carga que **aponta** este acordo (`vehicles.trade_agreement_id`). Uma entrega
 * casual entre os mesmos colonos é um presente, não um pagamento.
 */
class CreditarEntrega
{
    public function __construct(private Reputacao $reputacao) {}

    public function handle(TradeAgreement $acordo, int $origemId, int $destinoId, string $recurso, int $liquido): void
    {
        if ($liquido <= 0 || ! $acordo->emVigor() || ! $acordo->envolve($origemId)) {
            return;
        }

        // A carga tem de ir para a contraparte. Despachar para um terceiro apontando o acordo não
        // paga nada a ninguém.
        if ($acordo->contraparte($origemId) !== $destinoId) {
            return;
        }

        /*
         * Entrega atrasada não cumpre. O tick fecha os trechos antes de expirar os acordos, então
         * a carga que chega exatamente no prazo ainda conta; a que chega depois encontra o acordo
         * já `quebrado` e cai no `emVigor()` acima. Este guarda cobre a corrida entre os dois.
         */
        if ($acordo->deadline_at->isPast()) {
            return;
        }

        DB::transaction(function () use ($acordo, $origemId, $recurso, $liquido) {
            $fresco = TradeAgreement::whereKey($acordo->id)->lockForUpdate()->first();

            if (! $fresco || ! $fresco->emVigor()) {
                return;
            }

            $entregue = $fresco->delivered_json ?? [];
            $chave = (string) $origemId;
            $entregue[$chave][$recurso] = ($entregue[$chave][$recurso] ?? 0) + $liquido;

            $fresco->forceFill(['delivered_json' => $entregue])->save();

            // Os dois lados honraram tudo: o acordo se executa sozinho, sem ninguém clicar nada.
            if ($fresco->cumpriu($fresco->colony_a_id) && $fresco->cumpriu($fresco->colony_b_id)) {
                $this->reputacao->fechar($fresco, 'executado', []);
            }
        });
    }
}
