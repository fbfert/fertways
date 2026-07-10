<?php

namespace App\Domain\Logistics;

use App\Models\NeutralZone;
use Illuminate\Support\Facades\DB;

/**
 * Extração das zonas neutras ocupadas, no tick (GDD §07, §24.4; D-52, Fatia 1).
 *
 * Roda **fora** do laço por colônia, como as entregas: uma zona rende para o Depósito dela por
 * delta de tempo, sem relação com o `last_tick_at` de nenhuma colônia. A taxa é a do §19.1 a partir
 * da base de 100/h (D-52), enquanto ocupada, já produtiva e com o Depósito não cheio (§07: "quando
 * lota, extração para").
 *
 * Como o `ColonyTick`, não perde fração num tick de um minuto: credita as unidades **inteiras** e
 * avança `last_extraction_at` só pelo tempo que as produziu, carregando o resto para o tick seguinte.
 */
class ExtrairZonasNeutras
{
    /** @return int quantas zonas creditaram alguma coisa */
    public function handle($agora): int
    {
        $creditadas = 0;

        NeutralZone::whereNotNull('owner_colony_id')
            ->whereNotNull('productive_at')
            ->where('productive_at', '<=', $agora)
            ->orderBy('id')
            ->chunkById(200, function ($zonas) use ($agora, &$creditadas) {
                foreach ($zonas as $zona) {
                    if ($this->extrair($zona->id, $agora)) {
                        $creditadas++;
                    }
                }
            });

        return $creditadas;
    }

    private function extrair(int $zonaId, $agora): bool
    {
        return DB::transaction(function () use ($zonaId, $agora) {
            // Trava a zona: a retirada por um veículo mexe no mesmo `deposit_amount`.
            $zona = NeutralZone::whereKey($zonaId)->lockForUpdate()->first();
            if (! $zona) {
                return false;
            }

            $desde = $zona->last_extraction_at ?? $zona->productive_at;
            $segundos = $agora->getTimestamp() - $desde->getTimestamp();
            if ($segundos <= 0) {
                return false;
            }

            $espaco = $zona->capacidadeDeposito() - $zona->deposit_amount;
            if ($espaco <= 0) {
                // Cheio: a extração para (§07). O tempo com o Depósito lotado não vira produção.
                $zona->update(['last_extraction_at' => $agora]);

                return false;
            }

            $taxa = $zona->extracaoPorHora();
            $unidades = intdiv($taxa * $segundos, 3600); // piso: não credita fração de unidade
            if ($unidades <= 0) {
                return false; // menos de uma unidade de tempo: deixa o resto acumular
            }

            $credito = min($unidades, $espaco);
            $encheu = $credito >= $espaco;

            // Avança o relógio só pelo tempo que produziu o crédito (carrega o resto); se encheu,
            // para em `agora` — o excedente de tempo não vira produção retroativa.
            $novoRelogio = $encheu
                ? $agora
                : $desde->copy()->addSeconds(intdiv($credito * 3600, $taxa));

            $zona->update([
                'deposit_amount' => $zona->deposit_amount + $credito,
                'last_extraction_at' => $novoRelogio,
            ]);

            return true;
        });
    }
}
