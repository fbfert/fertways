<?php

namespace Database\Seeders;

use App\Models\Colony;
use Illuminate\Database\Seeder;
use App\Models\WarSetting;
use Illuminate\Support\Facades\DB;

/**
 * A partida do Espaçoporto que o usuário aprovou em 15/10 (D-263): o Cargueiro e os preços dos planetas.
 *
 * Mora num seeder, e não só na migration, porque o preço sai do preço de referência do catálogo — e
 * numa instalação nova as migrations rodam ANTES do `ResourceTypeSeeder`. A migration
 * `2026_10_16_100000_espacoporto_com_os_numeros_de_partida` chama este seeder quando o catálogo já
 * existe (a produção); o `DatabaseSeeder` o chama numa instalação nova. Uma fonte só para os números.
 *
 * Um planeta **paga 0,80×** o preço de referência pelo que importa (Auryn, o generalista, 0,70×) e
 * **cobra 1,25×** pelo que exporta. Como o maior preço de compra é sempre menor que o menor preço de
 * venda, nenhum ciclo entre planetas cria dinheiro — há teste guardando a propriedade. O mapeamento de
 * "exporta metais pesados" para recursos do jogo é interpretação do §03, aprovada junto.
 *
 * **Idempotente e respeitoso do painel**: o Cargueiro só é configurado se estiver vazio; um planeta só
 * ganha preços e abre se ainda não tiver preço nenhum.
 */
class EspacoportoPartidaSeeder extends Seeder
{
    private const COMPRA = 0.80;

    private const COMPRA_AURYN = 0.70;

    private const VENDA = 1.25;

    /**
     * Kalidor, Veyra e Auryn abriram no D-263; Solène e Drakmoor, no D-269 — quando o portão "por
     * ciência" e o risco alto ganharam mecânica.
     */
    private const ABREM = ['kalidor', 'veyra', 'auryn', 'solene', 'drakmoor'];

    /** @return array<string, array{compra: list<string>, venda: list<string>}> */
    private function mapa(): array
    {
        $porClasse = fn (array $classes) => DB::table('resource_types')
            ->whereIn('tax_class', $classes)->orderBy('code')->pluck('code')->all();
        $raros = $porClasse(['raro']);

        return [
            // Exporta "metais pesados, compostos químicos"; importa "biomassa, água".
            'kalidor' => [
                'compra' => ['agua', 'biomassa'],
                'venda' => ['metal_bruto', 'cobre', 'estanho', 'tungstenio', 'compostos_quimicos'],
            ],
            // Exporta "sementes alienígenas, compostos orgânicos"; importa "energia, componentes".
            'veyra' => [
                'compra' => ['energia', 'componentes_eletronicos'],
                'venda' => ['resina_organica', 'fungo_bioluminescente'],
            ],
            // Exporta "variedade enorme"; importa "qualquer recurso". Vende os três minerais sem fonte
            // além do governo (§12) — a segunda fonte deles.
            'auryn' => [
                'compra' => $porClasse(['primario', 'secundario']),
                'venda' => ['silicio', 'litio', 'tantalo', 'aluminio', 'ouro'],
            ],
            // Exporta "tecnologia, dados de pesquisa"; importa "recursos raros". Fechado: o portão é
            // "por ciência", sem critério publicado.
            'solene' => [
                'compra' => $raros,
                'venda' => ['componentes_eletronicos'],
            ],
            // Exporta "minerais raros exclusivos"; importa "biomassa, água, componentes". Fechado: o
            // risco alto não tem mecânica.
            'drakmoor' => [
                'compra' => ['agua', 'biomassa', 'componentes_eletronicos'],
                'venda' => ['niobio_alienigena', 'cristal_de_helio_3', 'plasma_fossilizado', 'quartzo_piezoeletrico', 'ferro_vermelho', 'gelo_de_metano'],
            ],
        ];
    }

    public function run(): void
    {
        DB::table('espacoporto_settings')
            ->where('id', 1)
            ->where('ativo', false)->whereNull('aluguel_micro')->whereNull('capacidade_unidades')->whereNull('cargueiros')
            ->update([
                'ativo' => true,
                'aluguel_micro' => 20 * Colony::MICRO_POR_FERT,
                'capacidade_unidades' => 10_000,
                'cargueiros' => 2,
                'updated_at' => now(),
            ]);

        $base = DB::table('resource_types')->pluck('preco_base_micro', 'code');

        foreach ($this->mapa() as $chave => $lados) {
            $planeta = DB::table('planetas_npc')->where('chave', $chave)->first();

            if (! $planeta || DB::table('planeta_npc_precos')->where('planeta_npc_id', $planeta->id)->exists()) {
                continue;
            }

            $fatorCompra = $chave === 'auryn' ? self::COMPRA_AURYN : self::COMPRA;
            $linhas = [];

            foreach ($lados['compra'] as $r) {
                $linhas[$r]['compra_micro'] = (int) round((int) $base[$r] * $fatorCompra);
            }
            foreach ($lados['venda'] as $r) {
                $linhas[$r]['venda_micro'] = (int) round((int) $base[$r] * self::VENDA);
            }

            foreach ($linhas as $r => $p) {
                DB::table('planeta_npc_precos')->insert([
                    'planeta_npc_id' => $planeta->id,
                    'resource_type' => $r,
                    'compra_micro' => $p['compra_micro'] ?? null,
                    'venda_micro' => $p['venda_micro'] ?? null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            if (in_array($chave, self::ABREM, true)) {
                DB::table('planetas_npc')->where('id', $planeta->id)->update(['aberto' => true, 'updated_at' => now()]);
            }
        }

        self::niobioNoPrecoDoGoverno();
    }

    /**
     * O Nióbio é o freio do exército (D-66): o governo o vende a 10× a referência. Drakmoor, a 1,25×,
     * seria Nióbio a um sétimo do preço — e o freio sumiria para quem chega ao marco 35. Ele continua
     * vendendo (é uma segunda fonte), mas nunca abaixo do governo (D-269).
     */
    public static function niobioNoPrecoDoGoverno(): void
    {
        // Lê sem criar a linha: ela nasce na primeira leitura do jogo, com os números do D-66 — e
        // sem linha o preço do governo é o padrão da coluna (10× a referência, a_guerra).
        $governo = (int) (WarSetting::query()->value('niobio_preco_micro') ?? 3_163_000);

        DB::table('planeta_npc_precos')
            ->where('resource_type', 'niobio_alienigena')
            ->whereNotNull('venda_micro')
            ->where('venda_micro', '<', $governo)
            ->update(['venda_micro' => $governo, 'updated_at' => now()]);
    }

}
