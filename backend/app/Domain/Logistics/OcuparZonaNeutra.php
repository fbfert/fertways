<?php

namespace App\Domain\Logistics;

use App\Exceptions\DomainRuleException;
use App\Models\Colony;
use App\Models\Ledger;
use App\Models\NeutralZone;
use App\Models\Unit;
use Illuminate\Support\Facades\DB;

/**
 * Ocupa uma zona neutra livre (GDD §07; docs/decisoes.md D-52, Fatia 1).
 *
 * A ocupação é **pesada** (arbitrada no D-52): a colônia ergue um Posto de Comando (custo próprio),
 * guarnece a zona com 20 Robôs Mineradores (custo publicado no §4.3) e espera o estabelecimento —
 * o tempo do Posto mais o tempo de ocupação — antes de a extração começar. Tudo numa transação: ou
 * a zona é tomada e paga por inteiro, ou nada acontece.
 *
 * O que é publicado (custo do Robô, defesa, proteção de 8 dias) vem do GDD; o que foi inventado
 * (custo/tempo do Posto, tempo de ocupação) está nas constantes de `NeutralZone`, marcado lá.
 */
class OcuparZonaNeutra
{
    /** 1 Fert$ = 1.000.000 de micro (a mesma escala de `colonies.fert_micro`). */
    private const MICRO = 1_000_000;

    public function handle(Colony $colony, NeutralZone $zona): NeutralZone
    {
        /*
         * O gate do §05, vivo desde o D-75: zonas neutras são do marco 20 (Desbravador). Fica FORA
         * da transação de propósito — barrar não precisa de lock. E é AQUISIÇÃO: quem já tem zona
         * continua com ela (posse preservada); só ocupar OUTRA passa por aqui.
         */
        app(\App\Domain\Marco\ExigirMarco::class)->exigir($colony, 20, 'Ocupar uma zona neutra');

        return DB::transaction(function () use ($colony, $zona) {
            // Trava a zona e a colônia: duas requisições não podem tomar a mesma zona nem gastar
            // o mesmo recurso. Reconfere a ocupação sob a trava.
            $zona = NeutralZone::whereKey($zona->id)->lockForUpdate()->firstOrFail();

            if ($zona->estaOcupada()) {
                throw new DomainRuleException('zona_ocupada', 'Esta zona neutra já tem dono.');
            }

            $colony = Colony::whereKey($colony->id)->lockForUpdate()->firstOrFail();

            $custo = $this->custoDeRecursos();
            $this->debitarRecursos($colony, $custo, $zona);
            $this->debitarFert($colony, NeutralZone::POSTO_FERT, $zona);

            $agora = now();
            // Estabelecimento: ergue o Posto, depois o tempo de ocupação. Só então extrai.
            $produtiva = $agora->copy()->addHours(NeutralZone::POSTO_HORAS + NeutralZone::OCUPACAO_HORAS);

            $zona->update([
                'owner_colony_id' => $colony->id,
                'status' => 'protegida',
                'occupied_at' => $agora,
                'protected_until' => $agora->copy()->addDays(NeutralZone::DIAS_DE_PROTECAO),
                'command_post_level' => 1,
                'productive_at' => $produtiva,
                'deposit_level' => 1,
                'deposit_amount' => 0,
                // A extração é creditada a partir daqui: nada rende durante o estabelecimento.
                'last_extraction_at' => $produtiva,
            ]);

            // Desbravador de fato: ocupar rende XP (D-75) — dentro da transação, com o resto.
            app(\App\Domain\Marco\ConcederXp::class)->handle($colony->id, 'zona_ocupada', "zona:{$zona->id}");

            /*
             * A guarnição são 20 Robôs Mineradores — e desde o D-66 eles são LINHAS, não um
             * contador. O §27.2 os torna defensores improvisados (25% da Sentinela, ataque zero),
             * e o §27.6 exige que cada um tenha o seu HP: quem sobrevive a um ataque volta ferido,
             * quem chega a zero morre de vez. Um `int` não guarda isso.
             */
            $agora2 = now();

            Unit::insert(array_fill(0, NeutralZone::GUARNICAO_INICIAL, [
                'zone_id' => $zona->id,
                'colony_id' => null,
                'type' => 'robo_minerador',
                'level' => 1,
                'hp_bps' => Unit::INTEIRA,
                'status' => 'na_zona',
                'created_at' => $agora2,
                'updated_at' => $agora2,
            ]));

            return $zona->fresh();
        });
    }

    /**
     * Custo em recursos: o Posto de Comando (Metal Bruto) mais 20 Robôs Mineradores. O custo do
     * robô vem do `building_specs` (§4.3), não hardcodado — assim acompanha o balanceamento.
     *
     * @return array<string,int>
     */
    private function custoDeRecursos(): array
    {
        $custoRobo = json_decode(
            DB::table('building_specs')->where('building_type', 'robo_minerador')->where('level', 1)->value('cost_json') ?? '{}',
            true,
        );

        $custo = ['metal_bruto' => NeutralZone::POSTO_METAL_BRUTO];
        foreach ($custoRobo as $recurso => $qtd) {
            $custo[$recurso] = ($custo[$recurso] ?? 0) + $qtd * NeutralZone::GUARNICAO_INICIAL;
        }

        return $custo;
    }

    /** @param array<string,int> $custo */
    private function debitarRecursos(Colony $colony, array $custo, NeutralZone $zona): void
    {
        $estoque = $colony->resources()->lockForUpdate()->get()->keyBy('resource_type');

        foreach ($custo as $recurso => $qtd) {
            $tem = $estoque->get($recurso)?->amount ?? 0;
            if ($tem < $qtd) {
                throw new DomainRuleException(
                    'recursos_insuficientes',
                    "Faltam recursos para ocupar: {$recurso} exige {$qtd}, você tem {$tem}.",
                );
            }
        }

        foreach ($custo as $recurso => $qtd) {
            $estoque[$recurso]->decrement('amount', $qtd);

            Ledger::create([
                'colony_id' => $colony->id,
                'type' => 'custo_ocupacao',
                'amount' => -$qtd,
                'resource_type' => $recurso,
                'ref' => "zona:{$zona->id}:ocupacao",
                'created_at' => now(),
            ]);
        }
    }

    private function debitarFert(Colony $colony, int $fert, NeutralZone $zona): void
    {
        $micro = $fert * self::MICRO;

        $pagou = Colony::whereKey($colony->id)
            ->where('fert_micro', '>=', $micro)
            ->decrement('fert_micro', $micro);

        if ($pagou === 0) {
            throw new DomainRuleException(
                'fert_insuficiente',
                "Faltam Fert\$ para o Posto de Comando: exige {$fert}.",
            );
        }

        Ledger::create([
            'colony_id' => $colony->id,
            'type' => 'custo_ocupacao',
            'amount' => -$micro,
            'resource_type' => null,
            'ref' => "zona:{$zona->id}:posto",
            'created_at' => now(),
        ]);
    }
}
