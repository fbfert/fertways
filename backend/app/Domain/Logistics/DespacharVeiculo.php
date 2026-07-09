<?php

namespace App\Domain\Logistics;

use App\Exceptions\DomainRuleException;
use App\Models\Colony;
use App\Models\Ledger;
use App\Models\ResourceType;
use App\Models\Vehicle;
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
    public function handle(Colony $origem, Vehicle $veiculo, string $destinoTipo, ?int $destinoId, array $carga): Vehicle
    {
        if ($veiculo->colony_id !== $origem->id) {
            throw new DomainRuleException('veiculo_de_outra_colonia', 'Este veículo não é seu.');
        }

        // §25.5: em rota, indisponível para qualquer outra tarefa até completar a viagem.
        if (! $veiculo->disponivel()) {
            throw new DomainRuleException('veiculo_ocupado', 'O veículo está em rota.');
        }

        $destino = $this->resolverDestino($origem, $destinoTipo, $destinoId);
        $this->validarCarga($veiculo, $carga);

        $distancia = MapaFertways::distancia($origem->x, $origem->y, $destino['x'], $destino['y']);

        if ($distancia === 0) {
            throw new DomainRuleException('destino_igual_origem', 'Origem e destino são o mesmo ponto.');
        }

        $energia = VeiculoSpecs::energiaDaViagem($veiculo->type, $distancia);

        return DB::transaction(function () use ($origem, $veiculo, $destinoTipo, $destino, $carga, $distancia, $energia) {
            $agora = now();
            $ref = "viagem:{$veiculo->id}:{$agora->getTimestamp()}";

            $this->debitar($origem, 'energia', $energia, 'energia_viagem', $ref);

            foreach ($carga as $recurso => $qtd) {
                $this->debitar($origem, $recurso, $qtd, 'transferencia', $ref);
            }

            $veiculo->forceFill([
                'status' => 'em_rota',
                'leg' => 'ida',
                'destination_type' => $destinoTipo,
                'destination_id' => $destino['id'],
                'distance_slots' => $distancia,
                'departs_at' => $agora,
                'arrives_at' => $agora->copy()->addSeconds(
                    VeiculoSpecs::segundosDoTrecho($veiculo->type, $distancia),
                ),
                'cargo_json' => $carga,
            ])->save();

            return $veiculo;
        });
    }

    /** @return array{id: ?int, x: int, y: int} */
    private function resolverDestino(Colony $origem, string $tipo, ?int $id): array
    {
        if ($tipo === 'mercado_central') {
            /*
             * §25.8 exige que o recurso seja depositado numa conta do colono no Mercado antes de
             * poder ser vendido. Essa conta não existe ainda — entregar aqui evaporaria a carga.
             * Recusamos com um código estável em vez de perder recurso do jogador.
             */
            throw new DomainRuleException(
                'mercado_central_indisponivel',
                'O depósito no Mercado Central ainda não está implementado.',
            );
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

    private function debitar(Colony $colonia, string $recurso, int $qtd, string $tipoLancamento, string $ref): void
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

        Ledger::create([
            'colony_id' => $colonia->id,
            'type' => $tipoLancamento,
            'amount' => -$qtd,
            'resource_type' => $recurso,
            'ref' => $ref,
            'created_at' => now(),
        ]);
    }
}
