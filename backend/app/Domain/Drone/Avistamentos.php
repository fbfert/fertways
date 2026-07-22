<?php

namespace App\Domain\Drone;

use App\Domain\Endurance\EfeitosDaEndurance;
use App\Domain\Logistics\MapaFertways;
use App\Models\Colony;
use App\Models\DroneSighting;
use App\Models\NeutralZone;
use App\Models\Vehicle;
use Illuminate\Support\Collection;

/**
 * O que a colônia SABE de cada zona neutra — a leitura da névoa (D-74).
 *
 * Desde o D-74 o interior de zona alheia (guarnição e depósito) é oculto. Esta classe responde,
 * para uma colônia, com que olhos ela vê cada zona:
 *
 *   dona      é dela: vê ao vivo, por direito.
 *   livre     zona sem dono não tem interior a esconder (guarnição 0, depósito 0) — vê ao vivo.
 *             É o "revela zonas neutras ANTES de ocupação" do §16.1, de graça: o segredo só
 *             nasce quando alguém a toma.
 *   ao_vivo   um Drone dela está SOBREVOANDO com a zona no raio: transmissão ao vivo.
 *   federacao a zona é de um ALIADO da mesma federação (D-116), e a dona tem Central de
 *             Comunicação (`communication_level >= 1`): vê ao vivo, sem gastar vigia de Drone —
 *             a Central troca de olhos por quem já é do mesmo time.
 *   foto      um Drone dela já passou por lá: os números da última passagem, com a DATA.
 *   nenhuma   nunca viu: guarnição e depósito são null na tela — não zero, null. Zero é um
 *             fato ("está indefesa"); null é a honestidade de não saber.
 */
class Avistamentos
{
    /** @var array{vigias: Collection, fotos: Collection}|null memo por pedido — o mapa consulta 120 zonas de uma vez */
    private ?array $memo = null;

    private ?int $memoColonia = null;

    public function __construct(private EfeitosDaEndurance $efeitosDaEndurance) {}

    /**
     * @return array{intel: string, garrison: int|null, deposit_amount: int|null, visto_em: ?string}
     */
    public function de(Colony $colonia, NeutralZone $zona): array
    {
        if ($zona->owner_colony_id === $colonia->id || $zona->owner_colony_id === null) {
            return $this->aoVivo($zona, $zona->owner_colony_id === null ? 'livre' : 'dona');
        }

        if ($colonia->federation_id !== null && $zona->nivelDe('central_de_comunicacao') >= 1) {
            // Não usa `$zona->owner` (relação eager-loaded pelo chamador, D-37, `NeutralZoneController`,
            // que restringe as colunas a `id,name,user_id` — sem `federation_id`): busca fresca.
            $dona = Colony::find($zona->owner_colony_id);

            if ($dona && $dona->federation_id === $colonia->federation_id) {
                return $this->aoVivo($zona, 'federacao');
            }
        }

        ['vigias' => $vigias, 'fotos' => $fotos] = $this->da($colonia);

        foreach ($vigias as $vigia) {
            if (MapaFertways::distancia($vigia->alvo_x, $vigia->alvo_y, $zona->x, $zona->y) <= $vigia->raio) {
                return $this->aoVivo($zona, 'ao_vivo');
            }
        }

        if ($foto = $fotos->get($zona->id)) {
            return [
                'intel' => 'foto',
                'garrison' => (int) $foto->garrison,
                'deposit_amount' => (int) $foto->deposit_amount,
                'visto_em' => $foto->seen_at->toIso8601String(),
            ];
        }

        return ['intel' => 'nenhuma', 'garrison' => null, 'deposit_amount' => null, 'visto_em' => null];
    }

    private function aoVivo(NeutralZone $zona, string $intel): array
    {
        return [
            'intel' => $intel,
            'garrison' => $zona->guarnicao(),
            'deposit_amount' => (int) $zona->deposit_amount,
            'visto_em' => null,
        ];
    }

    /** Duas consultas por pedido, não duas por zona: o mapa pergunta por 120 de uma vez. */
    private function da(Colony $colonia): array
    {
        if ($this->memo !== null && $this->memoColonia === $colonia->id) {
            return $this->memo;
        }

        $agora = now();

        // Bônus de raio da Endurance (D-135) — colônia inteira, um valor só, aplicado a cada drone
        // dela abaixo (mesmo formato de `ConcluirMissoes::fotografar()`).
        $bonusRaio = $this->efeitosDaEndurance->bonusDeDroneRaio($colonia);

        $vigias = Vehicle::where('colony_id', $colonia->id)
            ->where('type', DroneSpecs::TIPO)
            ->where('status', 'em_rota')
            ->where('leg', 'vigia')
            ->where('departs_at', '<=', $agora)
            ->where('arrives_at', '>', $agora)
            ->get()
            ->map(function (Vehicle $d) use ($bonusRaio) {
                $alvo = NeutralZone::find($d->destination_id);

                return $alvo ? (object) [
                    'alvo_x' => $alvo->x,
                    'alvo_y' => $alvo->y,
                    'raio' => EfeitosDaEndurance::aplicarBonus(DroneSpecs::RAIO[$d->level] ?? 6, $bonusRaio),
                ] : null;
            })
            ->filter()
            ->values();

        $fotos = DroneSighting::where('colony_id', $colonia->id)->get()->keyBy('zone_id');

        $this->memoColonia = $colonia->id;

        return $this->memo = ['vigias' => $vigias, 'fotos' => $fotos];
    }
}
