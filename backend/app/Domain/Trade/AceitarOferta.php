<?php

namespace App\Domain\Trade;

use App\Domain\Logistics\MapaFertways;
use App\Exceptions\DomainRuleException;
use App\Models\Colony;
use App\Models\TradeAgreement;
use Illuminate\Support\Facades\DB;

/**
 * Aceita uma **oferta aberta** do mural entre colonos (D-58).
 *
 * A oferta aberta é um Acordo sem contraparte (`colony_b_id` nulo). Quem aceita primeiro leva: a
 * colônia entra na coluna vazia, herda o lado `0` dos termos, e o acordo vira `aceito` — daí em
 * diante é um Acordo de Troca comum, com prazo, entrega física e calote possível (D-40).
 *
 * **O D-42 é cobrado aqui, e não na proposta.** Ao anunciar não havia contraparte, logo não havia
 * distância: exigiu-se só o piso teórico. Agora existe um par de verdade, e o prazo tem de caber na
 * viagem entre eles. Quem mora longe demais para o prazo anunciado não aceita — e é melhor assim
 * do que aceitar um calote que já nasce fabricado.
 */
class AceitarOferta
{
    public function handle(Colony $quemAceita, TradeAgreement $oferta): TradeAgreement
    {
        if ($oferta->colony_b_id !== null) {
            throw new DomainRuleException('nao_e_oferta_aberta', 'Esta oferta já tem contraparte.');
        }

        if ($oferta->status !== 'proposto') {
            throw new DomainRuleException('oferta_indisponivel', "A oferta está {$oferta->status}.");
        }

        if ($oferta->colony_a_id === $quemAceita->id) {
            throw new DomainRuleException('oferta_propria', 'Você não pode aceitar a sua própria oferta.');
        }

        if ($oferta->deadline_at->isPast()) {
            throw new DomainRuleException('prazo_ja_vencido', 'O prazo desta oferta já venceu.');
        }

        $proponente = Colony::findOrFail($oferta->colony_a_id);
        $this->exigirPrazoViavel($proponente, $quemAceita, $oferta);

        return DB::transaction(function () use ($oferta, $quemAceita) {
            // Releitura com lock: numa oferta aberta a corrida é real — dois colonos podem clicar
            // "aceitar" no mesmo instante, e só um pode levar.
            $fresca = TradeAgreement::whereKey($oferta->id)->lockForUpdate()->first();

            if (! $fresca || $fresca->colony_b_id !== null || $fresca->status !== 'proposto') {
                throw new DomainRuleException('oferta_ja_tomada', 'Outro colono aceitou esta oferta primeiro.');
            }

            $fresca->forceFill([
                'colony_b_id' => $quemAceita->id,
                'terms_json' => $this->comDono($fresca->terms_json, $quemAceita->id),
                'delivered_json' => $this->comDono($fresca->delivered_json ?? [], $quemAceita->id),
                'status' => 'aceito',
                'accepted_at' => now(),
            ])->save();

            return $fresca;
        });
    }

    /** D-42, agora com um par de verdade: o prazo tem de caber na viagem entre as duas colônias. */
    private function exigirPrazoViavel(Colony $proponente, Colony $quemAceita, TradeAgreement $oferta): void
    {
        $distancia = MapaFertways::distancia($proponente->x, $proponente->y, $quemAceita->x, $quemAceita->y);
        $minimo = now()->addSeconds(AcordoSpecs::prazoMinimoSegundos($distancia));

        if ($oferta->deadline_at->lessThan($minimo)) {
            throw new DomainRuleException(
                'prazo_curto_demais',
                'Sua colônia está longe demais para cumprir este prazo. Ele exigiria entrega até '
                .$minimo->toDateTimeString().'.',
            );
        }
    }

    /**
     * Troca a chave `0` (o lado sem dono) pelo id de quem aceitou.
     *
     * @param  array<string,mixed>  $lados
     * @return array<string,mixed>
     */
    private function comDono(array $lados, int $colonyId): array
    {
        $aberto = (string) ProporAcordo::LADO_ABERTO;

        if (! array_key_exists($aberto, $lados)) {
            return $lados;
        }

        $lados[(string) $colonyId] = $lados[$aberto];
        unset($lados[$aberto]);

        return $lados;
    }
}
