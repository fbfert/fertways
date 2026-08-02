<?php

namespace App\Domain\Endurance;

use App\Domain\Marco\ExigirMarco;
use App\Domain\Missoes\Progresso;
use App\Domain\Treasury\Tesouro;
use App\Exceptions\DomainRuleException;
use App\Models\Colony;
use App\Models\ColonyEnduranceItem;
use App\Models\EnduranceItem;
use App\Models\Ledger;
use Illuminate\Support\Facades\DB;

/**
 * Compra um item da Loja de Peças da Endurance (D-135). Substitui `ComprarPeca` (D-132) — mesmo
 * molde de `ComprarNiobio`/`ComprarVeiculo`: lock, checa Fert$, debita, credita o Tesouro, lança
 * ledger. Duas diferenças do antecessor: o marco é OPCIONAL (nem todo item exige), e o estoque é
 * GLOBAL (`quantidade_total`, não "já tem ou não tem") — uma colônia pode comprar mais de uma
 * unidade do mesmo item, e os efeitos empilham por unidade (`EfeitosDaEndurance`).
 */
class ComprarItem
{
    public function __construct(private Tesouro $tesouro) {}

    public function handle(Colony $colony, string $itemKey): ColonyEnduranceItem
    {
        $item = EnduranceItem::where('item_key', $itemKey)->first();

        if (! $item) {
            throw new DomainRuleException('item_desconhecido', "Item inexistente: {$itemKey}");
        }

        if ($item->marco_minimo !== null) {
            app(ExigirMarco::class)->exigir($colony, $item->marco_minimo, "O item \"{$item->nome}\"");
        }

        return DB::transaction(function () use ($colony, $itemKey) {
            $item = EnduranceItem::where('item_key', $itemKey)->lockForUpdate()->first();
            $colonia = Colony::whereKey($colony->id)->lockForUpdate()->firstOrFail();

            if ($item->estoqueLivre() <= 0) {
                throw new DomainRuleException('item_esgotado', 'Este item está esgotado.');
            }

            $preco = $item->preco_micro;

            if ($colonia->fert_micro < $preco) {
                throw new DomainRuleException(
                    'fert_insuficiente',
                    'Faltam '.number_format(($preco - $colonia->fert_micro) / 1_000_000, 2, ',', '.').' Fert$.',
                );
            }

            $colonia->decrement('fert_micro', $preco);
            $this->tesouro->creditarFert($preco, "compra_item_endurance:{$itemKey}");
            $item->increment('quantidade_vendida');

            Ledger::create([
                'colony_id' => $colonia->id,
                'type' => 'compra_item_endurance',
                'amount' => -$preco,
                'resource_type' => null,
                'ref' => "endurance:{$itemKey}",
                'created_at' => now(),
            ]);

            /*
             * ⚠️ A2.9: item ÚNICO ganha INSTÂNCIA, e não quantidade.
             *
             * Comprar o último exemplar de algo que só existe uma vez é uma **descoberta**: quem o
             * tirou dos destroços fica gravado para sempre (§11.1). Comum e raro continuam fungíveis,
             * porque ninguém quer a biografia do parafuso comum de número 4.312.
             *
             * A linha de posse é criada dos dois jeitos: as telas, os efeitos e o leilão já leem
             * `colony_endurance_items`, e fazer o único não aparecer lá o tornaria invisível no jogo
             * inteiro para ganhar uma história que ninguém veria.
             */
            if ($item->tipo === EnduranceItem::UNICO) {
                app(Instancias::class)->descobrir($colonia, $item);
            }

            $posse = ColonyEnduranceItem::firstOrNew([
                'colony_id' => $colonia->id,
                'endurance_item_id' => $item->id,
            ]);
            $posse->quantidade = ($posse->exists ? $posse->quantidade : 0) + 1;
            $posse->save();

            app(Progresso::class)->registrar($colonia->id, 'comprar_item_endurance');

            return $posse;
        });
    }
}
