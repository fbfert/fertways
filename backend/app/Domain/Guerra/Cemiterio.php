<?php

namespace App\Domain\Guerra;

use App\Models\Combat;
use App\Models\NeutralZone;
use App\Models\RoboEnterrado;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * O Cemitério de Robôs (GDD §17.4; D-290).
 *
 * O GDD o declara "sem função mecânica — apenas visual. Mostra unidades destruídas em combates
 * anteriores. Dá peso histórico a zonas muito disputadas". Ele continua sem função: não cura, não
 * defende, não rende. Faltava o que mostrar, porque o motor apagava o morto e não guardava nada.
 *
 * ## Enterrar
 *
 * Todo ponto do motor que apaga uma unidade **morta em combate** passa por `enterrar()`. Cada
 * unidade vira uma linha em `robos_enterrados`, na zona onde caiu, com o dono e o combate. O enterro
 * acontece **com ou sem Cemitério erguido**: o cemitério é a lápide, não a cova. Quem o ergue numa
 * zona disputada há meses encontra lá todos os mortos dela.
 *
 * Ficam de fora as tropas que somem sem morrer em combate, como a marcha para uma zona que deixou de
 * existir (`ChegarReforcos`): não há onde enterrá-las.
 */
class Cemiterio
{
    /** Quantas batalhas e quantos donos a lápide lista. O total conta todos. */
    public const LISTA = 10;

    /**
     * Enterra e apaga as unidades da consulta.
     *
     * @param  Builder<Unit>  $unidades
     * @param  int|null  $donoDaZona  o dono da guarnição, quando a zona acabou de mudar de mãos (a
     *                                conquista apaga a guarnição DEPOIS de trocar o dono)
     */
    public function enterrar(Builder $unidades, ?Combat $combate, ?int $donoDaZona = null): int
    {
        $mortos = (clone $unidades)->get(['id', 'colony_id', 'zone_id', 'type', 'level']);

        if ($mortos->isEmpty()) {
            return 0;
        }

        $agora = now();
        $linhas = [];

        foreach ($mortos as $u) {
            $zona = $u->zone_id ?? $combate?->zone_id;

            if ($zona === null) {
                continue;
            }

            $linhas[] = [
                'zone_id' => $zona,
                'combat_id' => $combate?->id,
                // A guarnição não tem colônia na linha: é do dono da zona, que é o defensor.
                'colony_id' => $u->colony_id ?? $donoDaZona ?? $combate?->defender_colony_id,
                'type' => $u->type,
                'level' => (int) $u->level,
                'morta_em' => $agora,
            ];
        }

        foreach (array_chunk($linhas, 500) as $lote) {
            RoboEnterrado::insert($lote);
        }

        return Unit::whereIn('id', $mortos->pluck('id'))->delete();
    }

    /** Erguido nesta zona, em qualquer nível. */
    public function erguido(NeutralZone $zona): bool
    {
        return $zona->nivelDe('cemiterio_de_robos') > 0;
    }

    /**
     * A lápide, que é o que o Cemitério mostra. Nula sem Cemitério: o enterro aconteceu, mas não há
     * onde ler.
     *
     * @return array{total:int, por_tipo: array<string,int>, por_colonia: list<array{colonia:?string, mortos:int}>, batalhas: list<array{combat_id:int, tipo:?string, em:?string, mortos:int}>, desde:?string}|null
     */
    public function lapide(NeutralZone $zona): ?array
    {
        if (! $this->erguido($zona)) {
            return null;
        }

        $base = RoboEnterrado::where('zone_id', $zona->id);

        $porTipo = (clone $base)->select('type', DB::raw('count(*) as n'))->groupBy('type')
            ->orderByDesc('n')->orderBy('type')->pluck('n', 'type')->map(fn ($n) => (int) $n)->all();

        $porColonia = (clone $base)->select('colony_id', DB::raw('count(*) as n'))->groupBy('colony_id')
            ->orderByDesc('n')->orderBy('colony_id')->limit(self::LISTA)->with('colony:id,name')->get()
            ->map(fn (RoboEnterrado $r) => ['colonia' => $r->colony?->name, 'mortos' => (int) $r->n])
            ->values()->all();

        $batalhas = (clone $base)->whereNotNull('combat_id')
            ->select('combat_id', DB::raw('count(*) as n'), DB::raw('max(morta_em) as em'))
            ->groupBy('combat_id')->orderByDesc('em')->orderByDesc('combat_id')->limit(self::LISTA)->get();
        $tipos = Combat::whereIn('id', $batalhas->pluck('combat_id'))->pluck('tipo', 'id');

        $primeiro = (clone $base)->min('morta_em');

        return [
            'total' => array_sum($porTipo),
            'por_tipo' => $porTipo,
            'por_colonia' => $porColonia,
            'batalhas' => $batalhas->map(fn ($b) => [
                'combat_id' => (int) $b->combat_id,
                'tipo' => $tipos[$b->combat_id] ?? null,
                'em' => $b->em ? Carbon::parse($b->em)->toIso8601String() : null,
                'mortos' => (int) $b->n,
            ])->values()->all(),
            'desde' => $primeiro ? Carbon::parse($primeiro)->toIso8601String() : null,
        ];
    }

    /**
     * Os mortos de cada zona que tem Cemitério, para o mapa: o peso histórico à vista de todos.
     *
     * @param  list<int>  $zonas
     * @return array<int,int> zona => mortos
     */
    public function mortosNoMapa(array $zonas): array
    {
        if ($zonas === []) {
            return [];
        }

        $comCemiterio = DB::table('zone_structures')->where('type', 'cemiterio_de_robos')
            ->where('level', '>', 0)->whereIn('neutral_zone_id', $zonas)->distinct()->pluck('neutral_zone_id');

        return RoboEnterrado::whereIn('zone_id', $comCemiterio)
            ->select('zone_id', DB::raw('count(*) as n'))->groupBy('zone_id')
            ->pluck('n', 'zone_id')->map(fn ($n) => (int) $n)->all();
    }
}
