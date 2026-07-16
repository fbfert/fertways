<?php

namespace App\Domain\Colony;

use App\Models\KitInicialSetting;
use App\Models\Vehicle;
use Illuminate\Support\Facades\DB;

/**
 * O kit inicial de toda colônia nova (D-85, 2026-07-15) — Fert$, um valor por recurso do
 * catálogo, e a frota. Decisão de balanceamento do usuário, **não vem do GDD**.
 *
 * **Editável pelo admin desde o D-92** (2026-07-16): o que era `const RECURSOS` em PHP virou
 * `kit_inicial_recursos`/`kit_inicial_settings` no banco, porque o usuário pediu para arbitrar o
 * kit sem precisar mexer em código. Só vale para quem funda DEPOIS de uma mudança — sem
 * backfill, mesma regra que o D-85 já tinha fixado.
 *
 * **O "muro de progressão" do D-17 quebra de propósito, por padrão**: 0 Nióbio Alienígena
 * (Torre de Defesa + Quartel exigem 5 juntas) e 2 Quartzo Piezoelétrico (Refinaria Química +
 * Antena de Comunicação exigem 3 juntas) — nenhum dos dois é produzível no jogo, só o governo
 * vende. Confirmado com o usuário: é decisão, não lacuna. O painel AVISA (não trava) se o admin
 * subir esses dois para além do que reabre o muro — `MURO_NIOBIO_REABRE_EM`/
 * `MURO_QUARTZO_REABRE_EM` são os limiares desse aviso, não uma validação.
 */
final class KitInicial
{
    /** A partir daqui, Torre de Defesa + Quartel juntas já saem do kit sem negociar (D-85). */
    public const MURO_NIOBIO_REABRE_EM = 5;

    /** A partir daqui, Refinaria Química + Antena de Comunicação juntas já saem do kit (D-85). */
    public const MURO_QUARTZO_REABRE_EM = 3;

    /** @return array<string,int> código do recurso => quantidade */
    public static function recursos(): array
    {
        return DB::table('kit_inicial_recursos')->pluck('amount', 'resource_type')->all();
    }

    public static function fertMicro(): int
    {
        return KitInicialSetting::singleton()->fert_micro;
    }

    /**
     * @return array<string,int> tipo do veículo (as mesmas chaves de `Vehicle::CAPACIDADE`) =>
     *                            quantos nascem com a colônia
     */
    public static function frota(): array
    {
        $config = KitInicialSetting::singleton();

        return [
            'furgao_de_comercio' => $config->furgoes,
            'caminhao_de_carga' => $config->caminhoes,
        ];
    }

    /** Todos os tipos de veículo que o kit PODE incluir — para o formulário do admin, não o jogo. */
    public static function tiposDeVeiculo(): array
    {
        return array_keys(Vehicle::CAPACIDADE);
    }
}
