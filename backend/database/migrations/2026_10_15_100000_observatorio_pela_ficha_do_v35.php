<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * O Observatório deixa de copiar o Laboratório e passa a custar o que o v35 publica (D-256).
 *
 * O D-252 ancorou custo, tempo e energia no Laboratório, linha a linha, dizendo que nenhum documento
 * publicava os do Observatório. O v35 publica — "11. Observatório", como especialização. O usuário
 * aprovou adotar a ficha numa construção que SOMA (e não substitui) o Laboratório.
 *
 * ## Por que migration
 *
 * A mesma razão do `2026_10_07_300000_o_observatorio`: o `deploy.sh` não roda seeder, e re-rodar o
 * `BuildingSpecSeeder` reescreveria todos os prédios. Esta lê o mesmo JSON que o seeder lê — os
 * números moram num lugar só — e atualiza só as linhas do Observatório.
 *
 * ## E o teto de vagas, 3 → 4
 *
 * A ficha vai a 4 trilhas no nível 5. Com o teto em 3, os níveis 4 e 5 do Observatório não somariam
 * nada — o mesmo defeito que esta decisão conserta nos níveis 1 a 4. Só sobe se ainda estiver no
 * padrão: um teto que o operador já mudou à mão não é desfeito por um deploy.
 */
return new class extends Migration
{
    private const TETO_ANTES = 3;

    private const TETO_DEPOIS = 4;

    public function up(): void
    {
        $this->escrever($this->daFicha());

        DB::table('research_settings')->where('id', 1)->where('vagas_teto', self::TETO_ANTES)
            ->update(['vagas_teto' => self::TETO_DEPOIS, 'updated_at' => now()]);
    }

    /** Volta à âncora do D-252: o Laboratório, linha a linha. */
    public function down(): void
    {
        $lab = DB::table('building_specs')->where('building_type', 'laboratorio')->get()->keyBy('level');

        $this->escrever($lab->map(fn ($l) => [
            'build_time_seconds' => (int) $l->build_time_seconds,
            'cost_json' => $l->cost_json,
            'energia_consumo_hora' => (int) $l->energia_consumo_hora,
        ])->all());

        DB::table('research_settings')->where('id', 1)->where('vagas_teto', self::TETO_DEPOIS)
            ->update(['vagas_teto' => self::TETO_ANTES, 'updated_at' => now()]);
    }

    /** @return array<int, array{build_time_seconds:int, cost_json:string, energia_consumo_hora:int}> */
    private function daFicha(): array
    {
        $tabelas = json_decode(
            file_get_contents(database_path('seeders/data/building_specs.json')), true, flags: JSON_THROW_ON_ERROR,
        );
        $prod = json_decode(
            file_get_contents(database_path('seeders/data/production.json')), true, flags: JSON_THROW_ON_ERROR,
        );

        $linhas = [];
        foreach (collect($tabelas)->firstWhere('type', 'observatorio')['levels'] as $lv) {
            $linhas[(int) $lv['level']] = [
                'build_time_seconds' => (int) $lv['build_time_seconds'],
                'cost_json' => json_encode($lv['cost'], JSON_UNESCAPED_UNICODE),
                'energia_consumo_hora' => (int) ($prod['observatorio']['consumo_energia'][(string) $lv['level']] ?? 0),
            ];
        }

        return $linhas;
    }

    /** @param array<int, array<string, mixed>> $porNivel */
    private function escrever(array $porNivel): void
    {
        foreach ($porNivel as $nivel => $campos) {
            DB::table('building_specs')
                ->where('building_type', 'observatorio')->where('level', $nivel)
                ->update($campos);
        }
    }
};
