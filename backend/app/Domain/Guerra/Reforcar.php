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
 * Despacha reforços para uma zona sua (GDD §27.5; docs/decisoes.md D-70).
 *
 * ⚠️ **A tela já prometia isto e a ação não existia.** O Quartel dizia ao defensor "ainda dá tempo de
 * reforçar a zona: despache Sentinelas antes de a marcha chegar" — e não havia rota, nem botão, nem
 * domínio. O motor de combate **já contava** reforços desde o D-66 (ele recongela a força a cada
 * chegada, que é o que faz o §27.5 prometer que "reforços tardios podem ainda mudar o resultado"),
 * mas ninguém podia mandá-los. Era uma promessa na tela sem nada por trás.
 *
 * ── Como funciona ───────────────────────────────────────────────────────────────────────────────
 *
 * As unidades marcham à mesma velocidade de um ataque: **1,3× mais lentas que a civil** (§27.4, que
 * é explícito — "unidades de reforço defensivo também levam 1,3× mais tempo para chegar à zona sob
 * ataque"). Elas **não contam** enquanto marcham: só entram na Força Defensiva quando chegam, e é
 * exatamente isso que faz o combate ser uma corrida contra o relógio.
 *
 * **Reforçar não exige combate em curso.** Guarnecer uma zona em paz é a mesma coisa que socorrê-la
 * sob ataque — e o colono prudente reforça antes, não durante.
 */
class Reforcar
{
    /** O que se manda para uma zona: quem defende. O Infiltrador e o Predador não defendem nada. */
    public const TIPOS = ['sentinela', 'robo_minerador'];

    /** Slots por minuto de uma unidade civil — o Furgão do §21.2. A mesma âncora do ataque. */
    private const VELOCIDADE_CIVIL = 4.0;

    /**
     * @param  list<int>  $unitIds  unidades em casa, na colônia.
     * @return int quantas marcharam
     */
    public function handle(Colony $colony, NeutralZone $zona, array $unitIds): int
    {
        return DB::transaction(function () use ($colony, $zona, $unitIds) {
            $zona = NeutralZone::whereKey($zona->id)->lockForUpdate()->firstOrFail();

            if ($zona->owner_colony_id !== $colony->id) {
                throw new DomainRuleException(
                    'zona_nao_e_sua',
                    'Só se reforça a própria zona. Para tomar a de outro, ataque.',
                );
            }

            /*
             * ⚠️ **Cercada, o reforço não passa** — e é a mordida mais dura do cerco.
             *
             * "Nada entra nem sai" (§28.10, D-66) alcança as tropas: quem está sitiado não recebe
             * socorro. A única saída é **romper o cerco** (ver `RomperCerco`), que é justamente o que
             * o §28.10 manda o defensor fazer. Se o reforço entrasse, o cerco seria decorativo.
             */
            if ($zona->cercada()) {
                throw new DomainRuleException(
                    'zona_cercada',
                    'A zona está cercada: nada entra nem sai, nem tropa. Rompa o cerco.',
                );
            }

            $unidades = Unit::whereIn('id', $unitIds)
                ->where('colony_id', $colony->id)
                ->where('status', 'casa')
                ->whereIn('type', self::TIPOS)
                ->where('hp_bps', '>', 0)
                ->lockForUpdate()
                ->get();

            if ($unidades->count() !== count($unitIds) || $unidades->isEmpty()) {
                throw new DomainRuleException(
                    'unidades_indisponiveis',
                    'Selecione Sentinelas ou Robôs Mineradores que estejam no pátio e não destruídos.',
                );
            }

            $chega = now()->addSeconds($this->marchaEmSegundos($colony, $zona));

            /*
             * `zone_id` já apontado, mas `status = marchando`: elas pertencem à zona e ainda não
             * estão nela. É essa distinção que o `Forcas::defensiva` lê — ele conta `na_zona` e
             * `em_combate`, nunca `marchando`. Uma tropa a caminho não defende nada.
             */
            Unit::whereIn('id', $unidades->pluck('id'))->update([
                'zone_id' => $zona->id,
                'colony_id' => null,
                'status' => 'marchando',
                'arrives_at' => $chega,
            ]);

            return $unidades->count();
        });
    }

    /**
     * A marcha, em segundos. **1,3× mais lenta que a civil**, como a de ataque.
     *
     * O §27.4 é explícito e não deixa margem: "unidades de reforço defensivo **também** levam 1,3×
     * mais tempo para chegar à zona sob ataque". Um reforço mais rápido que o inimigo tornaria o
     * ataque impossível de vencer contra um defensor atento.
     */
    private function marchaEmSegundos(Colony $origem, NeutralZone $zona): int
    {
        $slots = MapaFertways::distancia($origem->x, $origem->y, $zona->x, $zona->y);
        $minutos = $slots / self::VELOCIDADE_CIVIL * Combat::MARCHA;

        return max(60, (int) round($minutos * 60));
    }
}
