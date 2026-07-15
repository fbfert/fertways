<?php

namespace App\Domain\Colony;

use App\Domain\Logistics\MapaFertways;
use App\Domain\Transport\Placas;
use App\Exceptions\DomainRuleException;
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
 * Tudo numa transação: cria a colônia, as **cinco essenciais já erguidas no nível 1** no miolo
 * dos 21 slots, as linhas de recurso já com o kit inicial (D-85), o Furgão do kit inicial e o
 * lançamento do saldo em Fert$. Se qualquer passo falhar, nada persiste — senão um jogador
 * poderia ficar com colônia sem recursos ou sem veículo, estados que nenhuma parte do jogo sabe
 * consertar.
 *
 * Regras aplicadas aqui:
 *  - Saldo e recursos iniciais: `Domain\Colony\KitInicial` (D-85) — substitui de vez os 50 Fert$
 *    do GDD, os raros calculados do D-17 e o kit fixo separado do D-57.
 *  - Furgão de Comércio no kit inicial ("todo colono começa com um" — GDD).
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
                    'amount' => KitInicial::RECURSOS[$r] ?? 0,
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
            $this->lancarKitInicial($colony, $agora);

            /*
             * As 5 missões de tutoria EXISTEM desde o D-78 — e o auto-completar continua, agora
             * por DECISÃO e não por stub: o usuário arbitrou que a tutoria recompensa mas não
             * trava o subsídio (contradição consciente com o §03, que diz "mediante conclusão
             * da tutoria"; registrada no D-78 — não a "conserte"). O bilhete do D-18 morreu.
             */
            $user->forceFill(['tutorial_completed_at' => $agora])->save();

            // A mão de missões da tutoria (§06: "5 missões — dias 1 a 3"). Elas pagam; não travam.
            app(\App\Domain\Missoes\Atribuir::class)->tutoria($colony);

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
     * O ledger do kit inicial (D-85): os recursos já foram gravados na criação das linhas de
     * `resources`, acima — aqui só se audita, um lançamento por recurso que o kit de fato deu
     * (os zerados, como Fungo Bioluminescente e Nióbio Alienígena, não geram linha: não houve
     * concessão nenhuma para auditar).
     */
    private function lancarKitInicial(Colony $colony, $agora): void
    {
        foreach (KitInicial::RECURSOS as $codigo => $qtd) {
            if ($qtd <= 0) {
                continue;
            }

            Ledger::create([
                'colony_id' => $colony->id,
                'type' => 'kit_inicial',
                'amount' => $qtd,
                'resource_type' => $codigo,
                'ref' => 'onboarding:kit_inicial',
                'created_at' => $agora,
            ]);
        }
    }
}
