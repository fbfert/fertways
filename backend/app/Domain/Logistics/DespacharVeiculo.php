<?php

namespace App\Domain\Logistics;

use App\Domain\Admin\Suspender;
use App\Domain\Market\Deposito;
use App\Domain\Trade\AcessoAoMercado;
use App\Exceptions\DomainRuleException;
use App\Domain\Transport\Conservacao;
use App\Models\Colony;
use App\Models\Ledger;
use App\Models\MarketAccount;
use App\Models\NeutralZone;
use App\Models\Punishment;
use App\Models\ResourceType;
use App\Models\TradeAgreement;
use App\Models\Vehicle;
use App\Models\VehicleListing;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Despacha um veículo com carga (GDD §25.3, "Toda Movimentação de Recurso Exige Veículo Físico").
 *
 * A carga sai do estoque **no despacho**, não na entrega: o recurso está fisicamente no veículo
 * durante o trajeto. Se ele nunca chegasse, o recurso não deveria reaparecer na origem — é
 * exatamente isso que torna o calote do comércio informal "real e visível" (§25.7).
 *
 * A energia também é debitada no despacho. O GDD não diz quando cobrar; cobrar na saída impede que
 * um veículo parta sem ter como voltar (D-30). Desde o D-65, porém, **cada perna paga a sua**: nem
 * toda viagem volta. Quem leva carga ao depósito da Capital **fica estacionado lá** (uma perna),
 * e quem sai do Pátio para outro colono entrega e **segue para casa** (duas pernas, de distâncias
 * diferentes). O que decide é a lista de pernas que a viagem vai rodar, e nada mais.
 *
 * `return_distance_slots` nulo é o que diz "só de ida": o veículo termina no destino e fica lá.
 */
class DespacharVeiculo
{
    // A conservação entra no despacho desde o D-60: ela decide a capacidade efetiva e a velocidade
    // do veículo, que o §16.4 manda encolherem com o desgaste.
    public function __construct(private readonly Conservacao $conservacao) {}

    /**
     * Despacha de onde o veículo estiver: da colônia, ou do Pátio da Capital (D-65).
     *
     * A bifurcação é aqui, e não no controller, porque "de onde ele sai" é regra de domínio: muda
     * a origem geográfica, muda de onde a carga é debitada (estoque ou depósito) e muda quais
     * pernas a viagem roda.
     */
    public function handle(Colony $origem, Vehicle $veiculo, string $destinoTipo, ?int $destinoId, array $carga, ?int $acordoId = null): Vehicle
    {
        $this->validarVeiculo($origem, $veiculo);

        if ($veiculo->noPatio()) {
            return $this->doPatio($origem, $veiculo, $destinoTipo, $destinoId, $carga, $acordoId);
        }

        $destino = $this->resolverDestino($origem, $destinoTipo, $destinoId);
        $this->validarCarga($veiculo, $carga);

        /*
         * §9.4: "Restrição comercial — jogador não pode enviar recursos por X dias." É a punição do
         * Ministério que morde toda saída de carga, para a doca ou para outro colono. Sete dias,
         * pelo D-49.
         *
         * Vem antes do Mercado: quem está sob restrição não deposita nem que a Confiança Comercial
         * esteja intacta, e a mensagem certa é a da punição, não a do índice.
         */
        $this->exigirSemRestricaoComercial($origem);

        // Depositar na doca é acessar o Mercado Central (§25.8). Quem está bloqueado pelo §26.2
        // não põe carga lá dentro.
        if ($destinoTipo === 'mercado_central') {
            AcessoAoMercado::exigir($origem);

            /*
             * D-58: o depósito tem teto, e o colono não deve descobri-lo depois de perder uma
             * viagem inteira. Barra-se aqui, pelo **líquido** — que é o que de fato vai entrar,
             * já descontado o tributo da entrega.
             */
            foreach ($carga as $recurso => $qtd) {
                $bps = (int) (ResourceType::find($recurso)?->tax_bps ?? 0);
                $liquido = (int) $qtd - intdiv((int) $qtd * $bps, 10_000);

                Deposito::exigirEspaco($origem->id, $recurso, $liquido);
            }
        }

        $acordo = $this->resolverAcordo($origem, $destinoTipo, $destino['id'], $acordoId);

        $ida = $this->distancia($origem->x, $origem->y, $destino['x'], $destino['y']);

        /*
         * D-65: levar carga ao depósito é uma viagem **só de ida**. O veículo descarrega e fica
         * estacionado no Pátio — foi a decisão do usuário —, então não há volta a rodar nem volta
         * a pagar. Toda outra viagem que sai de casa continua sendo ida e volta, como no D-30.
         */
        $volta = $destinoTipo === 'mercado_central' ? null : $ida;
        $energia = VeiculoSpecs::energiaDasPernas($veiculo->type, array_filter([$ida, $volta]));

        return DB::transaction(function () use ($origem, $veiculo, $destinoTipo, $destino, $carga, $ida, $volta, $energia, $acordo) {
            $agora = now();
            $ref = $this->partir($origem, $veiculo, $energia, $agora);

            foreach ($carga as $recurso => $qtd) {
                $this->debitarEstoque($origem, $recurso, $qtd, 'transferencia', $ref);
            }

            return $this->emRota($veiculo, $destinoTipo, $destino['id'], 'entrega', $ida, $volta, $carga, $agora, $acordo?->id);
        });
    }

    /**
     * Despacho a partir do Pátio da Capital (D-65).
     *
     * A carga sai do **depósito** do colono, não do estoque da colônia: o veículo está na Capital,
     * e é o depósito que está ao lado dele. A origem geográfica é a Capital.
     *
     * Dois destinos, e eles decidem as pernas:
     *
     *  - **a sua própria colônia**: viagem só de ida. O veículo chega, entrega (com tributo, como
     *    qualquer entrega física — §25.2) e **fica em casa**. É a retirada do §25.8 feita ao
     *    contrário: em vez de mandar um veículo de casa buscar, você já tinha um lá.
     *  - **a colônia de outro colono**: entrega lá e **segue para casa**. Três pontos, duas
     *    distâncias — é por isso que a viagem precisou de pernas independentes.
     */
    private function doPatio(Colony $dona, Vehicle $veiculo, string $destinoTipo, ?int $destinoId, array $carga, ?int $acordoId): Vehicle
    {
        if ($destinoTipo !== 'colonia') {
            throw new DomainRuleException(
                'destino_invalido_do_patio',
                'Um veículo no Pátio da Capital só sai para uma colônia — ele já está na Capital.',
            );
        }

        $this->exigirSemRestricaoComercial($dona);

        // Tirar carga do depósito é usar o Mercado Central (§25.8), como a retirada.
        AcessoAoMercado::exigir($dona);

        $destino = Colony::find($destinoId);

        if (! $destino) {
            throw new DomainRuleException('destino_inexistente', 'Colônia de destino não encontrada.');
        }

        $this->validarCarga($veiculo, $carga);

        // Para casa, o acordo não faz sentido (ninguém promete a si mesmo); para outro colono, é a
        // mesma regra de sempre — a carga só abate o acordo que ela aponta (D-41).
        $acordo = $destino->id === $dona->id
            ? null
            : $this->resolverAcordo($dona, 'colonia', $destino->id, $acordoId);

        $ida = $this->distancia(MapaFertways::CAPITAL_X, MapaFertways::CAPITAL_Y, $destino->x, $destino->y);
        $volta = $destino->id === $dona->id
            ? null
            : $this->distancia($destino->x, $destino->y, $dona->x, $dona->y);

        $energia = VeiculoSpecs::energiaDasPernas($veiculo->type, array_filter([$ida, $volta]));

        return DB::transaction(function () use ($dona, $veiculo, $destino, $carga, $ida, $volta, $energia, $acordo) {
            $agora = now();

            // A energia sai do estoque da **colônia**, ainda que o veículo esteja na Capital: quem
            // abastece o caminhão é o dono dele, e é o único estoque que existe.
            $ref = $this->partir($dona, $veiculo, $energia, $agora);

            foreach ($carga as $recurso => $qtd) {
                $this->debitarConta($dona, $recurso, $qtd, $ref);
            }

            return $this->emRota($veiculo, 'colonia', $destino->id, 'entrega', $ida, $volta, $carga, $agora, $acordo?->id);
        });
    }

    /** §9.4, restrição comercial: uma condenação no Ministério fecha a saída de carga por 7 dias. */
    private function exigirSemRestricaoComercial(Colony $origem): void
    {
        $usuario = $origem->user;

        if (! $usuario) {
            return;
        }

        /*
         * D-61: a suspensão administrativa **congela o comércio**, e é aqui que ela morde.
         *
         * Ela não inventa mecânica: reusa exatamente esta porta, que o §9.4 já usava. A suspensão
         * barra o login, mas um suspenso pode ter deixado a colônia com veículos e acordos — e os
         * **outros** jogadores não podem ficar à espera de carga de quem não pode mais entrar.
         *
         * A colônia continua produzindo, e os veículos em rota chegam. Só a saída fecha.
         */
        if (Suspender::estaSuspenso($usuario)) {
            throw new DomainRuleException(
                'conta_suspensa',
                'A sua conta está suspensa: nenhuma carga sai da colônia enquanto durar.',
            );
        }

        if (Punishment::restricaoComercialAtiva($usuario->id)) {
            throw new DomainRuleException(
                'restricao_comercial',
                'O Ministério das Reputações proibiu você de enviar recursos. Aguarde o fim da restrição.',
            );
        }
    }

    /**
     * D-41: a carga só abate um Acordo de Troca se **apontar** aquele acordo. Sem isto, um presente
     * casual entre os mesmos colonos viraria pagamento, e dois acordos abertos do mesmo par se
     * canibalizariam.
     *
     * O acordo não reserva nada e não é obrigatório: despachar sem ele continua sendo comércio
     * informal, com o risco de calote que o §25.7 quer preservar.
     */
    private function resolverAcordo(Colony $origem, string $destinoTipo, ?int $destinoId, ?int $acordoId): ?TradeAgreement
    {
        if ($acordoId === null) {
            return null;
        }

        $acordo = TradeAgreement::find($acordoId);

        if (! $acordo || ! $acordo->envolve($origem->id)) {
            throw new DomainRuleException('acordo_de_outros', 'Este acordo não é seu.');
        }

        if (! $acordo->emVigor()) {
            throw new DomainRuleException('acordo_nao_vigente', "O acordo está {$acordo->status}, não aceito.");
        }

        if ($acordo->deadline_at->isPast()) {
            throw new DomainRuleException('prazo_ja_vencido', 'O prazo deste acordo já venceu.');
        }

        if ($destinoTipo !== 'colonia' || $acordo->contraparte($origem->id) !== $destinoId) {
            throw new DomainRuleException(
                'destino_nao_e_a_contraparte',
                'A carga de um acordo tem de ir para a colônia da contraparte.',
            );
        }

        return $acordo;
    }

    /**
     * Retirada do Mercado Central (§25.8): "ele precisa enviar um veículo próprio para retirá-lo
     * e levá-lo até seu slot". O veículo vai vazio, carrega na Capital e volta com a carga — o
     * tributo só incide quando ela chega ao slot do colono, no fim da volta.
     *
     * O saldo é debitado da conta **aqui, no despacho**, não na chegada do veículo ao Mercado.
     * É a mesma regra do estoque na entrega (D-30): sem reservar, dois veículos partiriam
     * prometendo o mesmo saldo e um deles voltaria vazio depois de gastar energia. Ver D-32.
     */
    public function retirar(Colony $origem, Vehicle $veiculo, array $pedido): Vehicle
    {
        $this->validarVeiculo($origem, $veiculo);

        AcessoAoMercado::exigir($origem);

        $destino = $this->resolverDestino($origem, 'mercado_central', null);
        $this->validarCarga($veiculo, $pedido);

        // A retirada continua sendo ida e volta (D-65): ela existe justamente para quem **não**
        // tem veículo estacionado na Capital. Quem tem, despacha do Pátio e paga uma perna só.
        [$distancia, $energia] = $this->idaEVolta($origem, $veiculo, $destino);

        return DB::transaction(function () use ($origem, $veiculo, $pedido, $distancia, $energia) {
            $agora = now();
            $ref = $this->partir($origem, $veiculo, $energia, $agora);

            foreach ($pedido as $recurso => $qtd) {
                $this->debitarConta($origem, $recurso, $qtd, $ref);
            }

            // `cargo_json` numa retirada é a carga **reservada**, que só embarca de fato ao
            // chegar na Capital. O veículo viaja vazio na ida; `trip_purpose` diz qual é qual.
            return $this->emRota($veiculo, 'mercado_central', null, 'retirada', $distancia, $distancia, $pedido, $agora);
        });
    }

    /**
     * Retirada de uma zona neutra sua (§07, §24.4; D-52). Igual à retirada do Mercado: o veículo
     * vai vazio, carrega o mineral extraído no Depósito da zona e volta — o tributo incide quando a
     * carga chega ao slot, no fim da volta (o `ConcluirTrechos` já trata `retirada` para qualquer
     * destino). Só o mineral daquela zona sai dela, e a carga é reservada no despacho, como no
     * Mercado (D-30), para dois veículos não prometerem o mesmo Depósito.
     */
    public function retirarDeZona(Colony $origem, Vehicle $veiculo, NeutralZone $zona, array $pedido): Vehicle
    {
        $this->validarVeiculo($origem, $veiculo);
        $this->exigirSemRestricaoComercial($origem);

        if ($zona->owner_colony_id !== $origem->id) {
            throw new DomainRuleException('zona_nao_e_sua', 'Esta zona neutra não é sua.');
        }

        /*
         * Cercada, nada entra nem sai (§28.10, D-66). É a metade da mordida do cerco que não está
         * em `ExtrairZonasNeutras` — lá o depósito para de aceitar o que se extrai; aqui, o dono
         * não pode esvaziar a zona debaixo do sítio para salvar o estoque do saque.
         *
         * Sem esta trava o cerco seria decorativo: bastaria mandar um Furgão e levar tudo embora
         * antes das 48 h.
         */
        if ($zona->cercada()) {
            throw new DomainRuleException(
                'zona_cercada',
                'A zona está cercada: nada entra nem sai. Rompa o cerco ou espere as 48 h.',
            );
        }

        $this->validarCarga($veiculo, $pedido);

        /*
         * Desde o D-67 a zona pode ter **duas** coisas para levar: o minério que ela extrai e o
         * secundário em que a **Refinaria de Campo** já o converteu. Retirar só o bruto tornaria a
         * Refinaria uma armadilha — o colono a construiria e o produto ficaria preso na zona.
         */
        $refinado = $zona->recursoRefinado();

        foreach ($pedido as $recurso => $qtd) {
            if ($recurso !== $zona->mineral && ! ($refinado !== null && $recurso === $refinado && $zona->refinery_level >= 1)) {
                throw new DomainRuleException(
                    'recurso_nao_e_da_zona',
                    "Esta zona rende {$zona->mineral}"
                    .($zona->refinery_level >= 1 && $refinado ? " e {$refinado} (Refinaria)" : '')
                    ."; não há {$recurso} para retirar.",
                );
            }
        }

        $destino = ['id' => $zona->id, 'x' => $zona->x, 'y' => $zona->y];
        [$distancia, $energia] = $this->idaEVolta($origem, $veiculo, $destino);

        return DB::transaction(function () use ($origem, $veiculo, $zona, $pedido, $distancia, $energia) {
            $agora = now();
            $ref = $this->partir($origem, $veiculo, $energia, $agora);

            foreach ($pedido as $recurso => $qtd) {
                // Cada um sai da sua coluna: o bruto do `deposit_amount`, o refinado do `refined_amount`.
                $coluna = $recurso === $zona->mineral ? 'deposit_amount' : 'refined_amount';
                $this->debitarDeposito($zona, $qtd, $ref, $coluna);
            }

            // `cargo_json` na retirada é a carga reservada; só embarca de fato ao chegar na zona.
            return $this->emRota($veiculo, 'zona_neutra', $zona->id, 'retirada', $distancia, $distancia, $pedido, $agora);
        });
    }

    /**
     * Leva material de obra até a zona (docs/decisoes.md D-67).
     *
     * **As obras da zona exigem entrega física.** O material sai do estoque da colônia, viaja de
     * veículo, e ao chegar entra no **canteiro** (`zone_materials`). Só então se pode construir.
     *
     * ⚠️ Isso **contradiz a ocupação**, que debita da colônia e ergue o Posto de Comando sem veículo
     * nenhum (D-52). Deliberado: a ocupação é o ato de **chegar**; as obras são o ato de **investir**.
     *
     * A viagem é de **ida e volta**: o veículo descarrega e volta para casa. (Diferente da entrega no
     * depósito da Capital, que é só de ida e estaciona no Pátio — lá há pátio, aqui não
     * necessariamente.)
     */
    public function entregarMaterialNaZona(Colony $origem, Vehicle $veiculo, NeutralZone $zona, array $carga): Vehicle
    {
        $this->validarVeiculo($origem, $veiculo);
        $this->exigirSemRestricaoComercial($origem);

        if ($zona->owner_colony_id !== $origem->id) {
            throw new DomainRuleException('zona_nao_e_sua', 'Esta zona neutra não é sua.');
        }

        // Cercada, nada entra nem sai — e é isso que impede fortificar sob sítio (D-67).
        if ($zona->cercada()) {
            throw new DomainRuleException(
                'zona_cercada',
                'A zona está cercada: nada entra nem sai. Não se constrói sob sítio.',
            );
        }

        $this->validarCarga($veiculo, $carga);

        $destino = ['id' => $zona->id, 'x' => $zona->x, 'y' => $zona->y];
        [$distancia, $energia] = $this->idaEVolta($origem, $veiculo, $destino);

        return DB::transaction(function () use ($origem, $veiculo, $zona, $carga, $distancia, $energia) {
            $agora = now();
            $ref = $this->partir($origem, $veiculo, $energia, $agora);

            // O material sai do estoque da colônia AGORA, no despacho — como toda carga que embarca.
            foreach ($carga as $recurso => $qtd) {
                $this->debitarEstoque($origem, $recurso, (int) $qtd, 'custo_obra_zona', $ref);
            }

            return $this->emRota($veiculo, 'zona_neutra', $zona->id, 'entrega', $distancia, $distancia, $carga, $agora);
        });
    }

    /** Reserva do Depósito da zona, com a mesma guarda atômica do estoque. */
    private function debitarDeposito(NeutralZone $zona, int $qtd, string $ref, string $coluna = 'deposit_amount'): void
    {
        $afetadas = NeutralZone::whereKey($zona->id)
            ->where($coluna, '>=', $qtd)
            ->decrement($coluna, $qtd);

        if ($afetadas === 0) {
            $que = $coluna === 'refined_amount' ? ($zona->recursoRefinado() ?? 'refinado') : $zona->mineral;

            throw new DomainRuleException(
                'deposito_insuficiente',
                "O Depósito da zona não tem {$qtd} de {$que}.",
            );
        }
    }

    private function validarVeiculo(Colony $origem, Vehicle $veiculo): void
    {
        if ($veiculo->colony_id !== $origem->id) {
            throw new DomainRuleException('veiculo_de_outra_colonia', 'Este veículo não é seu.');
        }

        // §25.5: em rota, indisponível para qualquer outra tarefa até completar a viagem.
        if (! $veiculo->disponivel()) {
            throw new DomainRuleException('veiculo_ocupado', 'O veículo está em rota.');
        }

        /*
         * D-60: veículo anunciado no mercado de usados não sai em viagem.
         *
         * Sem esta guarda, o vendedor podia anunciar e despachar em seguida — e o comprador que
         * clicasse em "comprar" levaria um erro na cara **por culpa do vendedor**, porque a compra
         * exige o veículo no pátio. O anúncio é um compromisso: ou você o está vendendo, ou o está
         * usando. Retire o anúncio para voltar a rodar com ele.
         */
        if (VehicleListing::where('vehicle_id', $veiculo->id)->where('status', 'aberto')->exists()) {
            throw new DomainRuleException(
                'veiculo_anunciado',
                'Este veículo está anunciado no mercado de usados. Retire o anúncio antes de despachá-lo.',
            );
        }
    }

    /** A distância de uma perna, em slots. Zero seria uma viagem para o lugar onde já se está. */
    private function distancia(int $x1, int $y1, int $x2, int $y2): int
    {
        $distancia = MapaFertways::distancia($x1, $y1, $x2, $y2);

        if ($distancia === 0) {
            throw new DomainRuleException('destino_igual_origem', 'Origem e destino são o mesmo ponto.');
        }

        return $distancia;
    }

    /**
     * Ida e volta pelo mesmo caminho: a viagem clássica, que sai de casa e volta para casa.
     *
     * @return array{0: int, 1: int} a distância (que serve às duas pernas) e a energia das duas
     */
    private function idaEVolta(Colony $origem, Vehicle $veiculo, array $destino): array
    {
        $distancia = $this->distancia($origem->x, $origem->y, $destino['x'], $destino['y']);

        return [$distancia, VeiculoSpecs::energiaDasPernas($veiculo->type, [$distancia, $distancia])];
    }

    /** Debita a energia e devolve a referência que amarra todos os lançamentos da viagem. */
    private function partir(Colony $origem, Vehicle $veiculo, int $energia, CarbonInterface $agora): string
    {
        $ref = "viagem:{$veiculo->id}:{$agora->getTimestamp()}";

        $this->debitarEstoque($origem, 'energia', $energia, 'energia_viagem', $ref);

        return $ref;
    }

    /**
     * Põe o veículo na estrada.
     *
     * `$volta` nulo é o que diz "viagem só de ida" (D-65): ao fechar a ida, o veículo **fica no
     * destino** em vez de dar meia-volta. É assim que o depósito o deixa estacionado no Pátio, e é
     * assim que o caminhão do Pátio volta para casa de vez. Nenhum outro sinalizador é preciso.
     */
    private function emRota(Vehicle $veiculo, string $destinoTipo, ?int $destinoId, string $proposito, int $distancia, ?int $volta, array $carga, CarbonInterface $agora, ?int $acordoId = null): Vehicle
    {
        $veiculo->forceFill([
            'status' => 'em_rota',
            'leg' => 'ida',
            'trip_purpose' => $proposito,
            'destination_type' => $destinoTipo,
            'destination_id' => $destinoId,
            'distance_slots' => $distancia,
            'return_distance_slots' => $volta,
            'departs_at' => $agora,
            // Sai da vaga: em rota não se paga a hora, e nada disto vale enquanto ele roda.
            'parked_at' => null,
            'patio_cobrado_ate' => null,
            // Pela Conservacao, não pelo VeiculoSpecs cru: o veículo desgastado é mais lento
            // (§16.4, D-60). A carroça de 30% não pode chegar junto com a nova.
            'arrives_at' => $agora->copy()->addSeconds(
                $this->conservacao->segundosDoTrecho($veiculo, $distancia),
            ),
            'cargo_json' => $carga,
            'trade_agreement_id' => $acordoId,
        ])->save();

        return $veiculo;
    }

    /** @return array{id: ?int, x: int, y: int} */
    private function resolverDestino(Colony $origem, string $tipo, ?int $id): array
    {
        // §25.8: o destino é fixo — o Mercado Central fica no núcleo do mapa. Não tem `id`
        // porque não é uma colônia; quem identifica o dono da carga é a origem da viagem.
        if ($tipo === 'mercado_central') {
            return ['id' => null, 'x' => MapaFertways::CAPITAL_X, 'y' => MapaFertways::CAPITAL_Y];
        }

        if ($tipo !== 'colonia') {
            throw new DomainRuleException('destino_invalido', "Destino desconhecido: {$tipo}");
        }

        $destino = Colony::find($id);

        if (! $destino) {
            throw new DomainRuleException('destino_inexistente', 'Colônia de destino não encontrada.');
        }

        if ($destino->id === $origem->id) {
            throw new DomainRuleException('destino_igual_origem', 'Não se despacha carga para a própria colônia.');
        }

        return ['id' => $destino->id, 'x' => $destino->x, 'y' => $destino->y];
    }

    private function validarCarga(Vehicle $veiculo, array $carga): void
    {
        if ($carga === []) {
            throw new DomainRuleException('carga_vazia', 'O veículo não sai vazio.');
        }

        foreach ($carga as $recurso => $qtd) {
            if (! is_int($qtd) || $qtd <= 0) {
                throw new DomainRuleException('quantidade_invalida', "Quantidade inválida para {$recurso}.");
            }

            if (! ResourceType::whereKey($recurso)->exists()) {
                throw new DomainRuleException('recurso_desconhecido', "Recurso inexistente: {$recurso}");
            }
        }

        // §25.4: a capacidade é em unidades de recurso, somando toda a carga.
        $total = array_sum($carga);

        // Desde o D-60 é a capacidade EFETIVA que manda: o §16.4 diz que o veículo desgastado
        // "carrega menos progressivamente". Um Caminhão a 50% leva 15.000, não 30.000.
        $capacidade = $this->conservacao->capacidadeEfetiva($veiculo);

        if ($total > $capacidade) {
            throw new DomainRuleException(
                'carga_excede_capacidade',
                "Carga de {$total} excede a capacidade de {$capacidade}.",
            );
        }
    }

    private function debitarEstoque(Colony $colonia, string $recurso, int $qtd, string $tipoLancamento, string $ref): void
    {
        // `where amount >= qtd` no UPDATE: dois despachos simultâneos não podem gastar o mesmo
        // estoque. A coluna é unsigned, então um saldo negativo seria erro de banco, não bug silencioso.
        $afetadas = $colonia->resources()
            ->where('resource_type', $recurso)
            ->where('amount', '>=', $qtd)
            ->decrement('amount', $qtd);

        if ($afetadas === 0) {
            throw new DomainRuleException('recurso_insuficiente', "Falta {$recurso} para esta viagem.");
        }

        $this->lancar($colonia, $tipoLancamento, -$qtd, $recurso, $ref);
    }

    /** Mesma guarda atômica do estoque, agora sobre o saldo do colono no Mercado. */
    private function debitarConta(Colony $colonia, string $recurso, int $qtd, string $ref): void
    {
        $afetadas = MarketAccount::where('colony_id', $colonia->id)
            ->where('resource_type', $recurso)
            ->where('amount', '>=', $qtd)
            ->decrement('amount', $qtd);

        if ($afetadas === 0) {
            throw new DomainRuleException(
                'saldo_mercado_insuficiente',
                "Sua conta no Mercado não tem {$qtd} de {$recurso}.",
            );
        }

        $this->lancar($colonia, 'retirada_mercado', -$qtd, $recurso, $ref);
    }

    private function lancar(Colony $colonia, string $tipo, int $valor, string $recurso, string $ref): void
    {
        Ledger::create([
            'colony_id' => $colonia->id,
            'type' => $tipo,
            'amount' => $valor,
            'resource_type' => $recurso,
            'ref' => $ref,
            'created_at' => now(),
        ]);
    }
}
