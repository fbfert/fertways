<?php

namespace App\Domain\Zona;

use App\Models\Colony;
use App\Models\Ledger;
use App\Models\NeutralZone;
use App\Models\Unit;
use App\Models\ZoneBuild;
use App\Models\ZoneEvent;
use App\Models\ZoneMaterial;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Manutenção territorial (GDD §27.12; docs/decisoes.md D-52 para o decaimento, D-84 para existir
 * de verdade). Nunca tinha sido implementada — nem para zona de nível 1: ocupar uma zona não
 * custava nada por dia até aqui.
 *
 * Cobra da colônia (não do Depósito da própria zona: são recursos diferentes — Biomassa, Energia,
 * Ligas, Componentes — e a zona só armazena o que ela mesma extrai). Sem pagar por 24 h, os Pontos
 * de Defesa da zona decaem 5%/dia (`NeutralZone::penalidadeManutencaoBps()`, lido por
 * `Forcas::defensiva()`); sem pagar por 72 h, a zona é abandonada — o D-52 já havia corrigido os
 * números do §27.12 (a Parte I corrige a Parte II: por dia, não por hora; 72 h, não 48 h).
 *
 * Roda no tick, uma vez por zona por dia (o próprio `maintenance_next_due_at` limita a cadência,
 * mesmo chamado a cada minuto).
 */
class CobrarManutencaoTerritorial
{
    /** @return array{cobradas: int, abandonadas: int} */
    public function handle(?Carbon $agora = null): array
    {
        $agora ??= now();

        $ids = NeutralZone::whereNotNull('owner_colony_id')
            ->whereNotNull('maintenance_next_due_at')
            ->where('maintenance_next_due_at', '<=', $agora)
            ->pluck('id');

        $cobradas = 0;
        $abandonadas = 0;

        foreach ($ids as $id) {
            $resultado = $this->processar($id, $agora);

            if ($resultado === 'cobrada') {
                $cobradas++;
            } elseif ($resultado === 'abandonada') {
                $abandonadas++;
            }
        }

        return ['cobradas' => $cobradas, 'abandonadas' => $abandonadas];
    }

    private function processar(int $id, Carbon $agora): ?string
    {
        return DB::transaction(function () use ($id, $agora) {
            $zona = NeutralZone::whereKey($id)->lockForUpdate()->first();

            if (! $zona || $zona->owner_colony_id === null) {
                return null;   // outro processo já resolveu, ou a zona já não tem dono.
            }

            if ($zona->deveSerAbandonada()) {
                $this->abandonar($zona);

                return 'abandonada';
            }

            $colony = Colony::whereKey($zona->owner_colony_id)->lockForUpdate()->first();

            if (! $colony) {
                return null;
            }

            $custo = $zona->custoDeManutencao();
            $pago = $this->tentarDebitar($colony, $custo, $zona);

            if ($pago) {
                $zona->update([
                    'maintenance_next_due_at' => $zona->maintenance_next_due_at->copy()->addHours(24),
                    'maintenance_unpaid_since' => null,
                ]);

                return 'cobrada';
            }

            // Não pagou: a inadimplência começa exatamente na data que venceu, não em "agora" —
            // um tick atrasado não pode encolher a carência de 24 h nem o abandono de 72 h.
            $zona->update([
                'maintenance_next_due_at' => $zona->maintenance_next_due_at->copy()->addHours(24),
                'maintenance_unpaid_since' => $zona->maintenance_unpaid_since ?? $zona->maintenance_next_due_at,
            ]);

            return null;
        });
    }

    /** @param array<string,int> $custo */
    private function tentarDebitar(Colony $colony, array $custo, NeutralZone $zona): bool
    {
        $estoque = $colony->resources()->lockForUpdate()->get()->keyBy('resource_type');

        foreach ($custo as $recurso => $qtd) {
            if (($estoque->get($recurso)?->amount ?? 0) < $qtd) {
                return false;
            }
        }

        foreach ($custo as $recurso => $qtd) {
            $estoque[$recurso]->decrement('amount', $qtd);

            Ledger::create([
                'colony_id' => $colony->id,
                'type' => 'manutencao_territorial',
                'amount' => -$qtd,
                'resource_type' => $recurso,
                'ref' => "zona:{$zona->id}:manutencao",
                'created_at' => now(),
            ]);
        }

        return true;
    }

    /**
     * Abandono automático (§27.12): "fica disponível para ocupação por qualquer jogador". Sem
     * precedente no código para reaproveitar — a conquista por guerra sempre TRANSFERE a zona
     * (`ResolverCombates::vitoriaDoAtacante()`), nunca a esvazia. Decisão do D-84: reset completo
     * ao estado de zona nunca ocupada, e não um "congelamento" com os níveis preservados — do
     * contrário, abandonar de propósito uma zona nível 5 para outra conta do mesmo jogador ocupar
     * de graça viraria a lavagem que o D-73 já fechou para o Furgão, só que para zonas.
     */
    private function abandonar(NeutralZone $zona): void
    {
        Unit::where('zone_id', $zona->id)->delete();

        // Reset completo (comentário abaixo): até o Posto de Comando some — a zona volta ao estado
        // de quem nunca foi ocupada, não a um "congelamento" com os níveis preservados (D-144).
        \App\Models\ZoneStructure::where('neutral_zone_id', $zona->id)->delete();

        /*
         * Revisão de 2026-07-19: o canteiro (`zone_materials`) e a fila de obras
         * (`zone_build_queue`) NÃO eram limpos aqui — o reset "completo" que este comentário já
         * prometia (abaixo) tinha um buraco de verdade. Uma obra em curso sobrevivia ao abandono
         * e, quando o prazo vencesse, `ConcluirObrasDaZona` erguia a estrutura para quem quer que
         * fosse o dono NAQUELE momento — inclusive uma segunda conta do mesmo jogador que
         * reocupasse a zona de propósito. É exatamente a lavagem que este método diz impedir.
         */
        ZoneMaterial::where('zone_id', $zona->id)->delete();
        ZoneBuild::where('zone_id', $zona->id)->delete();

        // Um upgrade pago (Metal Bruto + Fert$ + reforço de guarnição, debitados na hora do
        // pedido em `SubirNivelDaZona`) que ainda não tinha concluído se perde aqui, sem estorno —
        // o reset é completo, de propósito (ver o comentário abaixo). Antes disso desaparecia em
        // silêncio; agora fica registrado, coerente com "todo Fert$/recurso tem história".
        if ($zona->level_target !== null) {
            $perdido = NeutralZone::custoDeUpgrade($zona->level_target);

            ZoneEvent::create([
                'zone_id' => $zona->id, 'type' => 'upgrade_perdido_no_abandono',
                'colony_id' => $zona->owner_colony_id,
                'meta' => ['nivel_alvo' => $zona->level_target, 'custo_perdido' => $perdido],
                'created_at' => now(),
            ]);
        }

        // Histórico da zona (D-86): a última linha antes de o dono sumir daqui — por isso o
        // evento registra QUEM perdeu, já que depois deste update `owner_colony_id` é nulo.
        ZoneEvent::create([
            'zone_id' => $zona->id, 'type' => 'abandonada', 'colony_id' => $zona->owner_colony_id,
            'created_at' => now(),
        ]);

        $zona->update([
            'owner_colony_id' => null,
            'status' => 'livre',
            'occupied_at' => null,
            'protected_until' => null,
            'productive_at' => null,
            'level' => 1,
            'level_target' => null,
            'level_upgrade_finishes_at' => null,
            'deposit_amount' => 0,
            'last_extraction_at' => null,
            'maintenance_next_due_at' => null,
            'maintenance_unpaid_since' => null,
            'refined_amount' => 0,
            'last_refine_at' => null,
            'last_industry_at' => null,
            'sieged_at' => null,
            'modules_offline' => null,
            'modules_offline_expira_em' => null,
            'structures_saboted' => null,
        ]);
    }
}
