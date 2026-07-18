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
    public function nivelMaximo(string $tipo): int
    {
        $max = DB::table('building_specs')->where('building_type', $tipo)->max('level');

        if ($max === null) {
            throw new DomainRuleException('construcao_desconhecida', "Construção desconhecida: {$tipo}");
        }

        return (int) $max;
    }

    /** @return array{custo: array<string,int>, tempo_segundos: int} */
    public function para(string $tipo, int $nivel): array
    {
        $spec = DB::table('building_specs')
            ->where(['building_type' => $tipo, 'level' => $nivel])
            ->first();

        if (! $spec) {
            throw new DomainRuleException(
                'nivel_inexistente',
                "O GDD não define o nível {$nivel} de {$tipo}.",
            );
        }

        $override = DB::table('building_specs_overrides')
            ->where(['building_type' => $tipo, 'level' => $nivel])
            ->first();

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
