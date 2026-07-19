<?php

namespace App\Domain\Trade;

use App\Models\Colony;
use App\Models\TradeAgreement;
use Illuminate\Support\Facades\DB;

/**
 * Move a Confiança Comercial (GDD §26.2) conforme o desfecho de um Acordo de Troca.
 *
 * Os números são arbitragem do usuário, não do GDD: +10 por acordo cumprido, −50 por acordo
 * quebrado, e nada abaixo do piso de 5 F$ do §26.3 (500 F$ original, revisto no D-117). Ver
 * docs/decisoes.md D-43 e D-117.
 *
 * O §26.9 veda compensação cruzada: **só** `confianca_comercial` se move aqui. Pagar tributo ou
 * completar missão nunca recupera confiança perdida num calote.
 */
class Reputacao
{
    /**
     * Fecha o acordo e aplica a reputação **uma única vez**.
     *
     * O tick pode rodar concorrente (dois crons sobrepostos) e `ConcluirTrechos` pode fechar o
     * mesmo acordo que `ExpirarAcordos` acabou de fechar. O `where('reputation_applied', false)`
     * no UPDATE é a guarda: quem perder a corrida não move índice nenhum.
     *
     * @param  array<int>  $penalizadas  colônias que não cumpriram; vazio quando todos cumpriram
     * @return bool false se outro processo já fechou este acordo
     */
    public function fechar(TradeAgreement $acordo, string $status, array $penalizadas): bool
    {
        return DB::transaction(function () use ($acordo, $status, $penalizadas) {
            $ganhou = TradeAgreement::whereKey($acordo->id)
                ->where('reputation_applied', false)
                ->update([
                    'status' => $status,
                    'reputation_applied' => true,
                    'executed_at' => $status === 'executado' ? now() : null,
                    'updated_at' => now(),
                ]);

            if ($ganhou === 0) {
                return false;
            }

            // D-43: acordo trivial registra histórico e status, mas não move o índice — senão dois
            // amigos farmariam reputação trocando uma unidade de minério mil vezes (§26.1, §26.4).
            if ($acordo->value_micro < AcordoSpecs::PISO_REPUTACAO_MICRO) {
                return true;
            }

            $penalizadas === []
                ? $this->premiarTodos($acordo)
                : $this->punir($penalizadas);

            /*
             * O XP do Marco anda junto da reputação, e HERDA o piso do D-43 acima — de propósito:
             * as duas moedas premiam o mesmo ato, e um acordo pequeno demais para mover reputação
             * é pequeno demais para subir marco (D-75). Só o acordo CUMPRIDO rende; o quebrado já
             * paga na reputação.
             */
            if ($status === 'executado' && $penalizadas === []) {
                $xp = app(\App\Domain\Marco\ConcederXp::class);
                $xp->handle($acordo->colony_a_id, 'acordo_executado', "acordo:{$acordo->id}");
                $xp->handle($acordo->colony_b_id, 'acordo_executado', "acordo:{$acordo->id}");

                $missoes = app(\App\Domain\Missoes\Progresso::class);
                $missoes->registrar($acordo->colony_a_id, 'acordo_executado');
                $missoes->registrar($acordo->colony_b_id, 'acordo_executado');
            }

            return true;
        });
    }

    private function premiarTodos(TradeAgreement $acordo): void
    {
        foreach ([$acordo->colony_a_id, $acordo->colony_b_id] as $colonyId) {
            $this->somar($colonyId, AcordoSpecs::GANHO_CUMPRIDO);
        }
    }

    /** @param array<int> $colonias */
    private function punir(array $colonias): void
    {
        foreach ($colonias as $colonyId) {
            $this->somar($colonyId, -AcordoSpecs::PERDA_QUEBRADO);
        }
    }

    /**
     * A reputação vive no colono (`users`), não na colônia: o §26.2 mede a conduta de quem joga.
     * O `limitar` mantém o índice na escala 0–1000 — um calote não deixa saldo negativo pendurado
     * que exigiria dez acordos honestos só para voltar a zero.
     */
    private function somar(int $colonyId, int $delta): void
    {
        $colonia = Colony::find($colonyId);

        if (! $colonia) {
            return;
        }

        $usuario = $colonia->user()->lockForUpdate()->first();

        if (! $usuario) {
            return;
        }

        $usuario->confianca_comercial = AcordoSpecs::limitar($usuario->confianca_comercial + $delta);
        $usuario->save();
    }
}
