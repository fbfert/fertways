<?php

namespace App\Domain\Espacoporto;

use App\Domain\Treasury\Tesouro;
use App\Exceptions\DomainRuleException;
use App\Models\Colony;
use App\Models\ContratoDeRota;
use App\Models\Ledger;
use App\Models\PlanetaNpc;
use App\Models\Unit;
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
 * ## O imposto (D-269)
 *
 * A encomenda paga, na partida, o imposto de importação — a alíquota do recurso sobre o valor, a mesma
 * régua do Mercado (ver `ImpostoInterplanetario`). A venda paga o dela na volta, sobre o que chegou.
 */
class FecharContratoDeRota
{
    public function __construct(
        private readonly Espacoporto $espacoporto,
        private readonly Tesouro $tesouro,
        private readonly ImpostoInterplanetario $imposto,
        private readonly ProtecaoDaCarga $protecao,
    ) {}

    /**
     * @param  array<string,int>  $venda  recurso => quantidade que o colono leva
     * @param  array<string,int>  $compra  recurso => quantidade que o colono encomenda
     * @param  array{seguro?: bool, escolta?: int}  $opcoes  o seguro e quantas Sentinelas vão de escolta (D-280)
     */
    public function handle(Colony $colony, PlanetaNpc $planeta, array $venda, array $compra, array $opcoes = []): ContratoDeRota
    {
        return DB::transaction(function () use ($colony, $planeta, $venda, $compra, $opcoes) {
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

            // D-282: nas primeiras 48 h de uma rota descoberta, cada colônia viaja até N vezes para lá
            // ("limites de ordem evitam monopólio de lançamento", aditivo v3.4 §2.2).
            $planetaAgora = $planeta->fresh();
            if ($planetaAgora->abertura_ate && $planetaAgora->abertura_ate > now()) {
                $limite = (int) (DB::table('gagarin_settings')->value('viagens_na_abertura') ?? 1);
                $feitas = ContratoDeRota::where('colony_id', $colonia->id)->where('planeta_npc_id', $planeta->id)->count();
                if ($feitas >= $limite) {
                    throw new DomainRuleException(
                        'limite_da_abertura',
                        "{$planeta->nome} acabou de abrir: até ".$planetaAgora->abertura_ate->format('d/m H:i')
                        ." cada colônia faz {$limite} viagem(ns) para lá, para ninguém monopolizar a abertura.",
                    );
                }
            }

            // D-269: o portão do planeta, além do do Cargueiro.
            $doPlaneta = $this->espacoporto->portao($colonia, $planeta);
            if (! $doPlaneta['pode']) {
                throw new DomainRuleException('portao_do_planeta', "{$planeta->nome}: {$doPlaneta['falta']}");
            }

            // D-283: um Cargueiro no ar por colônia. A frota do Governo é de todos; sem esta trava, uma
            // colônia com caixa ocupava os dois sem parar.
            if (ContratoDeRota::where('colony_id', $colonia->id)->where('status', ContratoDeRota::EM_VIAGEM)->exists()) {
                throw new DomainRuleException(
                    'um_cargueiro_por_colonia',
                    'Você já tem um Cargueiro no ar. A frota do Governo é de todos: espere ele pousar para alugar outro.',
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

            /*
             * D-283: a demanda do planeta é finita. Sem teto, 10.000 Componentes Eletrônicos rendiam
             * ~8.900 F$ numa viagem a Auryn — 25 vezes o que o D-263 estimou para o servidor inteiro
             * num dia. A linha do planeta é travada para dois contratos não gastarem a mesma demanda.
             */
            $planetaTravado = PlanetaNpc::whereKey($planeta->id)->lockForUpdate()->first();
            $valorDaVenda = array_sum(array_map(fn ($p) => $p['qtd'] * $p['preco_micro'], $pernaVenda));
            $restante = $this->espacoporto->demandaRestante($planetaTravado);

            if ($valorDaVenda > 0 && $restante !== null && $valorDaVenda > $restante) {
                throw new DomainRuleException(
                    'demanda_do_planeta',
                    "{$planeta->nome} só compra mais ".number_format($restante / Colony::MICRO_POR_FERT, 2, ',', '.')
                    .' Fert$ hoje, somando todos os colonos, e a sua carga vale '
                    .number_format($valorDaVenda / Colony::MICRO_POR_FERT, 2, ',', '.').' Fert$. Leve menos, ou volte depois das 07h.',
                );
            }

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
            $agora = now();
            $importacao = $this->imposto->sobre($colonia, $pernaCompra, $agora, $planeta->id);
            $impostoDaCompra = array_sum(array_column($importacao, 'imposto'));

            // D-280: a escolta e o seguro, com a chance de sinistro que fica travada no contrato.
            $protecao = $this->protecao->cotar($colonia, $planeta->fresh(), $pernaVenda, $pernaCompra, $opcoes, $agora, travar: true);
            $premio = $protecao['seguro_premio_micro'];
            $cobertura = $protecao['seguro_cobertura_micro'];

            if ((int) $colonia->fert_micro < $aluguel + $custoDaCompra + $impostoDaCompra + $premio) {
                throw new DomainRuleException(
                    'fert_insuficiente',
                    $premio > 0
                        ? 'Falta Fert$ para o aluguel, a encomenda, o imposto de importação e o seguro.'
                        : 'Falta Fert$ para o aluguel, a encomenda e o imposto de importação.',
                );
            }

            // O Tesouro reserva a indenização inteira antes de vender o seguro: nunca promete o que não tem.
            if ($cobertura > 0 && ! $this->tesouro->debitar(Tesouro::FERT, $cobertura, "espacoporto_seguro_reserva:{$colonia->id}")) {
                throw new DomainRuleException(
                    'seguro_sem_lastro',
                    'O Tesouro não tem Fert$ para garantir este seguro agora. Viaje sem seguro, ou leve menos carga.',
                );
            }

            $ref = "espacoporto:{$planeta->chave}";

            foreach ($venda as $recurso => $qtd) {
                $estoque[$recurso]->decrement('amount', $qtd);
                Ledger::create([
                    'colony_id' => $colonia->id, 'type' => 'espacoporto_venda', 'amount' => -$qtd,
                    'resource_type' => $recurso, 'ref' => $ref, 'created_at' => $agora,
                ]);
            }

            $colonia->decrement('fert_micro', $aluguel + $custoDaCompra + $impostoDaCompra + $premio);

            if ($premio > 0) {
                $this->tesouro->creditarFert($premio, "espacoporto_seguro_premio:{$colonia->id}");
                Ledger::create([
                    'colony_id' => $colonia->id, 'type' => 'espacoporto_seguro', 'amount' => -$premio,
                    'resource_type' => null, 'ref' => $ref, 'created_at' => $agora,
                ]);
            }

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

            $contrato = ContratoDeRota::create([
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
                'imposto_importacao_micro' => $impostoDaCompra,
                'risco_chance_bps' => $protecao['risco_chance_bps'],
                // D-284: a perda também fica travada — a cobertura do seguro foi calculada com ela.
                'risco_perda_bps' => (int) $planeta->fresh()->risco_perda_bps,
                'seguro_premio_micro' => $premio,
                'seguro_cobertura_micro' => $cobertura,
                'escolta_unit_ids' => $protecao['escolta']->isEmpty() ? null : $protecao['escolta']->pluck('id')->all(),
                'escolta_poder' => $protecao['escolta_poder'],
            ]);

            if ($protecao['escolta']->isNotEmpty()) {
                Unit::whereIn('id', $protecao['escolta']->pluck('id'))->update(['status' => 'escolta']);
            }

            if ($impostoDaCompra > 0) {
                $this->tesouro->creditarFert($impostoDaCompra, "espacoporto_importacao:{$contrato->id}");
                Ledger::create([
                    'colony_id' => $colonia->id, 'type' => 'espacoporto_imposto', 'amount' => -$impostoDaCompra,
                    'resource_type' => null, 'ref' => $ref, 'created_at' => $agora,
                ]);
            }

            foreach ($importacao as $recurso => $linha) {
                $this->imposto->registrar('espacoporto_importacao', "rota:{$contrato->id}:compra:{$recurso}", $colonia, $recurso, $linha, $agora);
            }

            return $contrato;
        });
    }

    /**
     * O que a escolta e o seguro fariam nesta viagem, sem fechar nada (D-280). Usa a mesma conta do
     * contrato, para a tela nunca prometer um prêmio diferente do que se cobra.
     *
     * @param  array{seguro?: bool, escolta?: int}  $opcoes
     * @return array{risco_chance_bps:int, escolta_sentinelas:int, escolta_poder:int, seguro_cobertura_micro:int, seguro_premio_micro:int}
     */
    public function cotar(Colony $colonia, PlanetaNpc $planeta, array $venda, array $compra, array $opcoes): array
    {
        $precos = $planeta->precos()->get()->keyBy('resource_type');
        $pernaVenda = $this->precificar($this->limpar($venda), $precos, 'compra_micro', 'planeta_nao_compra', "{$planeta->nome} não compra");
        $pernaCompra = $this->precificar($this->limpar($compra), $precos, 'venda_micro', 'planeta_nao_vende', "{$planeta->nome} não vende");

        $p = $this->protecao->cotar($colonia, $planeta, $pernaVenda, $pernaCompra, $opcoes, now());

        return [
            'risco_chance_bps' => $p['risco_chance_bps'],
            'escolta_sentinelas' => $p['escolta']->count(),
            'escolta_poder' => $p['escolta_poder'],
            'seguro_cobertura_micro' => $p['seguro_cobertura_micro'],
            'seguro_premio_micro' => $p['seguro_premio_micro'],
        ];
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
