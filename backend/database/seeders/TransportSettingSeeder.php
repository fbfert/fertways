<?php

namespace Database\Seeders;

use App\Models\TransportSetting;
use Illuminate\Database\Seeder;

/**
 * Os parâmetros do Painel do Ministério dos Transportes (§16) — D-60, fatia 2.
 *
 * **Estes números não são do GDD.** O documento descreve a depreciação (§16.4) em cinco estágios e
 * **não publica um único valor**; o que ele publica é *de quem* eles são: o painel do Ministério
 * "configura a curva de depreciação", "configura o limite crítico" e "configura a perda de vida útil
 * e o teto de revenda". São, portanto, do **operador** — e foi isso que permitiu tirar a depreciação
 * da geladeira sem inventar constante nenhuma no código.
 *
 * Os valores abaixo são a **semente** que o usuário decidiu (2026-07-12). O admin os muda no painel,
 * e o jogo obedece ao que estiver na linha — não ao que está escrito aqui.
 *
 * Idempotente: `firstOrCreate` na linha única. Rodar de novo não redefine o que o operador ajustou.
 */
class TransportSettingSeeder extends Seeder
{
    public function run(): void
    {
        TransportSetting::firstOrCreate([], [
            // 0,5% de conservação por hora de uso ATIVO. Uma viagem longa de ida e volta (~3 h)
            // custa ~1,5%.
            'desgaste_bps_por_hora' => 50,

            // 25%. É o "limite crítico" do §16.4 — mas como PISO de desempenho, não como bloqueio:
            // o D-60 decidiu que o veículo nunca trava. Um caminhão a 5% ainda anda a 25%.
            'piso_desempenho_bps' => 2_500,

            // A manutenção custa 10% do custo do veículo, em recursos. É fração da tabela publicada
            // (§21.2, §21.3), não número novo.
            'manutencao_bps_do_custo' => 1_000,

            // O teto de conservação cai 5 pontos a cada manutenção. Depois de ~14 delas o veículo
            // não tem mais o que recuperar, e o dono decide sucatear.
            'perda_de_teto_bps' => 500,
        ]);
    }
}
