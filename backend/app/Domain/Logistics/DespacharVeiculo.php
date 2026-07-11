<?php

namespace App\Domain\Logistics;

use App\Domain\Market\Deposito;
use App\Domain\Trade\AcessoAoMercado;
use App\Exceptions\DomainRuleException;
use App\Models\Colony;
use App\Models\Ledger;
use App\Models\MarketAccount;
use App\Models\NeutralZone;
use App\Models\Punishment;
use App\Models\ResourceType;
use App\Models\TradeAgreement;
use App\Models\Vehicle;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Despacha um veículo com carga (GDD §25.3, "Toda Movimentação de Recurso Exige Veículo Físico").
 *
 * A carga sai do estoque **no despacho**, não na entrega: o recurso está fisicamente no veículo
 * durante o trajeto. Se ele nunca chegasse, o recurso não deveria reaparecer na origem — é
 * exatamente isso que torna o calote do comércio informal "real e visível" (§25.7).
 *
 * A energia dos dois trechos também é debitada no despacho. O GDD não diz quando cobrar; cobrar
 * na saída impede que um veículo parta sem ter como voltar. Ver docs/decisoes.md D-30.
 */
class DespacharVeiculo
{
    public function handle(Colony $origem, Vehicle $veiculo, string $destinoTipo, ?int $destinoId, array $carga, ?int $acordoId = null): Vehicle
    {
        $this->validarVeiculo($origem, $veiculo);

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

        [$distancia, $energia] = $this->trajeto($origem, $veiculo, $destino);

        return DB::transaction(function () use ($origem, $veiculo, $destinoTipo, $destino, $carga, $distancia, $energia, $acordo) {
            $agora = now();
            $ref = $this->partir($origem, $veiculo, $energia, $agora);

            foreach ($carga as $recurso => $qtd) {
                $this->debitarEstoque($origem, $recurso, $qtd, 'transferencia', $ref);
            }

            return $this->emRota($veiculo, $destinoTipo, $destino['id'], 'entrega', $distancia, $carga, $agora, $acordo?->id);
        });
    }

    /** §9.4, restrição comercial: uma condenação no Ministério fecha a saída de carga por 7 dias. */
    private function exigirSemRestricaoComercial(Colony $origem): void
    {
        $usuario = $origem->user;

        if ($usuario && Punishment::restricaoComercialAtiva($usuario->id)) {
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

        [$distancia, $energia] = $this->trajeto($origem, $veiculo, $destino);

        return DB::transaction(function () use ($origem, $veiculo, $pedido, $distancia, $energia) {
            $agora = now();
            $ref = $this->partir($origem, $veiculo, $energia, $agora);

            foreach ($pedido as $recurso => $qtd) {
                $this->debitarConta($origem, $recurso, $qtd, $ref);
            }

            // `cargo_json` numa retirada é a carga **reservada**, que só embarca de fato ao
            // chegar na Capital. O veículo viaja vazio na ida; `trip_purpose` diz qual é qual.
            return $this->emRota($veiculo, 'mercado_central', null, 'retirada', $distancia, $pedido, $agora);
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

        $this->validarCarga($veiculo, $pedido);

        foreach ($pedido as $recurso => $qtd) {
            if ($recurso !== $zona->mineral) {
                throw new DomainRuleException(
                    'recurso_nao_e_da_zona',
                    "Esta zona só rende {$zona->mineral}; não há {$recurso} para retirar.",
                );
            }
        }

        $destino = ['id' => $zona->id, 'x' => $zona->x, 'y' => $zona->y];
        [$distancia, $energia] = $this->trajeto($origem, $veiculo, $destino);

        return DB::transaction(function () use ($origem, $veiculo, $zona, $pedido, $distancia, $energia) {
            $agora = now();
            $ref = $this->partir($origem, $veiculo, $energia, $agora);

            foreach ($pedido as $recurso => $qtd) {
                $this->debitarDeposito($zona, $qtd, $ref);
            }

            // `cargo_json` na retirada é a carga reservada; só embarca de fato ao chegar na zona.
            return $this->emRota($veiculo, 'zona_neutra', $zona->id, 'retirada', $distancia, $pedido, $agora);
        });
    }

    /** Reserva do Depósito da zona, com a mesma guarda atômica do estoque. */
    private function debitarDeposito(NeutralZone $zona, int $qtd, string $ref): void
    {
        $afetadas = NeutralZone::whereKey($zona->id)
            ->where('deposit_amount', '>=', $qtd)
            ->decrement('deposit_amount', $qtd);

        if ($afetadas === 0) {
            throw new DomainRuleException(
                'deposito_insuficiente',
                "O Depósito da zona não tem {$qtd} de {$zona->mineral}.",
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
    }

    /** @return array{0: int, 1: int} distância em slots e energia da viagem inteira */
    private function trajeto(Colony $origem, Vehicle $veiculo, array $destino): array
    {
        $distancia = MapaFertways::distancia($origem->x, $origem->y, $destino['x'], $destino['y']);

        if ($distancia === 0) {
            throw new DomainRuleException('destino_igual_origem', 'Origem e destino são o mesmo ponto.');
        }

        return [$distancia, VeiculoSpecs::energiaDaViagem($veiculo->type, $distancia)];
    }

    /** Debita a energia e devolve a referência que amarra todos os lançamentos da viagem. */
    private function partir(Colony $origem, Vehicle $veiculo, int $energia, CarbonInterface $agora): string
    {
        $ref = "viagem:{$veiculo->id}:{$agora->getTimestamp()}";

        $this->debitarEstoque($origem, 'energia', $energia, 'energia_viagem', $ref);

        return $ref;
    }

    private function emRota(Vehicle $veiculo, string $destinoTipo, ?int $destinoId, string $proposito, int $distancia, array $carga, CarbonInterface $agora, ?int $acordoId = null): Vehicle
    {
        $veiculo->forceFill([
            'status' => 'em_rota',
            'leg' => 'ida',
            'trip_purpose' => $proposito,
            'destination_type' => $destinoTipo,
            'destination_id' => $destinoId,
            'distance_slots' => $distancia,
            'departs_at' => $agora,
            'arrives_at' => $agora->copy()->addSeconds(
                VeiculoSpecs::segundosDoTrecho($veiculo->type, $distancia),
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

        if ($total > $veiculo->capacity) {
            throw new DomainRuleException(
                'carga_excede_capacidade',
                "Carga de {$total} excede a capacidade de {$veiculo->capacity}.",
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
