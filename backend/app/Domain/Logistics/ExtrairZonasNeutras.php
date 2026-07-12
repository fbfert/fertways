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

            $taxa = $zona->extracaoPorHora();
            $unidades = intdiv($taxa * $segundos, 3600); // piso: não credita fração de unidade
            if ($unidades <= 0) {
                return false; // menos de uma unidade de tempo: deixa o resto acumular
            }

            /*
             * CERCADA: o depósito para de aceitar 30 minutos depois de o cerco começar (§28.10, as
             * 3 rodadas). A extração **continua correndo e se perde** — "extração continua mas não
             * há onde armazenar". O relógio avança; o mineral, não. É a mordida do cerco.
             */
            if ($zona->depositoBloqueado()) {
                $zona->update(['last_extraction_at' => $agora]);

                return false;
            }

            /*
             * A extração NÃO para no teto do Depósito, e isso contraria o §19.6 de propósito
             * (D-66): lá, os `500 … 19.222` são "capacidade" — o que cabe. Aqui, são o que está
             * **protegido**.
             *
             * A razão é que a Fatia 2 depende disso. O saque de 50% do §27.8 incide sobre o estoque
             * "não protegido", e o usuário arbitrou que protegido é o que cabe no Depósito. Com o
             * teto travando a extração, nada jamais excederia o Depósito, nada jamais estaria
             * exposto, e **o saque seria sempre zero** — a guerra não teria espólio nenhum.
             *
             * Então o excedente empilha ao relento. Deixar mineral rendendo na zona passa a ser um
             * risco real, retirá-lo passa a ser hábito, e subir o Depósito passa a valer a pena
             * porque protege mais. Ver `Domain\Guerra\Protegido`.
             */
            $zona->update([
                'deposit_amount' => $zona->deposit_amount + $unidades,
                'last_extraction_at' => $desde->copy()->addSeconds(intdiv($unidades * 3600, $taxa)),
            ]);

            return true;
        });
    }
}
