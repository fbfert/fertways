<?php

namespace App\Domain\Transport;

use App\Models\Vehicle;
use Illuminate\Support\Facades\DB;

/**
 * O Registro de Placas do Ministério dos Transportes (GDD §16.3).
 *
 * "Todo veículo civil — exceto Robô Minerador, Infiltrador e Predador (unidades militares
 * secretas) — recebe registro obrigatório no Ministério dos Transportes **ao ser construído ou
 * adquirido**." O jogo hoje só tem veículos civis (Furgão e Caminhão), então **todos** são
 * registrados.
 *
 * O GDD publica **um** exemplo de placa e nada mais: `FW-07429-F`, num Furgão de Comércio. O D-60
 * lê o formato dele assim:
 *
 *      FW - 5 dígitos sequenciais - inicial do tipo
 *      FW-00001-C  (Caminhão de Carga)     FW-00002-F  (Furgão de Comércio)
 *
 * A inicial é a leitura mais direta do único exemplo que existe: o `-F` de um Furgão. A placa passa
 * a dizer o que o veículo é — e atravessa a venda intacta, porque **a placa é do veículo, não do
 * dono** (é isso que faz dela um registro, e não uma etiqueta).
 */
class Placas
{
    private const INICIAL = [
        'furgao_de_comercio' => 'F',
        'caminhao_de_carga' => 'C',
        // Sem esta linha o Drone cairia no fallback 'X' — e placa é para sempre (D-75, auditoria
        // do painel). O 'X' é o código do tipo desconhecido, não uma escolha.
        'drone_de_exploracao' => 'D',
    ];

    /**
     * Emite a próxima placa livre para um tipo.
     *
     * O sequencial vem da **maior placa já emitida**, não da contagem de veículos: veículo
     * sucateado ou apagado não devolve o seu número ao estoque, senão duas máquinas diferentes na
     * história do planeta teriam levado a mesma placa. O `unique` no banco é a segunda trava.
     */
    public function emitir(string $tipo): string
    {
        $inicial = self::INICIAL[$tipo] ?? 'X';

        /*
         * O sequencial é global, não por tipo: a placa identifica o veículo no planeta, e o §16.3
         * fala de um registro único de "todos os veículos civis do planeta".
         *
         * O máximo sai em PHP, e não num `MAX(CAST(SUBSTRING(...)))`: essa sintaxe é do MySQL e
         * **quebraria no SQLite**, que é onde a suíte roda — a mesma armadilha que derrubou a
         * produção no D-59. A frota do planeta tem dezenas de veículos, não milhões; ler as placas
         * e comparar aqui custa nada e funciona nos dois motores.
         */
        $ultimo = DB::table('vehicles')
            ->whereNotNull('plate')
            ->pluck('plate')
            ->map(fn (string $placa): int => (int) substr($placa, 3, 5))
            ->max() ?? 0;

        return sprintf('FW-%05d-%s', $ultimo + 1, $inicial);
    }

    /** Emite e grava, numa transação, para que duas emissões simultâneas não colidam. */
    public function registrar(Vehicle $veiculo): string
    {
        return DB::transaction(function () use ($veiculo) {
            $placa = $this->emitir($veiculo->type);
            $veiculo->forceFill(['plate' => $placa])->save();

            return $placa;
        });
    }
}
