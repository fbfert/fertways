<?php

namespace App\Domain\Trade;

use App\Domain\Logistics\MapaFertways;
use App\Exceptions\DomainRuleException;
use App\Models\Colony;
use App\Models\ResourceType;
use App\Models\TradeAgreement;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Propõe um Acordo de Troca (GDD §26.5): "recursos prometidos por cada lado, prazo de cumprimento,
 * participantes".
 *
 * **Nada é reservado.** O §26.5 é explícito em não usar escrow, e o D-40 confirma: propor não tira
 * um grama do estoque de ninguém. O acordo é uma promessa registrada, não uma garantia.
 *
 * Só vira evidência depois que a contraparte confirmar — ver `ConfirmarAcordo`.
 */
class ProporAcordo
{
    /** A chave do lado ainda sem dono, numa oferta aberta. Nenhuma colônia tem id 0. */
    public const LADO_ABERTO = 0;

    /**
     * @param  Colony|null  $contraparte  nulo = oferta aberta no mural (D-58)
     * @param  array<string,int>  $prometidoPeloProponente
     * @param  array<string,int>  $prometidoPelaContraparte
     */
    public function handle(
        Colony $proponente,
        ?Colony $contraparte,
        array $prometidoPeloProponente,
        array $prometidoPelaContraparte,
        CarbonInterface $prazo,
    ): TradeAgreement {
        if ($contraparte && $proponente->id === $contraparte->id) {
            throw new DomainRuleException('acordo_consigo_mesmo', 'Não se faz acordo com a própria colônia.');
        }

        // Um acordo em que um dos lados não promete nada é doação, não troca. O §26.5 fala em
        // "recursos prometidos por cada lado", no plural de lados.
        $this->validarPromessa($prometidoPeloProponente, 'sua');
        $this->validarPromessa($prometidoPelaContraparte, 'da contraparte');

        $this->validarPrazo($proponente, $contraparte, $prazo);

        /*
         * D-58, oferta aberta: sem contraparte, o outro lado dos termos ainda não tem dono. A chave
         * `0` o guarda — nenhuma colônia tem id 0 —, e `AceitarOferta` a substitui pelo id de quem
         * aceitar. Assim `terms_json` continua indexado por colônia, e nada mais no domínio
         * (entrega, inadimplência, reputação) precisa saber que existe oferta aberta.
         */
        $chaveDoOutro = (string) ($contraparte->id ?? self::LADO_ABERTO);

        $termos = [
            (string) $proponente->id => $prometidoPeloProponente,
            $chaveDoOutro => $prometidoPelaContraparte,
        ];

        return DB::transaction(fn () => TradeAgreement::create([
            'colony_a_id' => $proponente->id,
            'colony_b_id' => $contraparte?->id,
            'proposer_colony_id' => $proponente->id,
            'terms_json' => $termos,
            'delivered_json' => [(string) $proponente->id => [], $chaveDoOutro => []],
            'status' => 'proposto',
            'deadline_at' => $prazo,
            'value_micro' => $this->valorDeMercado($termos),
        ]));
    }

    /** @param array<string,int> $promessa */
    private function validarPromessa(array $promessa, string $lado): void
    {
        if ($promessa === []) {
            throw new DomainRuleException('promessa_vazia', "A promessa {$lado} está vazia. Um acordo tem dois lados.");
        }

        foreach ($promessa as $recurso => $qtd) {
            if (! is_int($qtd) || $qtd <= 0) {
                throw new DomainRuleException('quantidade_invalida', "Quantidade inválida para {$recurso}.");
            }

            // D-41: o cumprimento é entrega física, e o jogo só move recursos do catálogo por
            // veículo. Fert$ não é recurso e não tem como ser entregue — logo, não pode ser
            // prometido.
            if (! ResourceType::whereKey($recurso)->exists()) {
                throw new DomainRuleException('recurso_desconhecido', "Recurso inexistente: {$recurso}");
            }
        }
    }

    /**
     * D-42: o prazo tem de caber na viagem. O piso é o trecho do veículo mais lento entre as duas
     * colônias, mais 12 h de folga — sem isso, quem propõe fabricaria o calote do outro.
     *
     * Numa **oferta aberta** não há contraparte, logo não há distância: aqui só se exige o piso
     * teórico (distância zero, a folga sozinha). A regra do D-42 não afrouxa — ela é cobrada de
     * novo, para o par de verdade, na hora em que alguém aceita (`AceitarOferta`). Quem mora longe
     * demais para o prazo anunciado simplesmente não consegue aceitar.
     */
    private function validarPrazo(Colony $proponente, ?Colony $contraparte, CarbonInterface $prazo): void
    {
        $distancia = $contraparte
            ? MapaFertways::distancia($proponente->x, $proponente->y, $contraparte->x, $contraparte->y)
            : 0;

        $minimo = now()->addSeconds(AcordoSpecs::prazoMinimoSegundos($distancia));

        if ($prazo->lessThan($minimo)) {
            throw new DomainRuleException(
                'prazo_curto_demais',
                'O prazo não dá tempo de a carga chegar. O mínimo é '.$minimo->toDateTimeString().'.',
            );
        }
    }

    /**
     * Valor de mercado dos dois lados somados, em µF$ (§26.3). Congelado na proposta: o piso
     * anti-farming do D-43 não pode mudar de veredito porque um preço-base mudou depois.
     *
     * @param  array<string,array<string,int>>  $termos
     */
    private function valorDeMercado(array $termos): int
    {
        $precos = ResourceType::pluck('preco_base_micro', 'code');
        $total = 0;

        foreach ($termos as $promessa) {
            foreach ($promessa as $recurso => $qtd) {
                $total += $qtd * (int) ($precos[$recurso] ?? 0);
            }
        }

        return $total;
    }
}
