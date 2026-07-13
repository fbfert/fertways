<?php

namespace App\Domain\Colony;

use App\Domain\Logistics\MapaFertways;
use App\Domain\Transport\Placas;
use App\Exceptions\DomainRuleException;
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
 * Tudo numa transação: cria a colônia, as **cinco essenciais já erguidas no nível 1** no miolo
 * dos 21 slots, as linhas de recurso zeradas, o Furgão do kit inicial e o lançamento dos 50
 * Fert$. Se qualquer passo falhar, nada persiste — senão um jogador poderia ficar com colônia
 * sem recursos ou sem veículo, estados que nenhuma parte do jogo sabe consertar.
 *
 * Regras do GDD aplicadas aqui:
 *  - Saldo inicial de 50 Fert$ ("Todo colono recebe 50 Fert$ ao chegar em Fertways").
 *  - Furgão de Comércio no kit inicial ("todo colono começa com um").
 *  - Recursos começam em 0. O colono usa os 50 Fert$ para comprar o primeiro lote de
 *    Ligas Metálicas no Mercado Central (§24.7).
 *
 * **Onde isto vai além do GDD (D-59, revisa o D-13).** O §24.7 subsidia o CUSTO das cinco
 * essenciais até o nível 3 — "o custo aparece normalmente na interface, mas junto com a mensagem
 * 'Esta construção será custeada pelo Governo Central até o nível 3'" —, o que pressupõe que o
 * nível 1 ainda seja *construído*. Era exatamente o que o D-13 fazia: nasciam no nível 0. Por
 * decisão do usuário (2026-07-11), elas agora **nascem prontas no nível 1**, no miolo fixo dos 21
 * slots. Subsídio continua valendo do nível 2 ao 3.
 *
 * Nenhuma outra linha de `buildings` é criada: **construção não erguida não ocupa slot**. A linha
 * passa a existir quando o colono escolhe onde pôr a construção (`ConstruirEmSlot`).
 */
class CreateColony
{
    /**
     * @param  int  $x  coluna escolhida pelo colono, com sinal (D-51)
     * @param  int  $y  linha escolhida pelo colono, com sinal (D-51)
     */
    public function handle(User $user, string $nome, int $x, int $y): Colony
    {
        // O sorteio do D-29 morreu (D-51): o colono escolhe a célula, e o privilégio do founder
        // — ficar perto do Mercado — é o desenho, não um efeito colateral. Aqui só se confere que
        // a escolha é legítima: slot de founder populável ou periferia, nunca Capital, anel ou
        // reservado. A colisão com colônia já instalada é a segunda trava; o `unique(x,y)` é a
        // terceira, contra a corrida de dois colonos pedindo a mesma célula no mesmo instante.
        if (! MapaFertways::podeFundar($x, $y)) {
            throw new DomainRuleException(
                'celula_invalida',
                'Esta célula não pode ser fundada: escolha um slot de founder livre ou a periferia.',
            );
        }

        if (Colony::where('x', $x)->where('y', $y)->exists()) {
            throw new DomainRuleException('celula_ocupada', 'Já há uma colônia nesta célula.');
        }

        return DB::transaction(function () use ($user, $nome, $x, $y) {
            $agora = now();

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

            /*
             * Sobrevivente de verdade: as cinco essenciais valem 5 níveis de obra no ledger de XP
             * (D-75). Sem isto, quem funda nasceria com XP zero enquanto o vizinho ganhou pelos
             * mesmos cinco prédios — e o recálculo retroativo (que conta níveis DE PÉ) divergiria
             * do ledger vivo.
             */
            app(\App\Domain\Marco\ConcederXp::class)->handle($colony->id, 'obra_concluida', 'fundacao', vezes: 5);

            // As cinco essenciais, já no nível 1, cada uma no seu slot do miolo (D-59). Só elas:
            // o resto dos 21 slots nasce vazio, e uma construção só ganha linha quando o colono
            // escolhe o slot dela.
            $colony->buildings()->createMany(
                array_map(
                    fn (string $tipo) => ['type' => $tipo, 'level' => 1, 'slot' => Slots::MIOLO[$tipo]],
                    Building::ESSENCIAIS,
                ),
            );

            $colony->resources()->createMany(
                array_map(fn (string $r) => [
                    'resource_type' => $r,
                    'amount' => 0,
                    // NULL: o GDD não define teto de armazenamento do slot principal.
                    'storage_cap' => null,
                ], Resource::daColonia()),
            );

            $furgao = $colony->vehicles()->create([
                'type' => 'furgao_de_comercio',
                'level' => 1,
                'status' => 'ocioso',
                'capacity' => Vehicle::CAPACIDADE['furgao_de_comercio'],
            ]);

            // §16.3: "todo veículo civil recebe registro obrigatório no Ministério dos Transportes
            // ao ser construído ou adquirido". O Furgão do kit não é exceção — ele é o primeiro
            // veículo do colono e o primeiro registro dele no Ministério (D-60).
            app(Placas::class)->registrar($furgao);

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

            $this->registrarMioloSubsidiado($colony, $agora);
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
     * As cinco essenciais nascem prontas, mas não nascem de graça no papel: quem as pagou foi o
     * Governo Central, e o ledger é a fonte auditável do jogo. Lança o custo do nível 1 de cada
     * uma como `subsidio_governo`, exatamente como `ColonyTick::concluir()` faz quando um upgrade
     * subsidiado termina. Sem isto, a emissão do miolo seria invisível na contabilidade.
     */
    private function registrarMioloSubsidiado(Colony $colony, $agora): void
    {
        $especificacoes = DB::table('building_specs')
            ->where('level', 1)
            ->whereIn('building_type', Building::ESSENCIAIS)
            ->get(['building_type', 'cost_json']);

        foreach ($especificacoes as $spec) {
            foreach (json_decode($spec->cost_json, true) as $recurso => $qtd) {
                Ledger::create([
                    'colony_id' => $colony->id,
                    'type' => 'subsidio_governo',
                    'amount' => $qtd,
                    'resource_type' => $recurso,
                    'ref' => "build:{$spec->building_type}:n1",
                    'created_at' => $agora,
                ]);
            }
        }
    }

    /**
     * Concede os recursos raros necessários para erguer uma vez, no nível 1, cada construção que
     * o colono AINDA precisa erguer — ou seja, as de progressão. A quantidade NÃO é digitada: é
     * somada de `building_specs`, que vem do GDD. Assim o kit acompanha o documento em vez de
     * virar constante mágica.
     *
     * Por que existe: várias construções exigem raros no nível 1, e as fontes de raros da
     * Temporada 1 (eventos, zonas profundas, contratos do governo) estão fora do MVP. Sem o
     * kit, o jogador para logo depois das cinco essenciais. É decisão de design, não do GDD.
     * Ver docs/decisoes.md D-17.
     *
     * Desde o D-59 a soma é sobre `PROGRESSAO`, não sobre `MVP`: as essenciais já nascem erguidas,
     * então os raros do nível 1 delas deixaram de ser necessários — dá-los seria dar duas vezes.
     * Uma cópia extra de uma repetível (segunda Mina) não é coberta: o kit ergue cada construção
     * **uma** vez, como sempre.
     */
    private function concederRarosDoKit(Colony $colony, $agora): void
    {
        // Somado em PHP de propósito. Um JOIN com json_extract exigiria concatenação de
        // string, e `||` é concat no SQLite mas OR no MariaDB: passaria nos testes e
        // quebraria em produção.
        $codigosRaros = ResourceType::where('tax_class', 'raro')->pluck('code')->flip();

        $especificacoes = DB::table('building_specs')
            ->where('level', 1)
            ->whereIn('building_type', Building::PROGRESSAO)
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
