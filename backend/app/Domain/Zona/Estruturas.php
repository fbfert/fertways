<?php

namespace App\Domain\Zona;

/**
 * As estruturas da zona neutra (GDD §17.4; docs/decisoes.md D-67).
 *
 * **A lacuna 7 do D-52 nunca foi de função — só de custo.** O §17.4 descreve todas elas, e ninguém
 * o tinha lido: o Abrigo recupera unidades entre turnos, a Torre avisa da aproximação inimiga com
 * antecedência, a Refinaria transforma o primário em secundário na própria zona, e o Cemitério **não
 * tem função nenhuma** — o próprio documento o declara "apenas visual".
 *
 * Duas camadas, como o `Domain\Building\Funcoes` faz para a colônia, e pela mesma razão: separar o
 * que o GDD **promete** do que o jogo **entrega hoje**. Sem isso, o colono gasta 600 Metal Bruto num
 * prédio inerte e só descobre depois.
 *
 * **Demolição existe desde o D-138** (`Domain\Zona\DemolirEstruturaDaZona`) — fecha a assimetria com
 * a colônia (`Domain\Building\Demolir`) achada em 2026-07-19 (D-122/D-123, achado 7) e registrada
 * ali como lacuna, não decisão. `ConcluirObrasDaZona`/`SubirNivelDaZona` continuam só SUBINDO nível
 * (o `max()` do primeiro segue deliberado — "nunca se BAIXA um nível" por upgrade/conquista); é a
 * demolição, um ato deliberado do dono, que agora zera uma coluna de volta a zero. Downgrade parcial
 * (reduzir sem zerar) não existe — nem na colônia, nem na zona: a lacuna era só a ausência total.
 */
class Estruturas
{
    /** A coluna de `neutral_zones` onde vive o nível de cada uma. */
    public const COLUNA = [
        'posto_de_comando' => 'command_post_level',
        'deposito_de_zona_neutra' => 'deposit_level',
        'muralha_de_perimetro' => 'wall_level',
        'torre_de_vigia' => 'watchtower_level',
        'bastiao' => 'bastion_level',
        'abrigo_de_robos' => 'shelter_level',
        'refinaria_de_campo' => 'refinery_level',
        'estacionamento_da_zona' => 'parking_level',
        'cemiterio_de_robos' => 'cemetery_level',
        'estrutura_de_extracao' => 'extraction_level',
        'central_de_comunicacao' => 'communication_level',
        'plataforma_de_pouso_da_zona' => 'landing_pad_level',
        'industria_siderurgica' => 'industry_level',
    ];

    /**
     * As que o colono pode ERGUER pela tela da zona.
     *
     * O **Posto de Comando** não está aqui: ele nasce com a ocupação (D-52) e não se ergue nem se
     * demole — "sem ela, não há controle territorial sobre a zona" (§17.4).
     */
    public const CONSTRUIVEIS = [
        'deposito_de_zona_neutra',
        'muralha_de_perimetro',
        'torre_de_vigia',
        'bastiao',
        'abrigo_de_robos',
        'refinaria_de_campo',
        'estacionamento_da_zona',
        'cemiterio_de_robos',
        'estrutura_de_extracao',
        'central_de_comunicacao',
        'plataforma_de_pouso_da_zona',
        'industria_siderurgica',
    ];

    /**
     * O que a Refinaria de Campo transforma, por distrito (D-67).
     *
     * **É a primeira construção do jogo que CONVERTE.** Todas as outras produzem uma taxa fixa por
     * hora, sem insumo nenhum — a Mina rende 15 Metal Bruto/h e não come nada. Esta consome.
     *
     * **2 primários → 1 secundário.** Não cria matéria do nada: dobra o valor por unidade
     * transportada, que é o que o §17.4 promete ("aumentando o valor da carga antes mesmo do
     * transporte"). O ganho real é de **volume** — a carroceria leva metade das unidades para o mesmo
     * minério, e as zonas ficam nos cantos do mapa, onde o frete é o gargalo.
     */
    public const REFINA = [
        'metal_bruto' => 'ligas_metalicas',
        'agua' => 'compostos_quimicos',
        'oxigenio' => 'compostos_quimicos',
        'biomassa' => 'biocombustivel',
    ];

    /** Quantos primários custam um secundário. */
    public const REFINO_CUSTO = 2;

    /**
     * Quanto a Refinaria processa por hora, no nível 1.
     *
     * **Metade do que a zona extrai** (a extração é 100/h, base arbitrada no D-52). Não é número
     * novo: é uma fração de um número que já existe. E a metade é o que dá sentido aos níveis — no
     * nível 1 ela refina metade do que entra, e subi-la passa a valer a pena. Os níveis seguem a
     * curva do §19.1 (`Base × 1,5^(N−1)`), como tudo o mais.
     */
    public const REFINO_BASE_HORA = 50;

    /** Vagas de caminhão do Estacionamento da Zona (§17.4, textual: 10 vagas). */
    public const ESTACIONAMENTO_VAGAS = 10;

    /**
     * O que cada uma promete (o GDD) e o que ela entrega (o jogo). Verbatim onde o documento é claro.
     *
     * @return array{nome: string, gdd: string, hoje: string, inerte: bool}
     */
    public static function de(string $tipo): array
    {
        return self::TABELA[$tipo] ?? [
            'nome' => ucfirst(str_replace('_', ' ', $tipo)),
            'gdd' => '—',
            'hoje' => '—',
            'inerte' => true,
        ];
    }

    private const TABELA = [
        'posto_de_comando' => [
            'nome' => 'Posto de Comando',
            'gdd' => 'Primeira estrutura erguida na ocupação. Sem ela, não há controle territorial sobre a zona.',
            'hoje' => 'Nasce com a ocupação, no nível 1. Não se ergue nem se demole.',
            'inerte' => false,
        ],

        'deposito_de_zona_neutra' => [
            'nome' => 'Depósito de Recursos',
            'gdd' => 'Armazena recursos extraídos antes do transporte. Quando lota, a extração para.',
            // ⚠️ A segunda metade do que o GDD diz NÃO vale aqui, e é deliberado (D-66).
            'hoje' => 'Guarda o que a zona extrai — e é o que PROTEGE do saque: o que cabe nele está a '
                .'salvo, o que transborda é butim. A extração NÃO para quando ele lota (contradição '
                .'deliberada ao §17.4: se parasse, nada jamais ficaria exposto e o saque seria sempre zero).',
            'inerte' => false,
        ],

        'muralha_de_perimetro' => [
            'nome' => 'Muralha de Perímetro',
            'gdd' => 'Dificulta a Invasão Direta — aumenta o número de unidades necessárias para o atacante vencer.',
            'hoje' => 'Soma bônus à Força Defensiva (§27.3), por nível. Vale a pena junto com a Torre e o Bastião.',
            'inerte' => false,
        ],

        'torre_de_vigia' => [
            'nome' => 'Torre de Vigia',
            'gdd' => 'Detecta a aproximação de unidades inimigas com antecedência. Cada nível aumenta o '
                .'tempo de antecipação antes do ataque acontecer.',
            // Duas funções, e as duas são do GDD: §17.4 (avisar) e §28.10 (ver o Infiltrador).
            'hoje' => 'Faz DUAS coisas: avisa do ataque antes de a marcha chegar (10 min por nível), e '
                .'detecta o Infiltrador de uma sabotagem (15% por nível, por rodada). Sem ela, você só '
                .'vê o inimigo quando ele bate à porta.',
            'inerte' => false,
        ],

        'bastiao' => [
            'nome' => 'Bastião',
            // ⚠️ O §17.4 NÃO o lista na zona. Ele é especialização da colônia. Ver D-67.
            'gdd' => 'Dobra o bônus defensivo da Torre de Defesa. ⚠️ O GDD o descreve como uma '
                .'ESPECIALIZAÇÃO DA COLÔNIA (exige Torre de Defesa N3 + Quartel N3), e não como '
                .'estrutura de zona — mas o §27.3 o conta entre as defesas da zona.',
            'hoje' => 'É estrutura de zona, por decisão do usuário (D-67). É o maior bônus defensivo dos três.',
            'inerte' => false,
        ],

        'abrigo_de_robos' => [
            'nome' => 'Abrigo de Robôs',
            'gdd' => 'Onde as unidades ficam estacionadas e se recuperam entre turnos de extração.',
            'hoje' => 'É o que o Predador tem de vencer para apreender um módulo (§28.10): quanto mais '
                .'alto, menor a chance dele. A RECUPERAÇÃO de unidades feridas o GDD promete e nunca '
                .'cronometra — está por fazer.',
            'inerte' => false,
        ],

        'refinaria_de_campo' => [
            'nome' => 'Refinaria de Campo',
            'gdd' => 'Processa o recurso extraído ainda na zona neutra — recurso primário vira secundário '
                .'no local, aumentando o valor da carga antes mesmo do transporte.',
            'hoje' => 'Converte 2 do minério da zona em 1 do secundário correspondente, por hora. É a '
                .'ÚNICA construção do jogo que consome para produzir. O ganho é de volume: a carroceria '
                .'leva metade das unidades pelo mesmo minério, e a zona fica longe.',
            'inerte' => false,
        ],

        'estacionamento_da_zona' => [
            'nome' => 'Estacionamento da Zona',
            'gdd' => '10 vagas para Caminhões de Carga aguardarem fila de retirada dentro da própria zona.',
            // Corrigido em 2026-07-19 (D-122, achado numa revisão de zonas): até aqui esta linha
            // dizia "dá 10 vagas — de graça", mas NADA no jogo contava veículos nem barrava
            // ninguém — o texto prometia um limite que não existia. Implementar o limite de
            // verdade hoje arriscaria travar zonas já ativas em produção sem fila nenhuma pra
            // justificar a mudança; a correção segura, por ora, é dizer a verdade — mesma escolha
            // do Cemitério.
            'hoje' => 'Nada. Ninguém conta veículos nem barra ninguém — o "10 vagas" nunca foi '
                .'aplicado. Ergue-se por gosto e futuro, como o Cemitério, até alguém desenhar o '
                .'que "fila de retirada" deveria significar num jogo sem fila de verdade.',
            'inerte' => true,
        ],

        'cemiterio_de_robos' => [
            'nome' => 'Cemitério de Robôs',
            // O GDD é explícito, e não corrigimos: ele QUER que ela seja inútil.
            'gdd' => 'Sem função mecânica — apenas visual. Mostra unidades destruídas em combates '
                .'anteriores. Dá peso histórico a zonas muito disputadas.',
            'hoje' => 'Nada. É a única construção do jogo que se ergue só por gosto — e o próprio GDD a '
                .'declara assim. Mostra os seus mortos.',
            'inerte' => true,
        ],

        /*
         * As três últimas do §17.4 (D-79). O D-67 as tinha deixado FORA de escopo — nenhuma tem
         * função possível hoje, porque a função que o GDD promete depende de um sistema que não
         * existe (extração territorial já funciona sem ferramenta própria; Federação; Nave de
         * Transporte Planetária). O usuário reabriu a decisão de propósito: quer poder ERGUÊ-las,
         * mesmo inertes, como o Cemitério — não é lacuna que falta fechar, é gosto e futuro.
         */
        'estrutura_de_extracao' => [
            'nome' => 'Estrutura de Extração',
            'gdd' => 'Varia conforme o tipo de recurso da zona: perfuratriz para minerais, escavadeira '
                .'para cristais.',
            'hoje' => 'Nada. A zona já extrai sem ela desde a Fatia 1 (D-52) — travar a extração a uma '
                .'ferramenta agora puniria as zonas que já rendem. Ergue-se por gosto, como o Cemitério.',
            'inerte' => true,
        ],

        'central_de_comunicacao' => [
            'nome' => 'Central de Comunicação',
            'gdd' => 'Permite que membros da federação vejam o status da zona em tempo real e recebam '
                .'alertas de ataque mesmo sem abrir o slot principal.',
            // Ativada no D-116: a Federação existe desde o D-114. As duas metades do GDD, de verdade:
            'hoje' => 'Faz as DUAS coisas que promete, para quem é da SUA federação: aliados veem esta '
                .'zona ao vivo sem gastar Drone (nível ≥ 1 já basta), e recebem um aviso quando ela '
                .'entra em cerco. Para você, dono, não muda nada — o efeito é todo para os aliados.',
            'inerte' => false,
        ],

        'plataforma_de_pouso_da_zona' => [
            'nome' => 'Plataforma de Pouso',
            // ⚠️ Homônima da Plataforma de Pouso do slot da colônia — entidades e custos diferentes.
            'gdd' => 'Permite o pouso de Naves de Transporte Planetária para retirada direta de robôs e '
                .'mercadorias da zona, sem depender de via terrestre hostil.',
            'hoje' => 'Nada. Só serve à Nave de Transporte Planetária, que está no catálogo e não no jogo '
                .'— é uma fatia inteira (§17.5): voo, placa, robôs transportados entre zonas.',
            'inerte' => true,
        ],

        /*
         * Construção nova, pedida pelo usuário — NÃO está no GDD (D-82). Existe também na colônia
         * (`Domain\Building\Funcoes`), com a mesma receita e a mesma tabela de custo.
         */
        'industria_siderurgica' => [
            'nome' => 'Indústria Siderúrgica',
            'gdd' => 'Não está no GDD — construção nova, pedida pelo usuário (D-82).',
            'hoje' => 'Processa Metal Bruto em Ligas Metálicas e nos cinco minerais eletrônicos que, na '
                .'Temporada 1, só o governo extrai (§4.3) — arbitragem consciente. Só funciona em zonas '
                .'de Metal Bruto, disputando o mesmo depósito que a Refinaria de Campo: quem chegar '
                .'primeiro no tick leva. A cada 1000 Metal Bruto processado: 350 Ligas, 35 Alumínio, 30 '
                .'Cobre, 20 Estanho, 4 Ouro, 1 Tungstênio — só em lotes inteiros.',
            'inerte' => false,
        ],
    ];

    /**
     * As que o §17.4 lista e o jogo **não tem**, e por quê. A tela as mostra como buraco marcado, em
     * vez de fingir que não existem — é o padrão do Gagarin e do Espaçoporto (D-55, D-63).
     *
     * **Vazia desde o D-79.** As três últimas (Extração, Comunicação, Plataforma de Pouso da zona)
     * foram custeadas como inertes — ver `TABELA` acima. Não é lacuna fechada por função: é a decisão
     * do usuário de erguê-las mesmo sem função, como sempre foi o caso do Cemitério.
     */
    public const AUSENTES = [];
}
