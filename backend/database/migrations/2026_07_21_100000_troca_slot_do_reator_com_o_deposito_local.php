<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Troca o Reator de Energia (miolo, slot 10 — o centro exato da colmeia) de lugar com o Depósito
 * Local (slot 21 — a linha solta do final), em toda colônia que já existe (docs/decisoes.md
 * D-142). Pedido direto do usuário: o Depósito é a construção mais aberta pelo colono (é onde os
 * recursos moram desde o D-106), e o centro da colmeia é o slot mais visível/alcançável — o
 * Reator, que quase ninguém abre, vai para a borda.
 *
 * `App\Domain\Colony\Slots::MIOLO['reator_de_energia']` e `::DEPOSITO_LOCAL['deposito_local']`
 * já foram trocados no código — isto é só o dado das colônias que já nasceram com o par antigo.
 * Toda colônia futura já nasce com o par novo, direto de `CreateColony`.
 *
 * **Troca em três passos, não em dois.** `buildings` tem `unique(colony_id, slot)` — atualizar o
 * Depósito direto para 10 esbarraria no Reator, que ainda está lá. Um valor de passagem fora da
 * faixa em uso (255, dentro do teto do `unsignedTinyInteger`) resolve sem depender de a
 * constraint ser adiável, que nem todo SGBD garante (e este projeto testa em SQLite e roda em
 * MariaDB — D-27, "SQLite mente").
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->trocar(reatorDe: 10, reatorPara: 21, depositoDe: 21, depositoPara: 10);
    }

    public function down(): void
    {
        $this->trocar(reatorDe: 21, reatorPara: 10, depositoDe: 10, depositoPara: 21);
    }

    private function trocar(int $reatorDe, int $reatorPara, int $depositoDe, int $depositoPara): void
    {
        DB::transaction(function () use ($reatorDe, $reatorPara, $depositoDe, $depositoPara) {
            DB::table('buildings')->where('type', 'reator_de_energia')->where('slot', $reatorDe)->update(['slot' => 255]);
            DB::table('buildings')->where('type', 'deposito_local')->where('slot', $depositoDe)->update(['slot' => $depositoPara]);
            DB::table('buildings')->where('type', 'reator_de_energia')->where('slot', 255)->update(['slot' => $reatorPara]);
        });
    }
};
