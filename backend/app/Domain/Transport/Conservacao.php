<?php

namespace App\Domain\Transport;

use App\Domain\Endurance\EfeitosDaEndurance;
use App\Domain\Logistics\VeiculoSpecs;
use App\Models\Colony;
use App\Models\TransportSetting;
use App\Models\Vehicle;

/**
 * O estado de conservação de um veículo, e o que ele faz (D-60, fatia 2 — GDD §16.4).
 *
 * O §16.4 descreve cinco estágios e **não publica um único número**. Todos os que aparecem aqui
 * saem de `transport_settings`, que é o **painel do operador** — o §16 manda o Ministério
 * "configurar a curva de depreciação", "configurar o limite crítico" e "configurar a perda de vida
 * útil". Nada é constante de código. Os valores semeados são os que o usuário decidiu:
 *
 *      desgaste          0,5% por hora de USO ATIVO
 *      piso              25%
 *      manutenção        10% do custo do veículo, em recursos
 *      perda de teto     5 pontos por manutenção
 *
 * ---
 *
 * **A contradição deliberada com o §16.4, e onde ela está.** O documento nomeia um "**Bloqueio
 * operacional** — abaixo de um limite crítico, o veículo não pode iniciar nova missão sem
 * manutenção". O usuário decidiu que **o veículo nunca trava** (D-60): ele só fica mais lento e
 * carrega menos, para sempre.
 *
 * O "limite crítico" **não morreu — mudou de sentido**: virou o **piso** de desempenho. Um caminhão
 * a 5% de conservação ainda anda a 25% da velocidade e carrega 25% da carga. Assim nenhuma das seis
 * atribuições publicadas do painel do §16 se perde, e o colono nunca vê um patrimônio de 300 Fert$
 * parado à espera de peças. É o mesmo tipo de contradição consciente do D-32 (o tributo): **não a
 * "conserte" sem perguntar.**
 */
class Conservacao
{
    /** 100% em bps. A unidade de fração do projeto inteiro (as alíquotas do §8.3 são bps). */
    public const CHEIO = 10_000;

    public function __construct(private EfeitosDaEndurance $efeitosDaEndurance) {}

    public function config(): TransportSetting
    {
        return TransportSetting::singleton();
    }

    /**
     * A colônia dona do veículo, ou `null` para a frota do Governo (frete público, D-76) — que não
     * tem colônia nenhuma para ter comprado peça da Endurance.
     */
    private function colonia(Vehicle $veiculo): ?Colony
    {
        return $veiculo->colony_id !== null ? Colony::find($veiculo->colony_id) : null;
    }

    /**
     * O multiplicador que a conservação aplica a **velocidade e capacidade** (§16.4: "o veículo fica
     * mais lento e carrega menos progressivamente").
     *
     * Nunca abaixo do piso: é o que faz o veículo velho ser ruim em vez de morto.
     */
    public function desempenhoBps(Vehicle $veiculo): int
    {
        if (! $this->deprecia($veiculo)) {
            return self::CHEIO;
        }

        return max($this->config()->piso_desempenho_bps, (int) $veiculo->conservacao_bps);
    }

    /**
     * §16.4, primeira frase: "**Apenas** Furgão e Caminhão de Carga possuem depreciação ativa, já que
     * rodam continuamente em missões. Os demais veículos registrados não têm desgaste por uso."
     */
    public function deprecia(Vehicle $veiculo): bool
    {
        return in_array($veiculo->type, ['furgao_de_comercio', 'caminhao_de_carga'], true);
    }

    /**
     * Cobra o desgaste de um trecho concluído e acumula as horas de uso ativo.
     *
     * **O desgaste é por hora de uso ativo, não por tempo de posse** (§16.4, explícito). Um veículo
     * parado na doca não envelhece — só quem roda.
     *
     * **Duas viagens não contam como uso**, e as duas por simetria (D-60): a **entrega de fábrica**
     * (o caminhão novo vindo da Capital) e a **entrega de um usado vendido**. Em nenhuma delas o
     * veículo está trabalhando para o dono: ele está a caminho dele. Quem comprou não pode receber o
     * veículo mais gasto do que o anúncio dizia.
     */
    public function cobrarTrecho(Vehicle $veiculo, int $segundos): void
    {
        if ($segundos <= 0 || ! $this->deprecia($veiculo) || $this->viagemDeEntrega($veiculo)) {
            return;
        }

        $desgaste = intdiv($segundos * $this->config()->desgaste_bps_por_hora, 3_600);

        $veiculo->forceFill([
            'uso_ativo_seg' => (int) $veiculo->uso_ativo_seg + $segundos,
            // Nunca abaixo de zero. O desempenho já tem o seu piso; a conservação pode chegar a 0%,
            // e é isso que faz o teto de revenda de uma carcaça ser (quase) nada.
            'conservacao_bps' => max(0, (int) $veiculo->conservacao_bps - $desgaste),
        ])->save();
    }

    public function viagemDeEntrega(Vehicle $veiculo): bool
    {
        // 'frete' (D-76): o desgaste da frota PÚBLICA é absorvido pelo governo — sem isto, os dez
        // caminhões da Garagem exigiriam uma manutenção estatal que ninguém opera, e morreriam aos
        // poucos em silêncio. Leitura consciente, registrada; o §16.4 fala da frota do colono.
        return in_array($veiculo->trip_purpose, ['entrega_de_fabrica', 'venda_usado', 'frete'], true);
    }

    /**
     * A capacidade efetiva, já descontado o desgaste e somado o bônus de peça da Endurance
     * (D-135, `EfeitosDaEndurance::CAPACIDADE_VEICULO`). É ela que o despacho tem de respeitar.
     */
    public function capacidadeEfetiva(Vehicle $veiculo): int
    {
        $base = intdiv((int) $veiculo->capacity * $this->desempenhoBps($veiculo), self::CHEIO);

        $colonia = $this->colonia($veiculo);

        if (! $colonia) {
            return $base;
        }

        $bonus = $this->efeitosDaEndurance->bonusDeCapacidade($colonia, $veiculo->type);

        return EfeitosDaEndurance::aplicarBonus($base, $bonus);
    }

    /**
     * A duração de um trecho, **já mais lenta pelo desgaste** (§16.4: "sem manutenção, o veículo
     * fica mais lento e carrega menos progressivamente").
     *
     * Um veículo a 50% de conservação leva o dobro do tempo. É o inverso do desempenho, e não uma
     * segunda curva: velocidade e capacidade sofrem o **mesmo** multiplicador, que é o que o §16.4
     * descreve. `ceil` porque um trecho nunca encurta por arredondamento.
     *
     * **Toda a máquina de viagem tem de passar por aqui**, e não pelo `VeiculoSpecs` cru — senão a
     * carroça de 30% chegaria tão rápido quanto a nova, e metade do §16.4 seria decoração.
     */
    public function segundosDoTrecho(Vehicle $veiculo, int $distanciaSlots): int
    {
        $base = VeiculoSpecs::segundosDoTrecho($veiculo->type, $distanciaSlots);
        $comDesgaste = (int) ceil($base * self::CHEIO / $this->desempenhoBps($veiculo));

        // Bônus de velocidade da Endurance (D-135): reduz a duração, por cima do desgaste — nunca
        // some inteiramente porque o teto agregado (`EfeitosDaEndurance`) nunca chega a 100%.
        $colonia = $this->colonia($veiculo);
        $bonus = $colonia ? $this->efeitosDaEndurance->bonusDeVelocidade($colonia, $veiculo->type) : 0;

        if ($bonus <= 0) {
            return $comDesgaste;
        }

        return (int) ceil($comDesgaste * self::CHEIO / (self::CHEIO + $bonus));
    }

    /**
     * O teto de revenda em Fert$ (§16.4: cada manutenção "reduz o teto de valor de revenda", e o
     * estado "afeta diretamente o preço de venda no mercado de usados").
     *
     * **Arbitragem do assistente (D-60):** o GDD nunca diz em relação a quê. O **preço de fábrica**
     * é a única âncora que existe.
     *
     * **E o Furgão passou a ter uma (D-73).** Ele ficou sem teto do aditivo 14 do D-52 até
     * 2026-07-13 — o Ministério não o vende, logo não havia preço de fábrica — e era por esse buraco
     * que duas contas do mesmo jogador podiam **lavar Fert$**: um Furgão sucateado anunciado por
     * 5.000 Fert$ move dinheiro limpo pelo escrow, sem carga e sem tributo. A âncora era, até o
     * D-109, um **preço de referência do operador** (`furgao_preco_referencia_micro`, em
     * `transport_settings`) — porque não havia preço de fábrica de verdade.
     *
     * **Desde o D-109, há.** O Ministério passou a fabricar e vender o Furgão também — e a âncora
     * volta a ser a mesma regra do Caminhão: o preço de fábrica de `Ministerio::config()`, agora
     * por tipo. `furgao_preco_referencia_micro` fica no schema (não vale a pena uma migration só
     * para tirá-lo), mas deixa de ser lido aqui.
     *
     * @return int|null micro-Fert$; `null` = sem teto (Nave e Drone não passam pelo mercado de usados)
     */
    public function tetoDeRevendaMicro(Vehicle $veiculo): ?int
    {
        $referencia = in_array($veiculo->type, Ministerio::TIPOS, true)
            ? Ministerio::config($veiculo->type)['preco_micro']
            : null;

        if ($referencia === null) {
            return null;
        }

        return intdiv($referencia * (int) $veiculo->teto_conservacao_bps, self::CHEIO);
    }
}
