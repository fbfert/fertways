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

        // NULL significa "o GDD não publica tempo para esta construção" (D-10). Não é zero,
        // e não pode virar construção instantânea. Falhar aqui é a única resposta honesta.
        if ($spec->build_time_seconds === null) {
            throw new DomainRuleException(
                'tempo_indefinido',
                "O GDD não define tempo de construção para {$tipo}. Enfileiramento bloqueado.",
            );
        }

        return [
            'custo' => json_decode($spec->cost_json, true),
            'tempo_segundos' => (int) $spec->build_time_seconds,
        ];
    }
}
