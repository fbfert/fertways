<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Dois passos da tutoria falam a língua de quem acabou de chegar (D-266).
 *
 * Jogando como novato: o primeiro passo mandava "espere o tick entregar" — palavra de quem escreve o
 * jogo —, e o terceiro prometia "os seus 50 Fert$ iniciais (§03)", quando o kit é outro número e o §
 * é referência de documento interno.
 *
 * Migration, e não só o seeder, porque o `deploy.sh` não roda seeder. Só troca o texto se ainda for o
 * original: uma descrição que o operador já reescreveu no painel de Missões fica como ele deixou.
 */
return new class extends Migration
{
    private const TEXTOS = [
        'tut_primeira_obra' => [
            'Conclua 1 nível de construção — enfileire um upgrade e espere o tick entregar.',
            'Construa algo: toque num hexágono vazio, ou suba o nível de uma construção, e espere a obra terminar.',
        ],
        'tut_primeiro_lote' => [
            'Compre no Mercado Central — os seus 50 Fert$ iniciais existem para isto (§03).',
            'Compre alguma coisa no Mercado Central, na Capital — o Fert$ com que você chegou existe para isto.',
        ],
        // Duas diárias prometiam "acima de 500 F$": o piso caiu para 5 (D-117), e o Mercado nem
        // tem mais piso nenhum para a missão (D-241). O Acordo continua com o de 5 F$ (D-43).
        'dia_mercado_1' => [
            'Feche 1 negócio no Mercado Central (acima de 500 F$).',
            'Feche 1 negócio no Mercado Central, comprando ou vendendo.',
        ],
        'dia_acordo' => [
            'Tenha 1 Acordo de Troca executado (acima de 500 F$).',
            'Tenha 1 Acordo de Troca executado, de pelo menos 5 F$.',
        ],
    ];

    public function up(): void
    {
        foreach (self::TEXTOS as $chave => [$antes, $depois]) {
            DB::table('mission_templates')->where('chave', $chave)->where('descricao', $antes)
                ->update(['descricao' => $depois, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        foreach (self::TEXTOS as $chave => [$antes, $depois]) {
            DB::table('mission_templates')->where('chave', $chave)->where('descricao', $depois)
                ->update(['descricao' => $antes, 'updated_at' => now()]);
        }
    }
};
