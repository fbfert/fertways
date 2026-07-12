<?php

namespace App\Domain\Admin;

use App\Domain\Logistics\MapaFertways;
use App\Models\Colony;
use Illuminate\Support\Collection;

/**
 * O plano de realocação para slots de founder (D-51, Fatia 2), e o que o impede.
 *
 * Extraído do `fertways:realocar-founders` em 2026-07-13, quando o painel passou a **mostrar o plano
 * antes de aplicar**. Antes, o painel tinha um botão que aplicava no escuro: você clicava e ou movia
 * todas as colônias do jogo, ou levava um "abortado" que **não dizia por quê**. A regra tem de ser uma
 * só, ou a simulação da tela mentiria em relação ao que o comando faz.
 *
 * A designação é **determinística** e não é escolha de ninguém: a colônia mais antiga (menor id) leva
 * o primeiro slot populável na ordem canônica de `MapaFertways::slotsFounder`. É o D-51 — as primeiras
 * a chegar ganham as melhores posições, perto do Mercado.
 */
class PlanoFounders
{
    /**
     * Os veículos que impedem a realocação, com nome e placa.
     *
     * Mover uma colônia é seguro só com os veículos **ociosos**: a viagem em curso guardou a distância
     * no despacho, e mudar a origem por baixo dela deixaria a viagem apontando para um número que já
     * não existe. Antes esta lista era uma frase de erro; agora ela **diz quais** — para quem opera
     * saber quanto tempo esperar em vez de tentar de novo às cegas.
     *
     * @return Collection<int, array{colonia: string, colony_id: int, veiculo: int, placa: ?string,
     *                               status: string, chega_at: ?string}>
     */
    public function bloqueios(): Collection
    {
        return Colony::with('vehicles')->orderBy('id')->get()
            ->flatMap(fn (Colony $c) => $c->vehicles
                ->filter(fn ($v) => $v->status !== 'ocioso')
                ->map(fn ($v) => [
                    'colonia' => $c->name,
                    'colony_id' => $c->id,
                    'veiculo' => $v->id,
                    'placa' => $v->plate,
                    'status' => $v->status,
                    'chega_at' => $v->arrives_at?->format('d/m H:i'),
                ]))
            ->values();
    }

    /**
     * De onde para onde. **Pula quem já está no slot certo** — é o que torna o plano idempotente e o
     * que faz a tela dizer "nada a fazer" em vez de mover colônias por nada.
     *
     * @return Collection<int, array{colony: Colony, x: int, y: int}>
     */
    public function plano(): Collection
    {
        $colonias = Colony::orderBy('id')->get();
        $populaveis = $this->slotsPopulaveis();

        $plano = collect();

        foreach ($colonias as $i => $c) {
            if (! isset($populaveis[$i])) {
                break;   // não há slot para esta: `semSlot()` conta quantas ficaram de fora.
            }

            $alvo = $populaveis[$i];

            if ($c->x === $alvo['x'] && $c->y === $alvo['y']) {
                continue;
            }

            $plano->push(['colony' => $c, 'x' => $alvo['x'], 'y' => $alvo['y']]);
        }

        return $plano;
    }

    /** Quantas colônias não cabem no disco. Se for > 0, o comando aborta e a tela tem de avisar. */
    public function semSlot(): int
    {
        return max(0, Colony::count() - $this->slotsPopulaveis()->count());
    }

    /** @return Collection<int, array{x: int, y: int, reservado: bool}> */
    private function slotsPopulaveis(): Collection
    {
        return collect(MapaFertways::slotsFounder())->reject(fn ($s) => $s['reservado'])->values();
    }
}
