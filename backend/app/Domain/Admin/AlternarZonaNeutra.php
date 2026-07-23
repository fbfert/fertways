<?php

namespace App\Domain\Admin;

use App\Domain\Logistics\MapaFertways;
use App\Domain\Logistics\ZonasNeutras;
use App\Domain\Zona\ZonaSlots;
use App\Exceptions\DomainRuleException;
use App\Models\Colony;
use App\Models\FoundingCell;
use App\Models\NeutralZone;

/**
 * Cria ou remove uma zona neutra fora dos 4 distritos originais (D-148). **Só o dono.**
 *
 * Até aqui as 120 zonas eram inteiramente fixas (`ZonasNeutras::DISTRITOS`, D-51/D-52) — 4 blocos
 * de 30 células nos cantos, cada um com um mineral próprio. O usuário decidiu repensar isso: as 120
 * originais continuam intocadas (inclusive a que já está ocupada em produção), mas deixam de ser a
 * ÚNICA fonte de zona — o Dôno cria mais, onde quiser na periferia, escolhendo o mineral.
 *
 * Mesmo espírito reversível de `AlternarCelulaDeFundacao` (D-147): clicar de novo na mesma célula
 * desfaz — sem motivo, sem palavra de confirmação, porque uma zona LIVRE (sem dono, sem jogador
 * envolvido) é tão fácil de desmanchar quanto de criar. Uma zona com dono é outra história: aí a
 * fricção de baixo cuida disso, recusando a remoção.
 */
class AlternarZonaNeutra
{
    public function __construct(
        private readonly Auditoria $auditoria,
    ) {}

    /**
     * @param  string|null  $mineral  obrigatório só quando a célula ainda não é zona (criando);
     *                                ignorado ao remover.
     * @return bool  true se a zona ficou criada agora, false se foi removida
     */
    public function handle(int $x, int $y, ?string $mineral): bool
    {
        if (! MapaFertways::dentroDoMapa($x, $y)) {
            throw new DomainRuleException('fora_do_mapa', 'Esta célula não existe na grade.');
        }

        if (MapaFertways::ehCapital($x, $y)) {
            throw new DomainRuleException('celula_da_capital', 'A Capital nunca vira zona neutra.');
        }

        // Mesma trava do disco de founders que a Fundação já usa (D-147): zona nova só na
        // periferia — os 48 slots de founder seguem sendo território de colono, não de zona.
        if (MapaFertways::faixaDe($x, $y) !== 'periferia') {
            throw new DomainRuleException(
                'nao_e_periferia',
                'Zona neutra só pode ser criada na periferia — o disco de founders é território de colono.',
            );
        }

        $existente = NeutralZone::where('x', $x)->where('y', $y)->first();

        if ($existente) {
            if ($existente->owner_colony_id !== null) {
                throw new DomainRuleException(
                    'zona_ocupada',
                    "A zona ({$x}, {$y}) tem dono — não pode ser removida pelo mapa.",
                );
            }

            $antes = ['mineral' => $existente->mineral, 'district' => $existente->district];
            // O cascade das FKs (zone_structures, zone_build_queue, zone_events, unidades) já
            // limpa o resto — a linha de `neutral_zones` é a raiz de tudo o que pende dela.
            $existente->delete();

            $this->auditoria->registrar(
                'zona_neutra.remover',
                "Removeu a zona ({$x}, {$y}).",
                null,
                $antes,
                null,
            );

            return false;
        }

        if (! in_array($mineral, ZonasNeutras::MINERAIS, true)) {
            throw new DomainRuleException('mineral_invalido', 'Escolha um mineral válido.');
        }

        if (FoundingCell::where('x', $x)->where('y', $y)->exists()) {
            throw new DomainRuleException(
                'celula_de_fundacao',
                'Esta célula está liberada para fundação — não pode virar zona neutra.',
            );
        }

        if (Colony::where('x', $x)->where('y', $y)->exists()) {
            throw new DomainRuleException('celula_ocupada_por_colonia', 'Já há uma colônia nesta célula.');
        }

        $zona = NeutralZone::create([
            'x' => $x,
            'y' => $y,
            'district' => ZonasNeutras::quadranteDe($x, $y),
            'mineral' => $mineral,
            'level' => 1,
            'status' => 'livre',
            'deposit_amount' => 0,
        ]);

        // O Depósito nasce erguido mesmo numa zona livre (D-52) — mesmo gesto do
        // `NeutralZoneSeeder` pros 120 originais.
        $zona->zoneStructures()->create([
            'slot' => ZonaSlots::NIVEL1_SLOTS[0],
            'type' => 'deposito_de_zona_neutra',
            'level' => 1,
        ]);

        $this->auditoria->registrar(
            'zona_neutra.criar',
            "Criou a zona ({$x}, {$y}), mineral {$mineral}.",
            "zone:{$zona->id}",
            null,
            ['x' => $x, 'y' => $y, 'mineral' => $mineral],
        );

        return true;
    }
}
