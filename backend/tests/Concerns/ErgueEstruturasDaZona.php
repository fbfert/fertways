<?php

namespace Tests\Concerns;

use App\Domain\Zona\ZonaSlots;
use App\Models\NeutralZone;

/**
 * Ergue estruturas de teste na zona (D-144) — antes do D-144, os testes só passavam
 * `['wall_level' => 3]` etc. no `array_merge` de `NeutralZone::create()`; agora o nível mora numa
 * linha de `zone_structures`, não numa coluna, então os testes precisam de um passo a mais.
 *
 * `criarZonaComEstruturas()` mantém as fábricas de cada teste quase idênticas ao que eram: o
 * `$extra` continua aceitando as chaves ANTIGAS (`'wall_level' => 3`) — o trait as reconhece e as
 * roteia para `zone_structures`, então só a fábrica de cada teste precisa mudar, não as dezenas de
 * chamadas espalhadas pelo arquivo.
 */
trait ErgueEstruturasDaZona
{
    /** A mesma tabela da migration `2026_07_21_150000_slots_da_zona_neutra.php`, ao contrário. */
    private const TIPO_DA_COLUNA_ANTIGA = [
        'command_post_level' => 'posto_de_comando',
        'deposit_level' => 'deposito_de_zona_neutra',
        'wall_level' => 'muralha_de_perimetro',
        'watchtower_level' => 'torre_de_vigia',
        'bastion_level' => 'bastiao',
        'shelter_level' => 'abrigo_de_robos',
        'refinery_level' => 'refinaria_de_campo',
        'parking_level' => 'estacionamento_da_zona',
        'cemetery_level' => 'cemiterio_de_robos',
        'extraction_level' => 'estrutura_de_extracao',
        'communication_level' => 'central_de_comunicacao',
        'landing_pad_level' => 'plataforma_de_pouso_da_zona',
        'industry_level' => 'industria_siderurgica',
    ];

    /**
     * Recebe o MESMO array que os testes já montavam para `NeutralZone::create(array_merge(...))`
     * antes do D-144 — colunas reais (`x`, `level`, `sieged_at`…) e chaves antigas de estrutura
     * (`wall_level`, `command_post_level`…) misturadas no mesmo array. Separa as duas coisas: as
     * reais viram colunas de `neutral_zones`; as de estrutura viram linhas de `zone_structures`.
     *
     * @param  array<string,mixed>  $colunas
     */
    protected function criarZonaComEstruturas(array $colunas): NeutralZone
    {
        $reais = [];
        $estruturas = [];

        foreach ($colunas as $chave => $valor) {
            if (array_key_exists($chave, self::TIPO_DA_COLUNA_ANTIGA)) {
                $estruturas[self::TIPO_DA_COLUNA_ANTIGA[$chave]] = $valor;
            } else {
                $reais[$chave] = $valor;
            }
        }

        $zona = NeutralZone::create($reais);

        return $this->ergueEstruturas($zona, $estruturas);
    }

    /**
     * @param  array<string,int>  $niveis  tipo => nível
     *
     * Uma estrutura do MESMO tipo já erguida sobe de nível no slot que já tinha; uma nova pega o
     * primeiro slot ainda livre (nunca reusa um slot que outra estrutura já ocupa — importante para
     * as repetíveis, D-144, que podem ganhar mais de uma linha na mesma zona de teste).
     */
    protected function ergueEstruturas(NeutralZone $zona, array $niveis): NeutralZone
    {
        foreach ($niveis as $tipo => $nivel) {
            $existente = $zona->zoneStructures()->where('type', $tipo)->first();

            if ($existente) {
                $existente->update(['level' => $nivel]);

                continue;
            }

            if ($tipo === 'posto_de_comando') {
                $slot = ZonaSlots::POSTO_SLOT;
            } else {
                $ocupados = $zona->zoneStructures()->pluck('slot')->all();
                $slot = collect(ZonaSlots::NIVEL1_SLOTS)->first(fn ($s) => ! in_array($s, $ocupados, true));
            }

            $zona->zoneStructures()->create(['slot' => $slot, 'type' => $tipo, 'level' => $nivel]);
        }

        return $zona->fresh();
    }
}
