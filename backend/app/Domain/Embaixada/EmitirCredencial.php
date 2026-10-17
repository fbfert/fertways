<?php

namespace App\Domain\Embaixada;

use App\Domain\Espacoporto\Espacoporto;
use App\Domain\Treasury\Tesouro;
use App\Exceptions\DomainRuleException;
use App\Models\Colony;
use App\Models\CredencialComercial;
use App\Models\Ledger;
use App\Models\PlanetaNpc;
use Illuminate\Support\Facades\DB;

/**
 * A Embaixada Interplanetária emite a credencial comercial (D-289).
 *
 * ## Por que a Embaixada faz isto
 *
 * O GDD reserva o slot 9 da Capital para a "Embaixada Interplanetária" e não diz mais nada: nem função,
 * nem número. O que o jogo já tem de interplanetário é o Espaçoporto (D-262 a D-283): cinco planetas
 * NPC, cada um com preço, imposto, risco, portão e demanda. Uma embaixada é a relação formal com
 * outro governo, e a relação que o colono tem com esses planetas é comercial. A credencial é isso: o
 * reconhecimento, pago e com prazo, de que esta colônia comercia com aquele planeta — e o planeta, em
 * troca, cobra menos imposto da carga dela.
 *
 * ## Os números (meus, no painel)
 *
 * - **preço por planeta** (`planetas_npc.credencial_preco_micro`): 40 F$ de partida; vazio, o planeta
 *   não emite;
 * - **desconto** (`planetas_npc.credencial_desconto_bps`): 50% da alíquota, nas duas pernas;
 * - **prazo** (`espacoporto_settings.credencial_dias`): 30 dias.
 *
 * O preço vai ao Tesouro: é um dreno de Fert$, como o aluguel do Cargueiro. A conta que o colono faz
 * é a do volume: com a demanda finita do D-283 (100 F$/dia por planeta, somando o servidor), a
 * credencial só se paga para quem comercia com aquele planeta com frequência.
 *
 * ## Renovar
 *
 * Só nos últimos 7 dias da credencial, e o prazo novo soma ao que resta: quem renova cedo não perde
 * dia nenhum, e ninguém empilha um ano de credencial no preço de hoje.
 */
class EmitirCredencial
{
    public const RENOVA_NOS_ULTIMOS_DIAS = 7;

    public function __construct(
        private readonly Espacoporto $espacoporto,
        private readonly Credenciais $credenciais,
        private readonly Tesouro $tesouro,
    ) {}

    public function handle(Colony $colony, PlanetaNpc $planeta): CredencialComercial
    {
        $preco = $planeta->credencial_preco_micro;

        if ($preco === null) {
            throw new DomainRuleException('planeta_sem_credencial', "{$planeta->nome} não emite credencial comercial.");
        }

        $portao = $this->espacoporto->portao($colony, $planeta);
        if (! $portao['pode']) {
            throw new DomainRuleException('portao_fechado', "{$planeta->nome} ainda não recebe a sua colônia. {$portao['falta']}");
        }

        return DB::transaction(function () use ($colony, $planeta, $preco) {
            $colonia = Colony::whereKey($colony->id)->lockForUpdate()->firstOrFail();
            $agora = now();
            $dias = (int) (DB::table('espacoporto_settings')->value('credencial_dias') ?: 30);

            $atual = $this->credenciais->vigente($colonia, $planeta->id, $agora);
            if ($atual && $atual->valida_ate->copy()->subDays(self::RENOVA_NOS_ULTIMOS_DIAS)->isFuture()) {
                throw new DomainRuleException(
                    'credencial_vigente',
                    "A sua credencial com {$planeta->nome} vale até {$atual->valida_ate->format('d/m H:i')}. A renovação abre nos últimos "
                        .self::RENOVA_NOS_ULTIMOS_DIAS.' dias.',
                );
            }

            $afetadas = Colony::whereKey($colonia->id)->where('fert_micro', '>=', $preco)->decrement('fert_micro', (int) $preco);
            if ($afetadas === 0) {
                throw new DomainRuleException('sem_fert', 'Falta Fert$ para a credencial de '.number_format($preco / Colony::MICRO_POR_FERT, 2, ',', '.').' F$.');
            }

            $credencial = CredencialComercial::create([
                'colony_id' => $colonia->id,
                'planeta_npc_id' => $planeta->id,
                'valida_ate' => ($atual ? $atual->valida_ate->copy() : $agora->copy())->addDays($dias),
                'preco_pago_micro' => (int) $preco,
                'desconto_bps' => (int) $planeta->credencial_desconto_bps,
            ]);

            $this->tesouro->creditarFert((int) $preco, "embaixada_credencial:{$credencial->id}");

            Ledger::create([
                'colony_id' => $colonia->id,
                'type' => 'embaixada_credencial',
                'amount' => -(int) $preco,
                'resource_type' => null,
                'ref' => "credencial:{$credencial->id}:{$planeta->chave}",
                'created_at' => $agora,
            ]);

            return $credencial;
        });
    }
}
