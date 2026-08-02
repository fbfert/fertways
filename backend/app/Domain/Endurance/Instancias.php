<?php

namespace App\Domain\Endurance;

use App\Domain\Telemetria\RegistrarEvento;
use App\Exceptions\DomainRuleException;
use App\Models\Colony;
use App\Models\EnduranceItem;
use App\Models\EnduranceItemInstance;
use App\Models\EnduranceItemTransfer;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * A identidade e a biografia dos itens únicos da Endurance (A2.9 / GDD ALPHA 2 §11.1).
 *
 * ## O que "único" passou a significar
 *
 * Antes disto, `tipo = 'unico'` só fazia o painel forçar `quantidade_total = 1`. A posse continuava
 * sendo uma **quantidade**, e quantidade não tem identidade: não havia como dizer *quem achou*, *de
 * quem é agora*, nem *por onde passou*.
 *
 * ## ⚠️ O descobridor é imutável, e é isso que dá valor ao item
 *
 * `descobridor_colony_id` se escreve uma vez e nunca mais. O que faz um item único valer mais do que
 * um raro não é a escassez — raro também é escasso — é ele **ter uma história que ninguém pode
 * reescrever**. Se a primeira venda apagasse a descoberta, o item viraria só um número 1.
 *
 * ## Transferir registra, sempre
 *
 * Toda troca de mão escreve no histórico append-only. Não há caminho que mova um único sem deixar
 * linha: a transferência e o registro acontecem na **mesma transação**, e quem chamar `transferir()`
 * não consegue esquecer de registrar porque não é ele quem registra.
 */
class Instancias
{
    /**
     * Nasce um item único na mão de quem o descobriu.
     *
     * ⚠️ O `selo` é a identidade **do jogo** — o que aparece na tela, no chat e no leilão. O `id` é
     * detalhe de banco, e um jogador nunca deveria precisar dele para dizer "é aquele mesmo".
     */
    public function descobrir(Colony $descobridor, EnduranceItem $item): EnduranceItemInstance
    {
        if ($item->tipo !== EnduranceItem::UNICO) {
            throw new DomainRuleException(
                'item_nao_e_unico',
                'Só item único ganha instância. Comum e raro continuam fungíveis.',
            );
        }

        return DB::transaction(function () use ($descobridor, $item) {
            /*
             * O índice único em `endurance_item_id` é a trava real; esta conferência existe para a
             * mensagem ser legível em vez de um erro de integridade cru.
             */
            if (EnduranceItemInstance::where('endurance_item_id', $item->id)->lockForUpdate()->exists()) {
                throw new DomainRuleException(
                    'ja_descoberto',
                    "«{$item->nome}» já foi descoberto. Só existe um.",
                );
            }

            $agora = now();

            $instancia = EnduranceItemInstance::create([
                'endurance_item_id' => $item->id,
                'selo' => $this->selo($item),
                'descobridor_colony_id' => $descobridor->id,
                'descoberto_em' => $agora,
                'colony_id' => $descobridor->id,
            ]);

            // A descoberta é a primeira linha da biografia — o item não aparece do nada no histórico.
            $this->registrar($instancia, null, $descobridor->id, 'descoberta', $agora);

            return $instancia;
        });
    }

    /**
     * Passa o item de mão.
     *
     * `$para` nulo é escrow: o item saiu da mão de alguém e ainda não chegou na de outro — é o estado
     * de um leilão em curso. Registrar isso em vez de deixar o dono antigo com ele é o que impede o
     * histórico de mentir sobre onde a peça estava.
     */
    public function transferir(
        EnduranceItemInstance $instancia,
        ?Colony $para,
        string $motivo,
    ): EnduranceItemInstance {
        return DB::transaction(function () use ($instancia, $para, $motivo) {
            $instancia = EnduranceItemInstance::whereKey($instancia->id)->lockForUpdate()->firstOrFail();
            $de = $instancia->colony_id;

            $instancia->update(['colony_id' => $para?->id]);
            $this->registrar($instancia, $de, $para?->id, $motivo, now());

            return $instancia->fresh();
        });
    }

    /**
     * Telemetria de circulação (entrega da fase).
     *
     * ⚠️ `adiar: true` porque isto roda dentro da transação da transferência: sem adiar, um rollback
     * levaria o evento junto e a métrica sumiria justamente nos casos que mais interessam. Foi a
     * lição do D-173.
     */
    private function registrar(
        EnduranceItemInstance $instancia,
        ?int $de,
        ?int $para,
        string $motivo,
        CarbonInterface $em,
    ): void {
        EnduranceItemTransfer::create([
            'instance_id' => $instancia->id,
            'de_colony_id' => $de,
            'para_colony_id' => $para,
            'motivo' => $motivo,
            'em' => $em,
        ]);

        app(RegistrarEvento::class)->handle(
            'item_unico_circulou',
            null,
            $para !== null ? Colony::find($para) : null,
            [
                'selo' => $instancia->selo,
                'item' => $instancia->item?->item_key,
                'de' => $de,
                'para' => $para,
                'motivo' => $motivo,
                // Quantas mãos já passaram por ele: a métrica de circulação que a fase pede.
                'trocas' => $instancia->historico()->count(),
            ],
            origem: 'sistema',
            adiar: true,
        );
    }

    /**
     * O selo, derivado do item e do instante da descoberta.
     *
     * Legível e estável: quem lê `FW-U-reator_de_teste-8F2A` sabe de que item se trata sem consultar
     * nada. Um contador sequencial revelaria quantos únicos existem no jogo, que é informação do
     * operador e não do jogador.
     */
    private function selo(EnduranceItem $item): string
    {
        return 'FW-U-'.$item->item_key.'-'.strtoupper(substr(md5($item->id.'|'.now()->getTimestampMs()), 0, 4));
    }
}
