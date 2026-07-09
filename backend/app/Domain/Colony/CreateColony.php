<?php

namespace App\Domain\Colony;

use App\Domain\Logistics\EscolherPosicao;
use App\Models\Building;
use App\Models\Colony;
use App\Models\Ledger;
use App\Models\Resource;
use App\Models\ResourceType;
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
    public function __construct(private readonly EscolherPosicao $posicao) {}

    public function handle(User $user, string $nome): Colony
    {
        return DB::transaction(function () use ($user, $nome) {
            $agora = now();
            [$x, $y] = $this->posicao->handle();

            $colony = Colony::create([
                'user_id' => $user->id,
                'name' => $nome,
                'x' => $x,
                'y' => $y,
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
                ], Resource::daColonia()),
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

            $this->concederRarosDoKit($colony, $agora);

            // Stub: as cinco missões de tutoria ("cinco missões nos três primeiros dias")
            // estão fora do MVP. Sem isto o subsídio de §24.7 nunca destrava e o colono não
            // constrói nada. Note que o corpo de §24.7 sequer menciona tutoria — só a tabela
            // de onboarding a exige. Remover quando as missões existirem. Ver D-18.
            $user->forceFill(['tutorial_completed_at' => $agora])->save();

            return $colony->fresh(['buildings', 'resources', 'vehicles']);
        });
    }

    /**
     * Concede os recursos raros necessários para erguer cada construção do MVP uma vez, no
     * nível 1. A quantidade NÃO é digitada: é somada de `building_specs`, que vem do GDD.
     * Assim o kit acompanha o documento em vez de virar constante mágica.
     *
     * Por que existe: 8 das 16 construções exigem raros no nível 1, e as fontes de raros da
     * Temporada 1 (eventos, zonas profundas, contratos do governo) estão fora do MVP. Sem o
     * kit, o jogador para logo depois das cinco essenciais. É decisão de design, não do GDD.
     * Ver docs/decisoes.md D-17.
     */
    private function concederRarosDoKit(Colony $colony, $agora): void
    {
        // Somado em PHP de propósito. Um JOIN com json_extract exigiria concatenação de
        // string, e `||` é concat no SQLite mas OR no MariaDB: passaria nos testes e
        // quebraria em produção.
        $codigosRaros = ResourceType::where('tax_class', 'raro')->pluck('code')->flip();

        $especificacoes = DB::table('building_specs')
            ->where('level', 1)
            ->whereIn('building_type', Building::MVP)
            ->pluck('cost_json');

        $total = [];
        foreach ($especificacoes as $json) {
            foreach (json_decode($json, true) as $recurso => $qtd) {
                if ($codigosRaros->has($recurso)) {
                    $total[$recurso] = ($total[$recurso] ?? 0) + $qtd;
                }
            }
        }

        foreach ($total as $codigo => $qtd) {
            $colony->resources()->where('resource_type', $codigo)->update(['amount' => $qtd]);

            Ledger::create([
                'colony_id' => $colony->id,
                'type' => 'kit_inicial',
                'amount' => $qtd,
                'resource_type' => $codigo,
                'ref' => 'onboarding:kit_raros',
                'created_at' => $agora,
            ]);
        }
    }
}
