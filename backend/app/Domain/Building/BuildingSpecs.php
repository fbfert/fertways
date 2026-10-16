<?php

namespace App\Domain\Building;

use App\Exceptions\DomainRuleException;
use Illuminate\Support\Facades\DB;

/**
 * Leitura de `building_specs`, que é semeada verbatim das tabelas do GDD.
 *
 * Nada aqui calcula custo ou tempo por fórmula. As curvas do GDD têm exceções reais
 * (Tanque de Combustível nível 4) e usam modos de arredondamento distintos para custo e
 * tempo. Ver docs/decisoes.md D-02.
 *
 * Desde o D-107, `para()` também consulta `building_specs_overrides` — o ajuste do admin (Gestão
 * de Construções), quando existir, vale por cima do valor do GDD. `building_specs` continua sendo
 * a base, reseedável à vontade quando uma construção nova entra no catálogo; o override é uma
 * tabela separada que nenhum Seeder toca, então o ajuste do admin sobrevive a um reseed.
 */
class BuildingSpecs
{
    /**
     * D-277 (A2.12, carga): o que `precarregar()` leu, por tipo. Vazio por padrão — só quem pede
     * explicitamente (o `/buildings`, que roda no laço de 5 s do cliente) lê de memória; todo o
     * resto do jogo continua lendo do banco a cada chamada, e um override do admin vale na hora.
     *
     * @var array<string, array{max: int|null, linhas: array<int, object>, overrides: array<int, object>}>
     */
    private array $lote = [];

    /** Lê fichas, overrides e nível máximo de vários tipos em três consultas, para este objeto. */
    public function precarregar(iterable $tipos): void
    {
        $tipos = collect($tipos)->unique()->values();

        if ($tipos->isEmpty()) {
            return;
        }

        $linhas = DB::table('building_specs')->whereIn('building_type', $tipos)->get()->groupBy('building_type');
        $overrides = DB::table('building_specs_overrides')->whereIn('building_type', $tipos)->get()->groupBy('building_type');

        foreach ($tipos as $tipo) {
            $minhas = $linhas->get($tipo, collect());
            $this->lote[$tipo] = [
                'max' => $minhas->isEmpty() ? null : (int) $minhas->max('level'),
                'linhas' => $minhas->keyBy(fn ($l) => (int) $l->level)->all(),
                'overrides' => $overrides->get($tipo, collect())->keyBy(fn ($l) => (int) $l->level)->all(),
            ];
        }
    }

    public function nivelMaximo(string $tipo): int
    {
        $max = array_key_exists($tipo, $this->lote)
            ? $this->lote[$tipo]['max']
            : DB::table('building_specs')->where('building_type', $tipo)->max('level');

        if ($max === null) {
            throw new DomainRuleException('construcao_desconhecida', "Construção desconhecida: {$tipo}");
        }

        return (int) $max;
    }

    /** @return array{custo: array<string,int>, tempo_segundos: int} */
    public function para(string $tipo, int $nivel): array
    {
        $doLote = $this->lote[$tipo] ?? null;

        $spec = $doLote
            ? ($doLote['linhas'][$nivel] ?? null)
            : DB::table('building_specs')->where(['building_type' => $tipo, 'level' => $nivel])->first();

        if (! $spec) {
            throw new DomainRuleException(
                'nivel_inexistente',
                "O GDD não define o nível {$nivel} de {$tipo}.",
            );
        }

        $override = $doLote
            ? ($doLote['overrides'][$nivel] ?? null)
            : DB::table('building_specs_overrides')->where(['building_type' => $tipo, 'level' => $nivel])->first();

        $tempo = $override?->build_time_seconds ?? $spec->build_time_seconds;
        $custo = $override?->cost_json ?? $spec->cost_json;

        // NULL significa "nem o GDD nem o admin publicaram tempo para esta construção" (D-10).
        // Não é zero, e não pode virar construção instantânea. Falhar aqui é a única resposta
        // honesta. Um override de tempo pode DESTRAVAR uma construção que o GDD deixava muda —
        // é o ponto do D-107.
        if ($tempo === null) {
            throw new DomainRuleException(
                'tempo_indefinido',
                "O GDD não define tempo de construção para {$tipo}. Enfileiramento bloqueado.",
            );
        }

        return [
            'custo' => json_decode($custo, true),
            'tempo_segundos' => (int) $tempo,
        ];
    }
}
