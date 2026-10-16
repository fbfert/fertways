<?php

namespace App\Domain\Espacoporto;

use App\Exceptions\DomainRuleException;
use App\Models\Colony;
use App\Models\PlanetaNpc;
use App\Models\Unit;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * A escolta e o seguro de uma viagem do Cargueiro (D-280).
 *
 * O GDD dá a Drakmoor "Alto — escolta opcional" e chama as duas de "mecânicas de temporada" (v3.2),
 * sem número nenhum. O que se fez aqui é recomendação minha, com os números no painel.
 *
 * ## A escolta: as Sentinelas da colônia
 *
 * Uma escolta do Governo, paga, seria só um desconto com outro nome. Com as Sentinelas da colônia, a
 * escolta tem um custo de verdade: elas ficam fora de casa a viagem inteira e não marcham para zona
 * nenhuma. É também o primeiro uso das Sentinelas fora da guerra.
 *
 * O poder é o ataque somado (Sentinela ferida vale menos, como no combate). A escolta plena
 * (`escolta_poder_pleno`) corta `escolta_reducao_max_bps` da chance de sinistro, e uma escolta menor
 * corta na proporção. Nunca zera: Drakmoor continua sendo "alto". As Sentinelas voltam inteiras, com
 * ou sem sinistro: o sinistro é da carga.
 *
 * ## O seguro: o Governo como seguradora
 *
 * Cobre exatamente o que o sinistro levaria: a fração `risco_perda_bps` das duas pernas. A venda
 * perdida vale o que o planeta pagaria, já sem o imposto. A encomenda perdida vale o que se pagou por
 * ela. O imposto de importação não volta: é tributo, não carga.
 *
 * O prêmio é a perda esperada (cobertura × chance, já com a escolta) mais `seguro_carregamento_bps`.
 * Na média, o seguro tira Fert$ da economia em vez de pôr: não é torneira. O Tesouro **reserva** a
 * cobertura inteira na partida e só vende o seguro se puder reservar. Na volta, ou a paga ao colono,
 * ou a devolve a si mesmo. Assim o Tesouro nunca promete o que não tem.
 */
class ProtecaoDaCarga
{
    public function __construct(
        private readonly Espacoporto $espacoporto,
        private readonly ImpostoInterplanetario $imposto,
    ) {}

    /**
     * As Sentinelas que vão de escolta: as mais fortes que estão em casa, inteiras ou feridas.
     *
     * @return Collection<int, Unit>
     */
    public function sentinelas(Colony $colonia, int $quantas, bool $travar = false): Collection
    {
        if ($quantas <= 0) {
            return collect();
        }

        $q = Unit::where('colony_id', $colonia->id)
            ->where('type', 'sentinela')
            ->where('status', 'casa')
            ->where('hp_bps', '>', 0)
            ->orderByDesc('level')->orderByDesc('hp_bps')->orderBy('id')
            ->limit($quantas);

        $unidades = ($travar ? $q->lockForUpdate() : $q)->get();

        if ($unidades->count() < $quantas) {
            throw new DomainRuleException(
                'escolta_insuficiente',
                "A escolta pede {$quantas} Sentinela(s) em casa. Você tem {$unidades->count()} disponível(is).",
            );
        }

        return $unidades;
    }

    /** @param  Collection<int, Unit>  $unidades */
    public function poder(Collection $unidades): int
    {
        return (int) $unidades->sum(fn (Unit $u) => $u->ataque());
    }

    /** A chance de sinistro, em bps, depois da escolta. */
    public function chance(int $base, int $poder): int
    {
        $c = $this->espacoporto->config();
        $pleno = max(1, (int) $c->escolta_poder_pleno);
        $corte = intdiv(min($poder, $pleno) * (int) $c->escolta_reducao_max_bps, $pleno);

        return $base - intdiv($base * $corte, 10_000);
    }

    /**
     * O que o seguro pagaria se o sinistro viesse: a fração perdida das duas pernas.
     *
     * @param  array<string, array{qtd:int, preco_micro:int}>  $venda
     * @param  array<string, array{qtd:int, preco_micro:int}>  $compra
     */
    public function cobertura(Colony $colonia, int $perdaBps, array $venda, array $compra, CarbonInterface $agora): int
    {
        $perdida = fn (array $perna) => array_map(
            fn ($p) => ['qtd' => intdiv((int) $p['qtd'] * $perdaBps, 10_000), 'preco_micro' => (int) $p['preco_micro']],
            $perna,
        );

        $vendaLiquida = 0;
        foreach ($this->imposto->sobre($colonia, $perdida($venda), $agora) as $linha) {
            $vendaLiquida += $linha['base'] - $linha['imposto'];
        }

        $compraPerdida = array_sum(array_map(fn ($p) => $p['qtd'] * $p['preco_micro'], $perdida($compra)));

        return $vendaLiquida + $compraPerdida;
    }

    /** A perda esperada, arredondada para cima, mais o carregamento. */
    public function premio(int $cobertura, int $chanceBps): int
    {
        $esperada = intdiv($cobertura * $chanceBps + 9_999, 10_000);
        $fator = 10_000 + (int) $this->espacoporto->config()->seguro_carregamento_bps;

        return intdiv($esperada * $fator + 9_999, 10_000);
    }

    /**
     * Tudo o que a escolta e o seguro mudam numa viagem, sem tocar em nada.
     *
     * @param  array{seguro?: bool, escolta?: int}  $opcoes
     * @return array{risco_chance_bps:int, escolta: Collection<int, Unit>, escolta_poder:int, seguro_cobertura_micro:int, seguro_premio_micro:int}
     */
    public function cotar(Colony $colonia, PlanetaNpc $planeta, array $venda, array $compra, array $opcoes, CarbonInterface $agora, bool $travar = false): array
    {
        $base = (int) $planeta->risco_chance_bps;
        $querEscolta = (int) ($opcoes['escolta'] ?? 0);
        $querSeguro = (bool) ($opcoes['seguro'] ?? false);

        if (($querEscolta > 0 || $querSeguro) && ($base <= 0 || (int) $planeta->risco_perda_bps <= 0)) {
            throw new DomainRuleException(
                'sem_risco',
                "A rota para {$planeta->nome} não tem risco de sinistro: não há o que escoltar nem segurar.",
            );
        }

        $escolta = $this->sentinelas($colonia, $querEscolta, $travar);
        $poder = $this->poder($escolta);
        $chance = $this->chance($base, $poder);

        $cobertura = $querSeguro ? $this->cobertura($colonia, (int) $planeta->risco_perda_bps, $venda, $compra, $agora) : 0;

        return [
            'risco_chance_bps' => $chance,
            'escolta' => $escolta,
            'escolta_poder' => $poder,
            'seguro_cobertura_micro' => $cobertura,
            'seguro_premio_micro' => $cobertura > 0 ? $this->premio($cobertura, $chance) : 0,
        ];
    }
}
