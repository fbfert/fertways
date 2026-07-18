<?php

namespace App\Domain\Building;

use App\Exceptions\DomainRuleException;
use App\Models\Building;
use App\Models\BuildQueue;
use App\Models\Colony;
use Illuminate\Support\Facades\DB;

/**
 * Demole uma construção e libera o slot (D-59).
 *
 * **O GDD não fala em demolição** — nem na palavra, nem no conceito. Tudo aqui é arbitragem do
 * usuário (2026-07-11):
 *
 *  - **O investido não volta.** Demolir é perda: nenhum recurso é estornado, nenhum Fert$. Por
 *    isso não há lançamento de crédito no ledger; o custo já fora lançado como `custo_construcao`
 *    quando a obra foi enfileirada, e continua lá — é o registro honesto de um gasto que virou pó.
 *  - **As cinco essenciais são indemolíveis.** Elas nasceram com a colônia, no miolo. Deixar o
 *    colono derrubar o Gerador de Atmosfera exigiria decidir o que acontece a uma colônia sem
 *    atmosfera, e o GDD não tem resposta para isso. Não se inventa uma. O Depósito Local (D-105)
 *    é indemolível pelo mesmo motivo prático — nasce no slot 21, que não é reconstruível —, mesmo
 *    não sendo uma das cinco.
 *  - **Não se demole o que está em obra.** Primeiro cancele a obra (ou espere terminar). Assim
 *    não é preciso decidir o estorno de uma obra interrompida no meio — questão que este serviço
 *    simplesmente não faz nascer.
 */
class Demolir
{
    /**
     * A palavra que o colono tem de escrever para confirmar (D-61).
     *
     * Ela é conferida na **API**, não só na tela: uma confirmação que vive só no React protege contra
     * o dedo escorregando e contra mais nada. Vive aqui, no domínio, para que a tela e o controlador
     * leiam a mesma verdade em vez de repetirem a string.
     */
    public const PALAVRA = 'DEMOLIR';

    public function handle(Colony $colony, Building $building): void
    {
        if ($building->colony_id !== $colony->id) {
            throw new DomainRuleException('construcao_de_outra_colonia', 'Esta construção não é sua.');
        }

        if ($building->ehIndemolivel()) {
            throw new DomainRuleException(
                'essencial_indemolivel',
                $building->ehEssencial()
                    ? 'As cinco construções essenciais são o miolo da colônia e não podem ser demolidas.'
                    : 'O Depósito Local nasce no slot 21, que não pode ser reconstruído — por isso não pode ser demolido.',
            );
        }

        DB::transaction(function () use ($colony, $building) {
            $colony = Colony::whereKey($colony->id)->lockForUpdate()->firstOrFail();

            $naFila = BuildQueue::where('colony_id', $colony->id)
                ->ativos()
                ->where('building_id', $building->id)
                ->exists();

            if ($naFila) {
                throw new DomainRuleException(
                    'demolir_em_obra',
                    'Esta construção está na fila de obras. Cancele a obra antes de demolir.',
                );
            }

            // A linha some, e com ela o slot volta a ficar vazio: é o mesmo estado de quem nunca
            // construiu nada ali. Os itens de fila já concluídos apontam para esta construção e
            // caem junto pela FK — o ledger, que é a contabilidade, não depende deles.
            $building->delete();
        });
    }
}
