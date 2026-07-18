<?php

namespace App\Domain\Building;

/**
 * O que cada construção FAZ pela colônia (D-59, item 5 do usuário).
 *
 * Até aqui a tela de detalhe só sabia dizer custo e tempo — o jogador via o preço da Oficina sem
 * nunca saber para que ela serve. Este catálogo é a resposta, e ele tem duas camadas que **não
 * podem ser confundidas**:
 *
 *  - `frase` / `fonte`: o que o GDD **promete**, transcrito verbatim, com o § de onde saiu.
 *  - `nota`: o que o jogo **entrega hoje**, quando isso é menos do que a promessa.
 *
 * A segunda camada existe porque a primeira, sozinha, mentiria. Sete construções o GDD descreve
 * com uma frase e nunca quantifica; duas têm número publicado e mesmo assim não mordem no código.
 * Uma tela que dissesse só "Laboratório: pesquisa tecnológica" faria o colono gastar 90 Ligas num
 * prédio inerte. Enquanto o efeito não existir, ele é anunciado como o que é: uma promessa.
 *
 * Os NÚMEROS por nível não estão aqui de propósito — saem de `building_specs`, que é semeada
 * verbatim do GDD. Digitá-los aqui seria copiar o documento para fora do banco e deixá-los
 * apodrecer. Ver D-02.
 */
class Funcoes
{
    /**
     * `efeito`:
     *   produz   — credita recurso no tick, sem insumo (§19.2)
     *   converte — consome insumo e credita saída (Destilaria §18.2, Oficina §24.5)
     *   porta    — o prédio é o acesso a uma tela do jogo (D-59, item 6)
     *   mostra   — não produz nem processa nada; é onde uma informação do jogo mora (D-105)
     *   nenhum   — não faz nada além de consumir energia
     */
    public const CATALOGO = [
        // ---------------------------------------------------------------- as cinco essenciais
        'gerador_de_atmosfera' => [
            'frase' => 'Cria e mantém a cúpula atmosférica do slot.',
            'fonte' => '§17.1',
            'efeito' => 'produz',
            'nota' => null,
        ],
        'reator_de_energia' => [
            'frase' => 'Produção de energia do slot.',
            'fonte' => '§17.1',
            'efeito' => 'produz',
            // §19.8 é o único limite que o GDD põe no tamanho de uma colônia — vale dizer ao
            // colono, porque desde o D-59 é ele que decide quantas cópias de Mina cabem.
            'nota' => 'É o teto real da colônia: cada construção erguida consome energia, e nada '
                . 'a limita senão o que o Reator sustenta (§19.8).',
        ],
        'estrutura_de_sobrevivencia' => [
            'frase' => 'Habitação dos colonos. Cresce em módulos.',
            'fonte' => '§17.1',
            'efeito' => 'nenhum',
            'nota' => 'O GDD não diz quantos colonos ela abriga, nem o que a população faz. '
                . 'Hoje ela só consome energia — o efeito ainda não existe no jogo.',
        ],
        'fazenda' => [
            'frase' => 'Plantio e colheita de biomassa.',
            'fonte' => '§17.1',
            'efeito' => 'produz',
            'nota' => null,
        ],
        'captacao_de_agua' => [
            'frase' => 'Produção e armazenamento de água.',
            'fonte' => '§17.1',
            'efeito' => 'produz',
            'nota' => 'Produz água, sim. O "armazenamento" da frase o GDD nunca quantifica: '
                . 'não há teto de estoque no jogo.',
        ],

        // ---------------------------------------------------------------- progressão
        'oficina' => [
            'frase' => 'Produção de ligas metálicas.',
            'fonte' => '§17.2',
            'efeito' => 'converte',
            // A meia-verdade mais antiga do projeto (D-19) — resolvida no D-83, mas não do jeito
            // que a frase do GDD sugere: a Oficina não passou a produzir Ligas, ela deixou de
            // constar entre as fontes possíveis.
            'nota' => 'Fabrica Componentes Eletrônicos pelas três receitas do §24.5 — escolha a '
                . 'receita no painel. As Ligas Metálicas do §19.3 NÃO são produzidas AQUI: desde o '
                . 'D-83, só a Indústria Siderúrgica as produz (D-82), que já converte Metal Bruto '
                . 'numa proporção real. Duas fontes de "Metal Bruto vira Ligas" com regras '
                . 'diferentes seria confuso, não redundante.',
        ],
        'refinaria_quimica' => [
            'frase' => 'Produz Compostos Químicos a partir de minerais e água.',
            'fonte' => '§17.2',
            'efeito' => 'converte',
            'nota' => 'Converte Metal Bruto, Água, Biomassa e Energia em Compostos Químicos (D-83) '
                . '— 1 Metal Bruto + 10 Água + 5 Biomassa + 6 Energia por Composto. O GDD nunca '
                . 'publica a receita, só a taxa (30/h no nível 1); nessa proporção ela pediria 300 '
                . 'Água/h contra os 80/h que a Captação nível 1 produz, então a taxa em vigor é '
                . 'outra e bem menor (2/h no nível 1) — calibrada para caber com folga.',
        ],
        'mina_local' => [
            'frase' => 'A fonte individual de Metal Bruto no slot principal. Complementa a oferta '
                . 'governamental e a extração territorial, sem substituir as zonas neutras.',
            'fonte' => '§04',
            'efeito' => 'produz',
            'nota' => 'Pode ser repetida em mais de um slot: duas Minas produzem o dobro (D-59).',
        ],
        'industria_siderurgica' => [
            'frase' => 'Não está no GDD — construção nova, pedida pelo usuário (D-82).',
            'fonte' => '—',
            'efeito' => 'converte',
            'nota' => 'Processa Metal Bruto em Ligas Metálicas e nos cinco minerais eletrônicos que, '
                . 'na Temporada 1, só o governo extrai (§4.3) — arbitragem consciente, não lacuna. A '
                . 'cada 1000 Metal Bruto: 350 Ligas, 35 Alumínio, 30 Cobre, 20 Estanho, 4 Ouro, 1 '
                . 'Tungstênio. Só credita em lotes inteiros de 1000; o resto fica guardado para o '
                . 'próximo tick. Taxa de processamento igual à Mina Local, nível a nível. Pode ser '
                . 'repetida em mais de um slot (D-59): duas somam produção.',
        ],
        'destilaria' => [
            'frase' => 'Converte 2 Biomassas + 3 Energias em 1 Biocombustível. A conversão não tem '
                . 'receita alternativa: a taxa é fixa.',
            'fonte' => '§04',
            'efeito' => 'converte',
            'nota' => 'Pode ser repetida em mais de um slot (D-59). Vai até o nível 10.',
        ],
        'central_de_transportes' => [
            'frase' => 'Produção e gestão de Caminhões de Carga e Naves de Transporte Planetária.',
            'fonte' => '§17.2',
            'efeito' => 'porta',
            'nota' => 'É por aqui que se vê a Frota. O §28.5 diz que o nível dela deveria limitar '
                . 'quantos Caminhões o colono pode ter — isso ainda não vale no jogo, e nenhum '
                . 'Caminhão é fabricado aqui.',
        ],
        'mercado_local' => [
            'frase' => 'Comércio direto com vizinhos.',
            'fonte' => '§17.2',
            'efeito' => 'porta',
            'nota' => 'É por aqui que se abrem os Acordos de Troca com outros colonos — que é '
                . 'exatamente o que a frase do GDD descreve. O Mercado Central, esse é '
                . 'instituição da Capital (§2.1) e se alcança pelo mapa.',
        ],
        'laboratorio' => [
            'frase' => 'Pesquisa tecnológica.',
            'fonte' => '§17.2',
            'efeito' => 'nenhum',
            'nota' => 'O GDD diz duas palavras e nunca publica árvore de pesquisa, tecnologias, '
                . 'custo nem tempo. Hoje o Laboratório só consome energia — o efeito não existe.',
        ],
        'antena_de_comunicacao' => [
            'frase' => 'Comunicação com a Capital, alertas, eventos.',
            'fonte' => '§17.2',
            'efeito' => 'nenhum',
            'nota' => 'Sem número de efeito em lugar nenhum do GDD. Hoje só consome energia.',
        ],
        'torre_de_defesa' => [
            'frase' => 'Defesa básica do slot.',
            'fonte' => '§17.2',
            'efeito' => 'nenhum',
            // Esta não é lacuna: é contradição. Vale dizer, porque um colono que construir a Torre
            // esperando proteger a colônia estará defendendo o que ninguém pode atacar.
            'nota' => 'O GDD se contradiz: o slot principal é INVIOLÁVEL (§01), então não há o que '
                . 'defender aqui. O bônus nunca é dado em número. Hoje só consome energia.',
        ],
        'quartel' => [
            'frase' => 'Recruta e treina Robôs Mineradores, Infiltradores e Predadores. Necessário '
                . 'para ocupar zonas neutras ou atacar.',
            'fonte' => '§17.2',
            'efeito' => 'nenhum',
            'nota' => 'A guerra do §27 está fora do MVP e nenhuma unidade é recrutada aqui ainda. '
                . 'Hoje só consome energia.',
        ],
        'plataforma_de_pouso' => [
            'frase' => 'Hangar onde as Naves de Transporte Planetária ficam estacionadas. Upgrades '
                . 'aumentam capacidade de naves estacionadas e reduzem tempo de construção.',
            'fonte' => '§17.2',
            'efeito' => 'nenhum',
            'nota' => 'O GDD promete "mais naves" e "menos tempo" sem publicar um número sequer, e a '
                . 'Nave de Transporte Planetária está fora do MVP. Hoje só consome energia.',
        ],
        'tanque_de_combustivel' => [
            'frase' => 'Armazena Gelo de Metano refinado. Disponível no slot principal e nas zonas '
                . 'neutras.',
            'fonte' => '§21.9',
            'efeito' => 'nenhum',
            'nota' => 'O GDD publica a capacidade (200 no nível 1, até 1.012), mas o jogo ainda não '
                . 'tem teto de estoque nenhum — guardar mais não faz diferença. Hoje só consome energia.',
        ],

        // ---------------------------------------------------------------- fora do GDD (D-105)
        'deposito_local' => [
            'frase' => 'Não está no GDD — nasce erguido em todo colono, no slot 21 (pedido do usuário).',
            'fonte' => '—',
            'efeito' => 'mostra',
            'nota' => 'É por aqui que se vê o que a colônia tem guardado: os recursos deixaram de '
                . 'ficar sempre visíveis, e abrir o Depósito é o único jeito de consultá-los agora, '
                . 'no desktop e no mobile. Não produz nem processa nada — é infraestrutura de acesso, '
                . 'não uma construção econômica. Nasce no nível 1, custeada pelo Governo como as '
                . 'cinco essenciais, e evolui como qualquer outra: o nível não muda o que ela mostra, '
                . 'só é a mesma curva de custo/tempo de toda construção.',
        ],
    ];

    /** @return array{frase: string, fonte: string, efeito: string, nota: ?string} */
    public static function de(string $tipo): array
    {
        return self::CATALOGO[$tipo] ?? [
            'frase' => 'O GDD não descreve esta construção.',
            'fonte' => '—',
            'efeito' => 'nenhum',
            'nota' => null,
        ];
    }
}
