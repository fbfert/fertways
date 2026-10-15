<?php

use Database\Seeders\EspacoportoPartidaSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * O Espaçoporto abre, com os números de partida que o usuário aprovou em 15/10 (D-263).
 *
 * ## A regra dos preços, e por que ela não é uma torneira
 *
 * Um planeta **paga 0,80×** o preço de referência do catálogo pelo que importa e **cobra 1,25×** pelo
 * que exporta (Auryn, o generalista que "importa qualquer recurso", paga 0,70×). Como o maior preço de
 * compra é sempre menor que o menor preço de venda, **nenhum ciclo entre planetas cria dinheiro** — há
 * teste guardando a propriedade, não o número. E 0,80× fica abaixo da mediana do livro entre jogadores
 * (0,84×, D-229): o Espaçoporto é escoadouro do excedente e fonte do escasso, não concorrente do
 * Mercado.
 *
 * O mapeamento de "exporta metais pesados" para recursos do jogo é interpretação minha do §03,
 * aprovada junto.
 *
 * ## Nunca desfaz o painel
 *
 * O Cargueiro só é configurado se ainda estiver como o D-262 o deixou (tudo vazio). Um planeta só
 * ganha preços e abre se ainda não tiver preço nenhum.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Sem catálogo (instalação nova) não há preço de referência: o `DatabaseSeeder` faz depois.
        if (DB::table('resource_types')->exists()) {
            (new EspacoportoPartidaSeeder)->run();
        }
    }

    /** Volta ao D-262: fechado e vazio — se não houver cargueiro no ar com estes preços. */
    public function down(): void
    {
        if (DB::table('contratos_de_rota')->where('status', 'em_viagem')->exists()) {
            throw new RuntimeException('Há cargueiro em viagem. Espere os contratos voltarem antes de desfazer.');
        }

        DB::table('planeta_npc_precos')->delete();
        DB::table('planetas_npc')->update(['aberto' => false, 'updated_at' => now()]);
        DB::table('espacoporto_settings')->where('id', 1)->update([
            'ativo' => false, 'aluguel_micro' => null, 'capacidade_unidades' => null, 'cargueiros' => null,
            'updated_at' => now(),
        ]);
    }
};
