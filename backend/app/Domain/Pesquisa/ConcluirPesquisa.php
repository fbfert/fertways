<?php

namespace App\Domain\Pesquisa;

use App\Models\Colony;
use Illuminate\Support\Facades\DB;

/**
 * Conclui as pesquisas cujo prazo venceu (A2.3).
 *
 * ## Preguiçoso, e não por tick
 *
 * Não há varredura global de pesquisas vencidas. A conclusão acontece quando a colônia é olhada —
 * mesmo padrão das missões (`garantirEncadeada`) e pelo mesmo motivo: uma varredura que percorre
 * todas as colônias a cada minuto custa caro num servidor de 4 GB, e não compra nada. Uma pesquisa
 * concluída que ninguém foi ver ainda não afeta o mundo; ela passa a afetar no instante em que
 * alguém pergunta.
 *
 * ⚠️ A consequência a saber: **o efeito de uma tecnologia só entra na conta depois de a colônia ser
 * tocada**. Se o tick de produção rodar antes de qualquer olhada, ele produz sem o bônus daquele
 * minuto. É por isso que `ColonyTick` chama isto **antes** de calcular produção.
 */
class ConcluirPesquisa
{
    public function handle(Colony $colonia): int
    {
        $vencidas = DB::table('colony_technologies')
            ->where('colony_id', $colonia->id)
            ->where('status', 'pesquisando')
            ->where('finishes_at', '<=', now())
            ->get(['id', 'nivel']);

        if ($vencidas->isEmpty()) {
            return 0;
        }

        foreach ($vencidas as $linha) {
            DB::table('colony_technologies')->where('id', $linha->id)->update([
                'status' => 'concluida',
                /*
                 * O nível sobe AQUI, e não no início. Se subisse ao iniciar, uma pesquisa começada e
                 * nunca terminada já daria o efeito do nível novo — e valeria a pena iniciar tudo e
                 * não concluir nada.
                 */
                'nivel' => (int) $linha->nivel + 1,
                'updated_at' => now(),
            ]);
        }

        return $vencidas->count();
    }
}
