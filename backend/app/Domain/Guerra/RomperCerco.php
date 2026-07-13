<?php

namespace App\Domain\Guerra;

use App\Domain\Logistics\MapaFertways;
use App\Exceptions\DomainRuleException;
use App\Models\Colony;
use App\Models\Combat;
use App\Models\NeutralZone;
use App\Models\Unit;
use Illuminate\Support\Facades\DB;

/**
 * Rompe um cerco (GDD §28.10; docs/decisoes.md D-70).
 *
 * ⚠️ **Isto faltava, e sem ele o cerco era uma sentença.** O §28.10 dá ao defensor duas saídas — "o
 * defensor tem 48 horas para **romper o cerco** ou render-se" — e o jogo só tinha uma: esperar as 48 h
 * e entregar 30% do estoque exposto. Não havia como lutar.
 *
 *     "A ruptura ocorre por combate de Sentinelas nas rotas externas, com apoio possível de federação
 *      aliada." (§28.10)
 *
 * ── O que é diferente de um ataque normal ───────────────────────────────────────────────────────
 *
 * **A batalha é FORA da zona.** É o exército sitiante contra a força de socorro, "nas rotas
 * externas" — e por isso:
 *
 *  - **Nenhum bônus de construção conta.** A Muralha, a Torre e o Bastião defendem a zona, e a luta
 *    não é na zona. Quem rompe um cerco luta em campo aberto.
 *  - **A guarnição da zona não participa.** Ela está sitiada, do lado de dentro. Se pudesse sair para
 *    ajudar, o cerco não seria um cerco.
 *  - **Não há saque.** Quem rompe recupera a liberdade da zona, não o estoque de ninguém.
 *
 * Vencendo o socorro, o **cerco é levantado** e o exército sitiante é destruído. Vencendo o sitiante,
 * a força de socorro morre e **o cerco continua** — e o relógio das 48 h não parou.
 *
 * **Federações não existem** (D-44), então o apoio aliado do §28.10 fica de fora. Registrado.
 */
class RomperCerco
{
    private const VELOCIDADE_CIVIL = 4.0;

    /**
     * @param  list<int>  $unitIds  Sentinelas em casa. Só elas rompem: o §28.10 as nomeia.
     */
    public function handle(Colony $colony, Combat $cerco, array $unitIds): Combat
    {
        return DB::transaction(function () use ($colony, $cerco, $unitIds) {
            $cerco = Combat::whereKey($cerco->id)->lockForUpdate()->firstOrFail();

            if ($cerco->tipo !== 'cerco' || $cerco->status !== 'em_curso') {
                throw new DomainRuleException(
                    'nao_ha_cerco',
                    'Não há cerco em curso a romper.',
                );
            }

            if ($cerco->defender_colony_id !== $colony->id) {
                throw new DomainRuleException(
                    'cerco_nao_e_seu',
                    'Este cerco não é contra você. Só o sitiado o rompe.',
                );
            }

            // Uma tentativa de ruptura por vez. Duas forças de socorro ao mesmo tempo seriam duas
            // batalhas contra o mesmo exército, e as contas se atropelariam.
            $emCurso = Combat::where('zone_id', $cerco->zone_id)
                ->where('tipo', 'ruptura')
                ->whereIn('status', ['marchando', 'em_curso'])
                ->exists();

            if ($emCurso) {
                throw new DomainRuleException(
                    'ruptura_em_curso',
                    'Você já mandou uma força de socorro. Espere-a chegar.',
                );
            }

            $unidades = Unit::whereIn('id', $unitIds)
                ->where('colony_id', $colony->id)
                ->where('status', 'casa')
                ->where('type', 'sentinela')
                ->where('hp_bps', '>', 0)
                ->lockForUpdate()
                ->get();

            if ($unidades->count() !== count($unitIds) || $unidades->isEmpty()) {
                throw new DomainRuleException(
                    'unidades_indisponiveis',
                    'Só Sentinelas rompem um cerco (§28.10). Selecione as que estão no pátio.',
                );
            }

            $zona = NeutralZone::findOrFail($cerco->zone_id);

            $agora = now();
            $chega = $agora->copy()->addSeconds($this->marchaEmSegundos($colony, $zona));

            $ruptura = Combat::create([
                'zone_id' => $zona->id,
                // O sitiado é o ATACANTE da ruptura: é ele que sai a campo.
                'attacker_colony_id' => $colony->id,
                'defender_colony_id' => $cerco->attacker_colony_id,
                'tipo' => 'ruptura',
                'status' => 'marchando',
                'rodada' => 0,
                'chega_at' => $chega,
                'proxima_rodada_at' => $chega,
                // O cerco que ela vem quebrar. É por aqui que a rodada acha o exército sitiante.
                'resultado' => ['cerco_id' => $cerco->id],
            ]);

            Unit::whereIn('id', $unidades->pluck('id'))->update([
                'combat_id' => $ruptura->id,
                'status' => 'marchando',
                'arrives_at' => $chega,
            ]);

            return $ruptura;
        });
    }

    private function marchaEmSegundos(Colony $origem, NeutralZone $zona): int
    {
        $slots = MapaFertways::distancia($origem->x, $origem->y, $zona->x, $zona->y);
        $minutos = $slots / self::VELOCIDADE_CIVIL * Combat::MARCHA;

        return max(60, (int) round($minutos * 60));
    }
}
