<?php

namespace App\Domain\Zona;

use App\Domain\Production\Siderurgica;
use App\Models\NeutralZone;
use App\Models\ZoneMineral;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A Indústria Siderúrgica converte, no tick (docs/decisoes.md D-82) — construção nova, não está
 * no GDD.
 *
 * Só processa zonas de Metal Bruto (distrito Nordeste), disputando o MESMO `deposit_amount` que a
 * Refinaria de Campo já consome (`RefinarNaZona`) — decisão do usuário: quem chegar primeiro no
 * tick leva. Cada construção tem o seu PRÓPRIO relógio (`last_industry_at`, separado de
 * `last_refine_at`), mas as duas leem e escrevem o mesmo depósito.
 *
 * A cada 1000 Metal Bruto processado: 350 Ligas Metálicas — para `refined_amount`, o MESMO pote
 * da Refinaria de Campo, porque é o mesmo recurso — e 35 Alumínio + 30 Cobre + 20 Estanho +
 * 4 Ouro + 1 Tungstênio, para `zone_minerals`, que esta construção inaugura.
 *
 * Só em LOTES INTEIROS de 1000: a receita tem seis saídas simultâneas, e um lote fracionado
 * deixaria alguma delas sem unidade pra creditar. O excedente não fica perdido — o relógio avança
 * só pelo tempo que o gasto consumiu, e o resto continua acumulando (mesmo padrão da
 * `RefinarNaZona`).
 */
class ProcessarSiderurgicaNaZona
{
    /** @return int quantas zonas processaram */
    public function handle(?Carbon $agora = null): int
    {
        $agora ??= now();

        $ids = NeutralZone::whereNotNull('owner_colony_id')
            ->where('mineral', Siderurgica::INSUMO)
            ->where('industry_level', '>=', 1)
            ->where('deposit_amount', '>', 0)
            ->pluck('id');

        $processadas = 0;

        foreach ($ids as $id) {
            if ($this->processar($id, $agora)) {
                $processadas++;
            }
        }

        return $processadas;
    }

    private function processar(int $id, Carbon $agora): bool
    {
        return DB::transaction(function () use ($id, $agora) {
            $zona = NeutralZone::whereKey($id)->lockForUpdate()->first();

            if (! $zona || $zona->industry_level < 1 || $zona->deposit_amount <= 0) {
                return false;
            }

            // Cercada, nada entra nem sai (§28.10) — inclusive o que a própria zona consome de si
            // mesma. Mesma regra da Refinaria de Campo.
            if ($zona->depositoBloqueado()) {
                $zona->update(['last_industry_at' => $agora]);

                return false;
            }

            $taxa = $this->taxaPorHora($zona->industry_level);

            if ($taxa <= 0) {
                return false;
            }

            // O relógio próprio: a Siderúrgica converte por delta dela, sem relação com a
            // extração NEM com a Refinaria — cada construção lê o depósito no seu próprio ritmo.
            $desde = $zona->last_industry_at ?? $zona->productive_at ?? $zona->occupied_at ?? $agora;
            $segundos = $agora->getTimestamp() - $desde->getTimestamp();

            if ($segundos <= 0) {
                return false;
            }

            $capacidade = intdiv($taxa * $segundos, 3600);

            // Não processa mais do que há no depósito NESTE INSTANTE — se a Refinaria já levou a
            // maior parte neste mesmo tick, sobra menos para a Siderúrgica.
            $consumido = min($capacidade, $zona->deposit_amount);
            $lotes = intdiv($consumido, Siderurgica::BASE);

            if ($lotes <= 0) {
                // Ainda não deu para um lote inteiro. Não mexe no relógio: o tempo continua
                // acumulando, senão um tick de um minuto jogaria fora a fração toda vez.
                return false;
            }

            $gasto = $lotes * Siderurgica::BASE;

            $zona->update([
                'deposit_amount' => $zona->deposit_amount - $gasto,
                'refined_amount' => $zona->refined_amount + $lotes * Siderurgica::SAIDAS['ligas_metalicas'],
                // Avança o relógio só pelo tempo que o gasto consumiu — o resto acumula.
                'last_industry_at' => $desde->copy()->addSeconds(intdiv($gasto * 3600, max(1, $taxa))),
            ]);

            $minerais = $zona->minerais()->lockForUpdate()->get()->keyBy('resource_type');

            foreach (Siderurgica::SAIDAS_MINERAIS as $recurso) {
                $quantidade = $lotes * Siderurgica::SAIDAS[$recurso];

                if (isset($minerais[$recurso])) {
                    $minerais[$recurso]->increment('amount', $quantidade);
                } else {
                    ZoneMineral::create([
                        'zone_id' => $zona->id, 'resource_type' => $recurso, 'amount' => $quantidade,
                    ]);
                }
            }

            return true;
        });
    }

    /** A mesma taxa da colônia (D-82) — lida direto de `building_specs`, não recalculada. */
    private function taxaPorHora(int $nivel): int
    {
        $producao = DB::table('building_specs')
            ->where(['building_type' => 'industria_siderurgica', 'level' => $nivel])
            ->value('producao_hora_json');

        return $producao ? (int) (json_decode($producao, true)[Siderurgica::INSUMO] ?? 0) : 0;
    }
}
