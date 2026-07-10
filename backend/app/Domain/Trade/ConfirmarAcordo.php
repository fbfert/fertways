<?php

namespace App\Domain\Trade;

use App\Exceptions\DomainRuleException;
use App\Models\Colony;
use App\Models\TradeAgreement;
use Illuminate\Support\Facades\DB;

/**
 * O "aperto de mão digital" do GDD §26.5.
 *
 * "O acordo só vira evidência válida após **ambos** os colonos confirmarem" — e "uma proposta
 * registrada mas não confirmada pelo outro lado não tem valor de evidência completa".
 *
 * Quem propõe já aderiu ao propor. Falta a contraparte, e é só ela que pode confirmar: um
 * proponente que confirmasse sozinho fabricaria evidência contra alguém que nunca concordou.
 */
class ConfirmarAcordo
{
    public function handle(Colony $quemConfirma, TradeAgreement $acordo): TradeAgreement
    {
        if (! $acordo->envolve($quemConfirma->id)) {
            throw new DomainRuleException('acordo_de_outros', 'Este acordo não é seu.');
        }

        if ($acordo->proposer_colony_id === $quemConfirma->id) {
            throw new DomainRuleException(
                'proponente_nao_confirma',
                'Quem propõe já aderiu. Só a contraparte fecha o aperto de mão.',
            );
        }

        if ($acordo->status !== 'proposto') {
            throw new DomainRuleException('acordo_nao_esta_proposto', "O acordo está {$acordo->status}.");
        }

        // Confirmar um acordo já vencido criaria evidência natimorta: ele expiraria no tick
        // seguinte e alguém perderia 50 pontos por um prazo que nunca teve como cumprir.
        if ($acordo->deadline_at->isPast()) {
            throw new DomainRuleException('prazo_ja_vencido', 'O prazo deste acordo já venceu.');
        }

        return DB::transaction(function () use ($acordo) {
            // Releitura com lock: duas confirmações simultâneas não passam as duas.
            $fresco = TradeAgreement::whereKey($acordo->id)->lockForUpdate()->first();

            if (! $fresco || $fresco->status !== 'proposto') {
                throw new DomainRuleException('acordo_nao_esta_proposto', 'O acordo mudou de estado.');
            }

            $fresco->forceFill(['status' => 'aceito', 'accepted_at' => now()])->save();

            return $fresco;
        });
    }

    /**
     * Recusa ou desiste, enquanto o aperto de mão não se completou.
     *
     * Depois de aceito **não há cancelamento**: seria a saída fácil de quem se arrependeu, e
     * esvaziaria o §26.5. Quem aceitou e não entrega, calotea — e o registro é o ponto.
     */
    public function cancelar(Colony $quemCancela, TradeAgreement $acordo): TradeAgreement
    {
        if (! $acordo->envolve($quemCancela->id)) {
            throw new DomainRuleException('acordo_de_outros', 'Este acordo não é seu.');
        }

        if ($acordo->status !== 'proposto') {
            throw new DomainRuleException(
                'acordo_nao_cancelavel',
                "Só se cancela um acordo ainda não aceito. Este está {$acordo->status}.",
            );
        }

        $acordo->forceFill(['status' => 'cancelado'])->save();

        return $acordo;
    }
}
