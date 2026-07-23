<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * O Reator de Energia sai do slot 21 (a linha solta do final, onde o D-142 tinha posto ele) e vai
 * pro slot 6 — linha de cima, ao lado da Fazenda — em toda colônia que já existe (docs/decisoes.md
 * D-152). Pedido direto do usuário, sem motivo publicado.
 * `App\Domain\Colony\Slots::MIOLO['reator_de_energia']` já foi trocado no código; isto é só o dado
 * das colônias que já nasceram com o par antigo. Toda colônia futura já nasce com o slot novo.
 *
 * Mesmo cuidado do D-149/D-150: o 6 é um slot comum desde sempre, não um nome fixo — 18 das 28
 * colônias em produção já tinham construção de jogador ali (minas, oficinas, laboratório, torre de
 * defesa, refinaria química, até um Mercado Local — verificado por leitura antes de escrever esta
 * migration). A troca move os DOIS lados, por um valor de passagem (255), não escreve por cima.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->trocar(reatorDe: 21, reatorPara: 6, outroDe: 6, outroPara: 21);
    }

    public function down(): void
    {
        $this->trocar(reatorDe: 6, reatorPara: 21, outroDe: 21, outroPara: 6);
    }

    private function trocar(int $reatorDe, int $reatorPara, int $outroDe, int $outroPara): void
    {
        DB::transaction(function () use ($reatorDe, $reatorPara, $outroDe, $outroPara) {
            DB::table('buildings')->where('type', 'reator_de_energia')->where('slot', $reatorDe)->update(['slot' => 255]);
            DB::table('buildings')->where('slot', $outroDe)->update(['slot' => $outroPara]);
            DB::table('buildings')->where('type', 'reator_de_energia')->where('slot', 255)->update(['slot' => $reatorPara]);
        });
    }
};
