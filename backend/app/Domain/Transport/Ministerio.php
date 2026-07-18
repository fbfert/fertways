<?php

namespace App\Domain\Transport;

use App\Exceptions\DomainRuleException;
use App\Models\Colony;
use Illuminate\Support\Facades\DB;

/**
 * O Ministério dos Transportes (D-60) — os números.
 *
 * Desde o D-109, todos os números daqui vêm de `fabrica_veiculos`, editável pelo admin (aba
 * Fábrica, `/central/admin/transportes?aba=fabrica`) — não são mais constantes de PHP. A tabela
 * é uma migration one-time (nunca um Seeder), então o ajuste do admin nunca é apagado por um
 * `db:seed` futuro — mesmo desenho do kit inicial (D-92) e do Silo (D-107/108).
 *
 * **O Ministério fabrica dois veículos**: o Caminhão de Carga (GDD §21.3, nível 1) e, desde o
 * D-109, o Furgão de Comércio também — pedido do usuário, "para o Furgão continua vindo só no kit
 * inicial" (D-60, item 9) deixou de valer.
 */
final class Ministerio
{
    public const TIPOS = ['caminhao_de_carga', 'furgao_de_comercio'];

    /**
     * A configuração de um tipo: preço, estoque-alvo, tempo de fabricação e custo em recursos.
     * Cache por request — o mesmo tick pode consultar isto várias vezes sem reconsultar o banco.
     *
     * @return array{preco_micro: int, estoque_alvo: int, minutos_fabricacao: int, custo: array<string,int>}
     */
    public static function config(string $tipo): array
    {
        static $cache = [];

        if (isset($cache[$tipo])) {
            return $cache[$tipo];
        }

        $linha = DB::table('fabrica_veiculos')->where('tipo', $tipo)->first();

        if (! $linha) {
            throw new DomainRuleException('veiculo_sem_fabrica', "O Ministério não fabrica: {$tipo}");
        }

        return $cache[$tipo] = [
            'preco_micro' => (int) $linha->preco_micro,
            'estoque_alvo' => (int) $linha->estoque_alvo,
            'minutos_fabricacao' => (int) $linha->minutos_fabricacao,
            'custo' => json_decode($linha->custo_json, true),
        ];
    }

    public static function precoFert(string $tipo): float
    {
        return self::config($tipo)['preco_micro'] / Colony::MICRO_POR_FERT;
    }
}
