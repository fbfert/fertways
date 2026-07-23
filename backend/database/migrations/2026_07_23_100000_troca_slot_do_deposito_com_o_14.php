<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Troca o Depósito Local (D-105/D-142, slot 10 — o centro exato da colmeia) de lugar com o que
 * estiver no slot 14, em toda colônia que já existe (docs/decisoes.md D-149). Pedido direto do
 * usuário. `App\Domain\Colony\Slots::DEPOSITO_LOCAL['deposito_local']` já foi trocado no código —
 * isto é só o dado das colônias que já nasceram com o par antigo. Toda colônia futura já nasce
 * com o par novo, direto de `CreateColony`.
 *
 * **Diferente do D-142**: lá os dois lados eram construções NOMEADAS (Reator ↔ Depósito), sempre
 * presentes. Aqui só um lado tem nome fixo — o Depósito, sempre em 10. O 14 é um slot comum desde
 * sempre: em produção, 15 das 28 colônias já tinham construção de jogador ali (minas, laboratório,
 * oficina — verificado por leitura antes de escrever esta migration). A troca tem de mover os DOIS
 * lados, não sobrescrever — por isso o `WHERE slot = $outroDe`, sem filtro de tipo: pega o que
 * houver ali, seja o quê for, ou nada (colônia com o 14 vazio simplesmente fica com o 10 vazio).
 *
 * **Troca em três passos, não em dois** — mesmo motivo do D-142: `buildings` tem
 * `unique(colony_id, slot)`, e um valor de passagem fora da faixa em uso (255) evita depender de
 * a constraint ser adiável (D-27, "SQLite mente" — este projeto testa em SQLite e roda em MariaDB).
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->trocar(depositoDe: 10, depositoPara: 14, outroDe: 14, outroPara: 10);
    }

    public function down(): void
    {
        $this->trocar(depositoDe: 14, depositoPara: 10, outroDe: 10, outroPara: 14);
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
