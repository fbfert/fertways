<?php

namespace App\Domain\Endurance;

use App\Domain\Telemetria\RegistrarEvento;
use App\Models\Colony;
use App\Models\ColonyEnduranceItem;
use App\Models\EnduranceEscavacao;
use App\Models\EnduranceItem;
use Carbon\CarbonInterface;

/**
 * A equipe volta da Endurance com o que achou (D-249).
 *
 * Chamado pelo `ColonyTick`, logo depois da pesquisa e **antes da produção**: uma peça com bônus de
 * produção achada neste minuto vale neste minuto, e não no seguinte — a mesma razão pela qual a
 * pesquisa conclui ali.
 *
 * ## O único ganha o descobridor AQUI, e não no início
 *
 * A instância nasce na conclusão porque é nela que a colônia **acha** a peça. A reserva do início
 * (`Escavar`) já garante que ninguém mais a sorteia; a biografia começa quando a peça sai do chão.
 */
class ConcluirEscavacoes
{
    public function __construct(private Instancias $instancias) {}

    /**
     * @param  CarbonInterface|null  $agora  o instante do TICK, e não o relógio do servidor: o tick
     *                                       que recupera uma colônia atrasada precisa trazer de volta
     *                                       a equipe que voltou dentro do intervalo dele
     * @return int quantas escavações terminaram
     */
    public function handle(Colony $colony, ?CarbonInterface $agora = null): int
    {
        $agora ??= now();

        $vencidas = EnduranceEscavacao::where('colony_id', $colony->id)
            ->where('status', EnduranceEscavacao::ESCAVANDO)
            ->where('finishes_at', '<=', $agora)
            ->with('item')
            ->get();

        foreach ($vencidas as $escavacao) {
            $item = $escavacao->item;

            $posse = ColonyEnduranceItem::firstOrNew([
                'colony_id' => $colony->id,
                'endurance_item_id' => $item->id,
            ]);
            $posse->quantidade = ($posse->exists ? $posse->quantidade : 0) + 1;
            $posse->save();

            if ($item->tipo === EnduranceItem::UNICO) {
                $this->instancias->descobrir($colony, $item);
            }

            $escavacao->update([
                'status' => EnduranceEscavacao::CONCLUIDA,
                'concluida_em' => $escavacao->finishes_at,
            ]);

            app(RegistrarEvento::class)->handle(
                'endurance_interacao',
                null,
                $colony,
                [
                    'acao' => 'escavacao_concluida',
                    'secao' => $escavacao->secao,
                    'item' => $item->item_key,
                    'tipo' => $item->tipo,
                ],
                origem: 'sistema',
                adiar: true,
            );
        }

        return $vencidas->count();
    }
}
