<?php

namespace App\Domain\Espacoporto;

use App\Domain\Treasury\Tesouro;
use App\Exceptions\DomainRuleException;
use App\Models\Colony;
use App\Models\ContratoDeRota;
use App\Models\Ledger;
use App\Models\PlanetaNpc;
use Illuminate\Support\Facades\DB;

/**
 * Aluga o Cargueiro do Governo para uma ida a um planeta NPC (D-262).
 *
 * *"Colonos alugam o uso do cargueiro para viajar aos 5 planetas NPC — não constroem o próprio"*
 * (§16.2). O contrato tem duas pernas: o que o colono **leva para vender** (só o que o planeta compra)
 * e o que ele **encomenda** (só o que o planeta vende). O preço de cada uma é **travado agora**: o
 * operador que muda o preço amanhã não reescreve contrato que já saiu.
 *
 * ## Para onde vai o dinheiro
 *
 * - o **aluguel** é do Governo, operador do Cargueiro — vai ao Tesouro;
 * - o que se **paga ao planeta** pela encomenda sai da economia de Fertways;
 * - o que o planeta **paga ao colono**, na volta, entra nela.
 *
 * As duas últimas atravessam a fronteira do mundo, e é por isso que os preços são do operador e nascem
 * vazios: um preço de venda alto demais é uma torneira de Fert$ que nenhum outro sistema tem.
 *
 * ⚠️ **Sem imposto sobre a carga interplanetária**: o §25 cobra na entrega física entre donos do
 * planeta, e nenhum documento diz o que vale para um planeta NPC. Lacuna, não zero escolhido.
 */
class FecharContratoDeRota
{
    public function __construct(private readonly Espacoporto $espacoporto, private readonly Tesouro $tesouro) {}

    /**
     * @param  array<string,int>  $venda  recurso => quantidade que o colono leva
     * @param  array<string,int>  $compra  recurso => quantidade que o colono encomenda
     */
    public function handle(Colony $colony, PlanetaNpc $planeta, array $venda, array $compra): ContratoDeRota
    {
        return DB::transaction(function () use ($colony, $planeta, $venda, $compra) {
            $colonia = Colony::whereKey($colony->id)->lockForUpdate()->firstOrFail();
            $config = $this->espacoporto->config();

            if (($motivo = $this->espacoporto->motivoFechado()) !== null) {
                throw new DomainRuleException('espacoporto_fechado', $motivo);
            }

            if (! $planeta->fresh()->aberto) {
                throw new DomainRuleException('planeta_fechado', "As rotas para {$planeta->nome} não abriram.");
            }

            $portao = $this->espacoporto->portao($colonia);
            if (! $portao['pode']) {
                throw new DomainRuleException(
                    'portao_do_cargueiro',
                    "O Cargueiro abre no marco {$portao['marco_exigido']}, com {$portao['dias_exigidos']} dias de colônia (§05). "
                    ."Você está no marco {$portao['marco']}, com {$portao['dias']} dia(s).",
                );
            }

            // O Governo é o operador exclusivo (§16.2): a frota é finita, e os contratos também.
            $emViagem = ContratoDeRota::where('status', ContratoDeRota::EM_VIAGEM)->lockForUpdate()->count();
            if ($emViagem >= (int) $config->cargueiros) {
                throw new DomainRuleException('sem_cargueiro_livre', 'Todos os Cargueiros do Governo estão em viagem. Volte quando um pousar.');
            }

            $venda = $this->limpar($venda);
            $compra = $this->limpar($compra);

            if ($venda === [] && $compra === []) {
                throw new DomainRuleException('contrato_vazio', 'Leve alguma coisa para vender, ou encomende alguma coisa.');
            }

            $precos = $planeta->precos()->get()->keyBy('resource_type');
            $pernaVenda = $this->precificar($venda, $precos, 'compra_micro', 'planeta_nao_compra', "{$planeta->nome} não compra");
            $pernaCompra = $this->precificar($compra, $precos, 'venda_micro', 'planeta_nao_vende', "{$planeta->nome} não vende");

            $capacidade = (int) $config->capacidade_unidades;
            if (array_sum($venda) > $capacidade || array_sum($compra) > $capacidade) {
                throw new DomainRuleException(
                    'carga_acima_da_capacidade',
                    "O Cargueiro leva {$capacidade} unidades em cada perna da viagem.",
                );
            }

            $estoque = $colonia->resources()->lockForUpdate()->get()->keyBy('resource_type');
            foreach ($venda as $recurso => $qtd) {
                $tem = (int) ($estoque[$recurso]->amount ?? 0);
                if ($tem < $qtd) {
                    throw new DomainRuleException('recurso_insuficiente', "Para levar {$qtd} de {$recurso}, falta: você tem {$tem}.");
                }
            }

            $aluguel = (int) $config->aluguel_micro;
            $custoDaCompra = array_sum(array_map(fn ($p) => $p['qtd'] * $p['preco_micro'], $pernaCompra));

            if ((int) $colonia->fert_micro < $aluguel + $custoDaCompra) {
                throw new DomainRuleException('fert_insuficiente', 'Falta Fert$ para o aluguel e a encomenda.');
            }

            $agora = now();
            $ref = "espacoporto:{$planeta->chave}";

            foreach ($venda as $recurso => $qtd) {
                $estoque[$recurso]->decrement('amount', $qtd);
                Ledger::create([
                    'colony_id' => $colonia->id, 'type' => 'espacoporto_venda', 'amount' => -$qtd,
                    'resource_type' => $recurso, 'ref' => $ref, 'created_at' => $agora,
                ]);
            }

            $colonia->decrement('fert_micro', $aluguel + $custoDaCompra);

            if ($aluguel > 0) {
                $this->tesouro->creditarFert($aluguel, "espacoporto_aluguel:{$colonia->id}");
                Ledger::create([
                    'colony_id' => $colonia->id, 'type' => 'espacoporto_aluguel', 'amount' => -$aluguel,
                    'resource_type' => null, 'ref' => $ref, 'created_at' => $agora,
                ]);
            }

            if ($custoDaCompra > 0) {
                Ledger::create([
                    'colony_id' => $colonia->id, 'type' => 'espacoporto_compra', 'amount' => -$custoDaCompra,
                    'resource_type' => null, 'ref' => $ref, 'created_at' => $agora,
                ]);
            }

            return ContratoDeRota::create([
                'colony_id' => $colonia->id,
                'planeta_npc_id' => $planeta->id,
                'status' => ContratoDeRota::EM_VIAGEM,
                'venda' => $pernaVenda,
                'compra' => $pernaCompra,
                'aluguel_micro' => $aluguel,
                'parte_em' => $agora,
                // A distância do planeta é o contrato inteiro: o GDD dá um tempo por planeta, e não
                // um de ida e outro de volta. "Possui previsão de chegada" (v3.2 §11).
                'chega_em' => $agora->copy()->addMinutes((int) $planeta->distancia_minutos),
            ]);
        });
    }

    /** @return array<string,int> só quantidades positivas, inteiras */
    private function limpar(array $pedido): array
    {
        $limpo = [];
        foreach ($pedido as $recurso => $qtd) {
            if ((int) $qtd > 0) {
                $limpo[(string) $recurso] = (int) $qtd;
            }
        }

        return $limpo;
    }

    /** @return array<string, array{qtd:int, preco_micro:int}> */
    private function precificar(array $pedido, $precos, string $coluna, string $codigo, string $frase): array
    {
        $perna = [];
        foreach ($pedido as $recurso => $qtd) {
            $preco = $precos[$recurso]->{$coluna} ?? null;
            if ($preco === null) {
                throw new DomainRuleException($codigo, "{$frase} {$recurso}.");
            }
            $perna[$recurso] = ['qtd' => $qtd, 'preco_micro' => (int) $preco];
        }

        return $perna;
    }
}
