<?php

namespace App\Domain\Endurance;

use App\Domain\Marco\ExigirMarco;
use App\Domain\Treasury\Tesouro;
use App\Exceptions\DomainRuleException;
use App\Models\Colony;
use App\Models\ColonyEndurancePiece;
use App\Models\Ledger;
use Illuminate\Support\Facades\DB;

/**
 * Compra uma peça da Loja da Endurance (D-132). Mesmo molde do `ComprarNiobio`/`ComprarVeiculo`:
 * lock da colônia, checa Fert$, debita, credita o Tesouro, grava o ledger.
 *
 * O gate é o Marco que o §05 já publica (`ExigirMarco`, o mesmo usado por Drone e zona neutra) —
 * "os demais desbloqueios do §05 ganham gate no dia em que os sistemas existirem" já estava escrito
 * lá antes deste dia chegar.
 *
 * Peça `unica`: só uma colônia no servidor pode possuí-la — checado sob o mesmo lock, não por
 * constraint de banco (a chave já é única por natureza da tabela; a checagem "outra colônia já
 * tem" é lógica de domínio).
 */
class ComprarPeca
{
    public function __construct(private Tesouro $tesouro) {}

    public function handle(Colony $colony, string $pecaKey): ColonyEndurancePiece
    {
        if (! EnduranceSpecs::existe($pecaKey)) {
            throw new DomainRuleException('peca_desconhecida', "Peça inexistente: {$pecaKey}");
        }

        $peca = EnduranceSpecs::peca($pecaKey);

        app(ExigirMarco::class)->exigir($colony, $peca['marco_minimo'], "A peça \"{$peca['nome']}\"");

        return DB::transaction(function () use ($colony, $pecaKey, $peca) {
            $colony = Colony::whereKey($colony->id)->lockForUpdate()->firstOrFail();

            if (ColonyEndurancePiece::where('colony_id', $colony->id)->where('peca_key', $pecaKey)->exists()) {
                throw new DomainRuleException('peca_ja_possuida', 'Você já tem esta peça.');
            }

            if ($peca['unica'] && ColonyEndurancePiece::where('peca_key', $pecaKey)->lockForUpdate()->exists()) {
                throw new DomainRuleException(
                    'peca_esgotada',
                    'Esta peça é única, e outra colônia já a arrematou.',
                );
            }

            $preco = $peca['preco_micro'];

            if ($colony->fert_micro < $preco) {
                throw new DomainRuleException(
                    'fert_insuficiente',
                    'Faltam '.number_format(($preco - $colony->fert_micro) / 1_000_000, 2, ',', '.').' Fert$.',
                );
            }

            $colony->decrement('fert_micro', $preco);
            $this->tesouro->creditarFert($preco, "compra_peca_endurance:{$pecaKey}");

            Ledger::create([
                'colony_id' => $colony->id,
                'type' => 'compra_peca_endurance',
                'amount' => -$preco,
                'resource_type' => null,
                'ref' => "endurance:{$pecaKey}",
                'created_at' => now(),
            ]);

            return ColonyEndurancePiece::create([
                'colony_id' => $colony->id,
                'peca_key' => $pecaKey,
                'comprado_em' => now(),
            ]);
        });
    }
}
