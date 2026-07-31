<?php

namespace App\Domain\Colony;

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
 * Tudo numa transação: cria a colônia, as **cinco essenciais e o Depósito Local já erguidos no
 * nível 1** (D-105) no miolo dos 22 slots, as linhas de recurso já com o kit inicial (D-85), a
 * frota do kit e o lançamento do saldo em Fert$. Se qualquer passo falhar, nada persiste — senão
 * um jogador poderia ficar com colônia sem recursos ou sem veículo, estados que nenhuma parte do
 * jogo sabe consertar.
 *
 * Regras aplicadas aqui:
 *  - Saldo, recursos e frota iniciais: `Domain\Colony\KitInicial` (D-85, editável pelo admin
 *    desde o D-92) — substitui de vez os 50 Fert$ do GDD, os raros calculados do D-17 e o kit
 *    fixo separado do D-57.
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
        // A legitimidade da célula (founder populável, periferia liberada — D-147; nunca Capital,
        // anel, reservado ou zona neutra) é conferida por quem CHAMA este método, não aqui — ver
        // `ColonyController::store()`. Este `handle()` é o primitivo de "criar a colônia já", usado
        // também por ferramentas internas (testes, scaffolding) que não passam pela ceremônia de
        // fundação de um jogador novo; `RealocarColonia` (D-61) já estabeleceu esse mesmo
        // precedente para colônias EXISTENTES — mover uma colônia não confere `podeFundar`.
        //
        // A colisão com colônia já instalada continua aqui: é invariante de dado (duas colônias
        // não cabem na mesma célula), não política de quem pode fundar onde. O `unique(x,y)` do
        // banco é a terceira trava, contra a corrida de dois colonos pedindo a mesma célula no
        // mesmo instante.
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
                'fert_micro' => KitInicial::fertMicro(),
                'last_tick_at' => $agora,
            ]);

            /*
             * Sobrevivente de verdade: as cinco essenciais valem 5 níveis de obra no ledger de XP
             * (D-75). Sem isto, quem funda nasceria com XP zero enquanto o vizinho ganhou pelos
             * mesmos cinco prédios — e o recálculo retroativo (que conta níveis DE PÉ) divergiria
             * do ledger vivo.
             */
            app(\App\Domain\Marco\ConcederXp::class)->handle($colony->id, 'obra_concluida', 'fundacao', vezes: 5);

            // As cinco essenciais, já no nível 1, cada uma no seu slot do miolo (D-59) — e o
            // Depósito Local (D-105, fora do GDD) junto, no slot 10, o centro exato da colmeia
            // desde o D-142: sem ele não há como ver os recursos, e um colono não pode nascer sem
            // enxergar o que tem no depósito. O resto dos slots nasce vazio, e uma construção só
            // ganha linha quando o colono escolhe.
            $colony->buildings()->createMany([
                ...array_map(
                    fn (string $tipo) => ['type' => $tipo, 'level' => 1, 'slot' => Slots::MIOLO[$tipo]],
                    Building::ESSENCIAIS,
                ),
                ...array_map(
                    fn (string $tipo) => ['type' => $tipo, 'level' => 1, 'slot' => Slots::DEPOSITO_LOCAL[$tipo]],
                    array_keys(Slots::DEPOSITO_LOCAL),
                ),
            ]);

            $recursosDoKit = KitInicial::recursos();

            $colony->resources()->createMany(
                array_map(fn (string $r) => [
                    'resource_type' => $r,
                    'amount' => $recursosDoKit[$r] ?? 0,
                    // NULL: o GDD não define teto de armazenamento do slot principal.
                    'storage_cap' => null,
                ], Resource::daColonia()),
            );

            // A frota do kit (D-85/D-92): quantos veículos de cada tipo, arbitrado pelo admin —
            // hoje 1 Furgão e 0 Caminhões, mas o painel pode mudar isso a qualquer momento.
            foreach (KitInicial::frota() as $tipo => $quantidade) {
                for ($i = 0; $i < $quantidade; $i++) {
                    $veiculo = $colony->vehicles()->create([
                        'type' => $tipo,
                        'level' => 1,
                        'status' => 'ocioso',
                        'capacity' => Vehicle::CAPACIDADE[$tipo],
                    ]);

                    // §16.3: "todo veículo civil recebe registro obrigatório no Ministério dos
                    // Transportes ao ser construído ou adquirido". Nenhum veículo do kit é exceção
                    // (D-60).
                    app(Placas::class)->registrar($veiculo);
                }
            }

            // O saldo entra como lançamento, não como número mudo na coluna: o ledger é a
            // fonte auditável, e `colonies.fert_micro` é só a projeção do saldo corrente.
            Ledger::create([
                'colony_id' => $colony->id,
                'type' => 'saldo_inicial',
                'amount' => $colony->fert_micro,
                'resource_type' => null,
                'ref' => 'onboarding:saldo_inicial',
                'created_at' => $agora,
            ]);

            $this->registrarNivelUmSubsidiado($colony, $agora);
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

            /*
             * Telemetria (A2.0.1). O ledger registra o kit e o saldo iniciais, mas não o ATO de
             * fundar — e é o ato que o funil de onboarding conta. Dentro da transação de propósito:
             * uma fundação que falhe no meio não deve deixar um evento de colônia que não existe.
             */
            app(\App\Domain\Telemetria\RegistrarEvento::class)
                ->handle('colonia_fundada', $user, $colony, ['x' => $x, 'y' => $y]);

            return $colony->fresh(['buildings', 'resources', 'vehicles']);
        });
    }

    /**
     * As cinco essenciais e o Depósito Local nascem prontos, mas não nascem de graça no papel:
     * quem os pagou foi o Governo Central, e o ledger é a fonte auditável do jogo. Lança o custo
     * do nível 1 de cada um como `subsidio_governo`, exatamente como `ColonyTick::concluir()` faz
     * quando um upgrade subsidiado termina. Sem isto, a emissão seria invisível na contabilidade.
     */
    private function registrarNivelUmSubsidiado(Colony $colony, $agora): void
    {
        $especificacoes = DB::table('building_specs')
            ->where('level', 1)
            ->whereIn('building_type', [...Building::ESSENCIAIS, ...array_keys(Slots::DEPOSITO_LOCAL)])
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
        foreach (KitInicial::recursos() as $codigo => $qtd) {
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
