<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Desfaz o D-149: o Depósito Local volta do slot 14 pro centro exato da colmeia (10) — de volta
 * ao que o D-142 já tinha decidido (docs/decisoes.md D-150). Pedido direto do usuário, minutos
 * depois do D-149 ter ido ao ar.
 *
 * Não é um `migrate:rollback` da migration do D-149 — o `deploy.sh` só roda migrations pra frente
 * (`migrate --force`), nunca rollback; desfazer por uma migration NOVA, e não apagando/revertendo
 * a antiga, é o mesmo padrão que o D-142→D-149 já seguiu (uma migration por decisão, histórico
 * nunca reescrito). `App\Domain\Colony\Slots::DEPOSITO_LOCAL['deposito_local']` já voltou a 10 no
 * código; isto é só o dado das colônias que já nasceram com o slot 14.
 *
 * Mesmo cuidado do D-149: o slot 10 pode estar ocupado por uma construção do jogador (é exatamente
 * o que o D-149 pôs lá, pras 15 colônias que tinham algo no antigo slot 14) — a troca move os DOIS
 * lados, por um valor de passagem (255), não escreve por cima.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->trocar(depositoDe: 14, depositoPara: 10, outroDe: 10, outroPara: 14);
    }

    public function down(): void
    {
        $this->trocar(depositoDe: 10, depositoPara: 14, outroDe: 14, outroPara: 10);
    }

    private function trocar(int $depositoDe, int $depositoPara, int $outroDe, int $outroPara): void
    {
        DB::transaction(function () use ($depositoDe, $depositoPara, $outroDe, $outroPara) {
            DB::table('buildings')->where('type', 'deposito_local')->where('slot', $depositoDe)->update(['slot' => 255]);
            DB::table('buildings')->where('slot', $outroDe)->update(['slot' => $outroPara]);
            DB::table('buildings')->where('type', 'deposito_local')->where('slot', 255)->update(['slot' => $depositoPara]);
        });
    }
};
