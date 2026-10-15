<?php

use App\Domain\Eventos\EntregarCestas;
use App\Models\Colony;
use Database\Seeders\EnduranceItemSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A escavação da Endurance liga, com os números de partida do usuário (D-256).
 *
 * O D-249 a construiu desligada e vazia, porque custo e duração são do operador e o jogo não os
 * inventa. Em 15/10 o usuário aprovou a partida: **6 h por escavação, 15 F$ + 300 de energia**, e um
 * lote de peças que só se acham escavando, em três seções (`EnduranceItemSeeder::LOTE_DE_ESCAVACAO`).
 *
 * ## Por que migration, e o que ela NÃO faz
 *
 * O `deploy.sh` não roda seeder, e a escavação ligada sem peça nenhuma recusaria toda tentativa.
 * Esta migration põe as duas coisas em produção — mas **nunca desfaz o painel**: as configurações só
 * mudam se ainda estiverem como o D-249 as deixou (desligada, sem duração, sem custo), e uma peça só
 * entra se a chave dela ainda não existir. Quem já mexeu no cartão de `/central/admin/endurance`
 * continua com o que escolheu.
 */
return new class extends Migration
{
    private const DURACAO_MINUTOS = 360;

    private function custo(): array
    {
        return [EntregarCestas::FERT => 15 * Colony::MICRO_POR_FERT, 'energia' => 300];
    }

    public function up(): void
    {
        DB::table('endurance_escavacao_settings')
            ->where('id', 1)
            ->where('ativo', false)->whereNull('duracao_minutos')->whereNull('custo')
            ->update([
                'ativo' => true,
                'duracao_minutos' => self::DURACAO_MINUTOS,
                'custo' => json_encode($this->custo()),
                'updated_at' => now(),
            ]);

        foreach ((new EnduranceItemSeeder)->loteDeEscavacao() as $item) {
            if (DB::table('endurance_items')->where('item_key', $item['item_key'])->exists()) {
                continue;
            }

            $efeitos = $item['efeitos'];
            unset($item['efeitos']);

            $id = DB::table('endurance_items')->insertGetId($item + ['created_at' => now(), 'updated_at' => now()]);

            DB::table('endurance_item_effects')->insert(array_map(fn ($e) => [
                'endurance_item_id' => $id,
                'tipo_efeito' => $e['tipo'],
                'alvo' => $e['alvo'] ?? null,
                'valor_bps' => $e['bps'],
                'created_at' => now(),
                'updated_at' => now(),
            ], $efeitos));
        }
    }

    public function down(): void
    {
        $chaves = array_column((new EnduranceItemSeeder)->loteDeEscavacao(), 'item_key');

        // Uma peça já achada tem dono: apagá-la tiraria um item da mão de alguém. Recusa, como o
        // down do Observatório recusa com o prédio de pé.
        if (DB::table('endurance_items')->whereIn('item_key', $chaves)->where('quantidade_vendida', '>', 0)->exists()) {
            throw new RuntimeException('Peças do lote de escavação já foram achadas. Não desfaço o que alguém já tem.');
        }

        $ids = DB::table('endurance_items')->whereIn('item_key', $chaves)->pluck('id');
        DB::table('endurance_item_effects')->whereIn('endurance_item_id', $ids)->delete();
        DB::table('endurance_items')->whereIn('id', $ids)->delete();

        DB::table('endurance_escavacao_settings')
            ->where('id', 1)
            ->where('duracao_minutos', self::DURACAO_MINUTOS)
            ->update(['ativo' => false, 'duracao_minutos' => null, 'custo' => null, 'updated_at' => now()]);
    }
};
