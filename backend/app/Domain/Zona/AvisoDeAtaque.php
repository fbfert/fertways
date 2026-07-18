<?php

namespace App\Domain\Zona;

use App\Domain\Chat\ContaSistema;
use App\Domain\Chat\EnviarMensagem;
use App\Models\Colony;
use App\Models\NeutralZone;

/**
 * A Central de Comunicação avisa a federação inteira quando a zona de um membro entra em cerco
 * (§16/§06; D-116, Fatia 3) — mesmo desenho do aviso do Pátio (D-91), só que disparado uma vez, no
 * instante em que o cerco começa a morder (`ResolverCombates::chegar()`, junto com `sieged_at`).
 *
 * Exige Central nível 1 (`communication_level >= 1`) e que a zona tenha dono federado — sem
 * qualquer um dos dois, não há para quem avisar.
 */
class AvisoDeAtaque
{
    public function __construct(private readonly EnviarMensagem $chat) {}

    public function avisar(NeutralZone $zona): void
    {
        if ($zona->communication_level < 1 || $zona->owner_colony_id === null) {
            return;
        }

        $dona = $zona->owner;

        if (! $dona || $dona->federation_id === null) {
            return;
        }

        $corpo = "A zona \"{$zona->name}\" de {$dona->name}, da sua federação, está sob cerco.";

        foreach (Colony::where('federation_id', $dona->federation_id)->get() as $membro) {
            if ($usuario = $membro->user) {
                $this->chat->sistema(ContaSistema::federacao(), $usuario, $corpo);
            }
        }
    }
}
