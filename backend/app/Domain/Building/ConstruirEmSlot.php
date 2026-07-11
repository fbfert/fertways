<?php

namespace App\Domain\Building;

use App\Domain\Colony\Slots;
use App\Exceptions\DomainRuleException;
use App\Models\Building;
use App\Models\BuildQueue;
use App\Models\Colony;
use Illuminate\Support\Facades\DB;

/**
 * Ergue uma construção nova num slot escolhido pelo colono (D-59).
 *
 * Antes do D-59 as 16 construções já existiam na fundação, todas no nível 0, e "construir" era
 * só o primeiro upgrade de uma linha que já estava lá. Agora **construção não erguida não ocupa
 * slot**: a linha de `buildings` nasce aqui, no momento em que o colono aponta o buraco.
 *
 * O que este serviço faz é criar a linha; quem cobra o custo, confere a fila e agenda o tempo
 * continua sendo o `EnqueueUpgrade` — a construção é o upgrade do nível 0 para o 1, e não havia
 * razão para ter duas contabilidades. Se o enfileiramento falhar (fila cheia, recurso faltando),
 * a transação some com a linha e o slot volta a ficar vazio: não se deixa prédio fantasma no
 * mapa por causa de um erro de saldo.
 */
class ConstruirEmSlot
{
    public function __construct(private readonly EnqueueUpgrade $enqueue) {}

    public function handle(Colony $colony, string $tipo, int $slot): BuildQueue
    {
        if (! in_array($tipo, Building::MVP, true)) {
            throw new DomainRuleException('construcao_desconhecida', "Construção desconhecida: {$tipo}.");
        }

        // As cinco essenciais nascem no miolo com a colônia e não se erguem de novo. Barrar aqui
        // é o que garante que o subsídio do §24.7 não vire torneira: sem segunda Fazenda, não há
        // segunda Fazenda de graça.
        if (in_array($tipo, Building::ESSENCIAIS, true)) {
            throw new DomainRuleException(
                'essencial_ja_existe',
                'As cinco construções essenciais nascem com a colônia, no miolo. Não se erguem de novo.',
            );
        }

        Slots::exigirEscolhivel($slot);

        return DB::transaction(function () use ($colony, $tipo, $slot) {
            // Trava a colônia: duas requisições simultâneas não podem reclamar o mesmo slot. O
            // `unique(colony_id, slot)` é a segunda trava, no banco.
            $colony = Colony::whereKey($colony->id)->lockForUpdate()->firstOrFail();

            if ($colony->buildings()->where('slot', $slot)->exists()) {
                throw new DomainRuleException('slot_ocupado', 'Já há uma construção neste slot.');
            }

            $jaTem = $colony->buildings()->where('type', $tipo)->exists();

            if ($jaTem && ! in_array($tipo, Building::REPETIVEIS, true)) {
                throw new DomainRuleException(
                    'construcao_unica',
                    'Esta construção só pode existir uma vez na colônia.',
                );
            }

            $building = $colony->buildings()->create([
                'type' => $tipo,
                'level' => 0,
                'slot' => $slot,
            ]);

            // Nível 0 -> 1. O custo sai daqui, não da fundação: construção de progressão "nunca é
            // subsidiada — exige produção própria desde o nível 1" (§24.7), e uma cópia extra de
            // uma repetível custa o mesmo que a primeira.
            return $this->enqueue->handle($colony, $building);
        });
    }
}
