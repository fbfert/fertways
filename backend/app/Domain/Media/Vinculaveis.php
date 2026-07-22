<?php

namespace App\Domain\Media;

use App\Domain\Zona\Estruturas;
use App\Models\Building;

/**
 * O que pode receber imagem (docs/decisoes.md D-68).
 *
 * **É aqui que o jogo e as pastas do artista se encontram** — e eles não falavam a mesma língua. As
 * imagens vieram com nomes de fantasia (`reator-helios`, `estufa-aurora`, `nucleo-ares`) e o jogo
 * conhece slugs (`reator_de_energia`, `fazenda`, `gerador_de_atmosfera`). **Nenhuma associação é
 * automática**, e é por isso que o painel existe: quem decide qual imagem é qual construção é uma
 * pessoa, não uma heurística.
 *
 * Esta classe só diz **o que existe para receber arte**, e em que categoria cada coisa é procurada
 * primeiro. O vínculo em si vive em `image_bindings`.
 */
class Vinculaveis
{
    /**
     * As áreas e os slots da Capital não são linhas de tabela nenhuma — são desenho (D-63). Por isso
     * a chave é textual e prefixada, e não um id.
     */
    public const AREAS_DA_CAPITAL = [
        'capital:area:norte' => 'Capital — Governo Central (Norte)',
        'capital:area:oeste' => 'Capital — Destroços da Endurance (Oeste)',
        'capital:area:leste' => 'Capital — Mercado Central e Pátio (Leste)',
        'capital:area:sul' => 'Capital — Espaçoporto (Sul)',
    ];

    /**
     * As 8 seções do casco da Endurance (D-132/Loja de Peças) — como as áreas da Capital, não são
     * linha de tabela: são pontos soltos no campo de destroços (`EnduranceMapa.tsx`).
     */
    public const SECOES_DA_ENDURANCE = [
        'endurance:secao:anel_habitacional' => 'Endurance — Anel Habitacional',
        'endurance:secao:baia_criogenica' => 'Endurance — Baía Criogênica',
        'endurance:secao:comando' => 'Endurance — Comando',
        'endurance:secao:matriz_comunicacao' => 'Endurance — Matriz de Comunicação',
        'endurance:secao:modulo_medico' => 'Endurance — Módulo Médico',
        'endurance:secao:nucleo_propulsao' => 'Endurance — Núcleo de Propulsão',
        'endurance:secao:secao_acoplagem' => 'Endurance — Seção de Acoplagem',
        'endurance:secao:silo_suprimentos' => 'Endurance — Silo de Suprimentos',
    ];

    /** Os 20 slots do Governo Central (§2.1). O 6 não está: ele **é** o Leste (D-63). */
    public const SLOTS_DA_CAPITAL = [
        1 => 'Administração Pública',
        2 => 'Central de Tributos',
        3 => 'Central de Pesquisas e Notícias',
        4 => 'Secretaria de Finanças',
        5 => 'Ministério da Segurança e Guerra',
        7 => 'Ministério das Reputações',
        8 => 'Ministério dos Transportes',
        9 => 'Quartel de Alianças',
    ];

    /**
     * Onde cada coisa do jogo é procurada por padrão, na tela do painel.
     *
     * Não é uma trava: o painel deixa vincular **qualquer** imagem a **qualquer** coisa. É só a
     * arrumação — as construções da colônia aparecem sob "Colônia", os veículos sob "Logística". Sem
     * isso, a aba seria uma lista de sessenta linhas sem ordem nenhuma.
     */
    public static function porCategoria(): array
    {
        $mvp = Building::MVP;

        // As cinco essenciais (D-59): nascem prontas, no miolo da colmeia.
        $essenciais = array_values(array_intersect($mvp, [
            'estrutura_de_sobrevivencia', 'gerador_de_atmosfera', 'fazenda',
            'reator_de_energia', 'captacao_de_agua',
        ]));

        // As de progressão: tudo o mais que o colono ergue.
        $progressao = array_values(array_diff($mvp, $essenciais));

        return [
            'colonia-base' => [
                'titulo' => 'As cinco essenciais da colônia',
                'itens' => self::rotular($essenciais),
            ],
            'especializacoes-da-colonia' => [
                'titulo' => 'As construções de progressão da colônia',
                // O Depósito Local (D-105/106) nasce fora de `Building::MVP` de propósito — não é
                // catálogo de construção — mas é uma construção de verdade, com slot e arte própria
                // (`deposito-local.png` já está na biblioteca, na mesma categoria, sem vínculo).
                'itens' => self::rotular([...$progressao, 'deposito_local']),
            ],
            'zonas-neutras-e-conflito' => [
                'titulo' => 'As estruturas da zona neutra (§17.4)',
                'itens' => self::rotular(Estruturas::TODAS),
            ],
            'logistica-e-frota' => [
                'titulo' => 'Veículos e unidades',
                'itens' => self::rotular([
                    'furgao_de_comercio', 'caminhao_de_carga', 'nave_de_transporte_planetaria',
                    'drone_de_exploracao',
                    'sentinela', 'robo_minerador', 'infiltrador', 'predador',
                ]),
            ],
            'capital' => [
                'titulo' => 'A Capital — áreas e slots do Governo Central',
                'itens' => array_merge(
                    self::AREAS_DA_CAPITAL,
                    array_combine(
                        array_map(fn ($n) => "capital:slot:{$n}", array_keys(self::SLOTS_DA_CAPITAL)),
                        array_map(fn ($n, $s) => "Slot {$n} — {$s}",
                            array_keys(self::SLOTS_DA_CAPITAL), self::SLOTS_DA_CAPITAL),
                    ),
                ),
            ],
            'destrocos-da-endurance' => [
                'titulo' => 'A Loja de Peças — as 8 seções do casco (D-132)',
                'itens' => self::SECOES_DA_ENDURANCE,
            ],
        ];
    }

    /** Toda chave vinculável, achatada. Serve para validar o que chega do painel. */
    public static function todas(): array
    {
        $out = [];

        foreach (self::porCategoria() as $grupo) {
            $out += $grupo['itens'];
        }

        return $out;
    }

    /** O nome humano de um `building_type`, para a tela não mostrar slug. */
    private static function rotular(array $tipos): array
    {
        $out = [];

        foreach ($tipos as $t) {
            $out[$t] = NomesDeExibicao::de($t);
        }

        return $out;
    }
}
