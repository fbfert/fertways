<?php

namespace App\Domain\Colony;

use App\Models\Building;
use App\Models\Colony;
use App\Models\Ledger;
use App\Models\Resource;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Support\Facades\DB;

/**
 * Fundação de uma colônia no slot principal.
 *
 * Tudo numa transação: cria a colônia, as 16 construções do MVP no nível 0, as linhas de
 * recurso zeradas, o Furgão do kit inicial e o lançamento dos 50 Fert$. Se qualquer passo
 * falhar, nada persiste — senão um jogador poderia ficar com colônia sem recursos ou sem
 * veículo, estados que nenhuma parte do jogo sabe consertar.
 *
 * Regras do GDD aplicadas aqui:
 *  - Saldo inicial de 50 Fert$ ("Todo colono recebe 50 Fert$ ao chegar em Fertways").
 *  - Furgão de Comércio no kit inicial ("todo colono começa com um").
 *  - Construções começam no nível 0. A subvenção de §24.7 cobre "a construção e os
 *    upgrades" das cinco essenciais até o nível 3, o que pressupõe que o nível 1 ainda
 *    seja construído — não concedido. Ver docs/decisoes.md D-13.
 *  - Recursos começam em 0. O colono usa os 50 Fert$ para comprar o primeiro lote de
 *    Ligas Metálicas no Mercado Central (§24.7).
 */
class CreateColony
{
    public function handle(User $user, string $nome): Colony
    {
        return DB::transaction(function () use ($user, $nome) {
            $agora = now();

            $colony = Colony::create([
                'user_id' => $user->id,
                'name' => $nome,
                'founded_at' => $agora,
                'milestone' => 'colonizacao_inicial',
                'fert_micro' => Colony::SALDO_INICIAL_MICRO,
                'last_tick_at' => $agora,
            ]);

            $colony->buildings()->createMany(
                array_map(fn (string $tipo) => ['type' => $tipo, 'level' => 0], Building::MVP),
            );

            $colony->resources()->createMany(
                array_map(fn (string $r) => [
                    'resource_type' => $r,
                    'amount' => 0,
                    // NULL: o GDD não define teto de armazenamento do slot principal.
                    'storage_cap' => null,
                ], Resource::DA_COLONIA),
            );

            $colony->vehicles()->create([
                'type' => 'furgao_de_comercio',
                'level' => 1,
                'status' => 'ocioso',
                'capacity' => Vehicle::CAPACIDADE['furgao_de_comercio'],
            ]);

            // O saldo entra como lançamento, não como número mudo na coluna: o ledger é a
            // fonte auditável, e `colonies.fert_micro` é só a projeção do saldo corrente.
            Ledger::create([
                'colony_id' => $colony->id,
                'type' => 'saldo_inicial',
                'amount' => Colony::SALDO_INICIAL_MICRO,
                'resource_type' => null,
                'ref' => 'onboarding:saldo_inicial',
                'created_at' => $agora,
            ]);

            return $colony->fresh(['buildings', 'resources', 'vehicles']);
        });
    }
}
