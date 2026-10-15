<?php

namespace Database\Seeders;

use App\Domain\Endurance\EfeitosDaEndurance as Efeitos;
use App\Models\EnduranceItem;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * O catálogo da Endurance — o §11 do GDD ALPHA 2, que existia inteiro e vazio (D-244).
 *
 * ## ⚠️ O sistema estava pronto e a sala vazia
 *
 * O §11 manda a Endurance ser *"origem de peças e artefatos"* e *"fonte de itens de diferentes
 * raridades"*. Os D-132 a D-140 construíram tudo: as 8 seções do casco, os 6 tipos de efeito
 * ligados ao motor, o teto agregado por tipo, a raridade, a linha de instância do único com
 * descobridor e histórico, os leilões. Medido em 2026-09-09, o catálogo tinha **1 item**, em 1 das 8
 * seções — e o D-226 registrou a consequência: 1 item nas mãos de um colono, 0 transferências.
 *
 * A A2.V5 decidiu **não polir a tela** da Endurance porque *"seria polir uma porta que ninguém
 * abre"*. Estava certo, e faltava a metade seguinte da frase: **atrás da porta não havia nada**.
 *
 * ## Nenhum número foi inventado — todos derivam do item que já existia
 *
 * O GDD não publica catálogo (nomeia raridades e não preços), então a régua é a do D-60: o número
 * que o documento manda existir e não publica sai do painel do operador. Só que aqui já havia **um
 * item de referência**, criado à mão pelo operador em 23/07 — a *Broca de Extração Aprimorada*:
 * `raro`, 42 unidades, 50 Fert$, marco 5, `producao_bonus +2000 bps` na Mina Local.
 *
 * O catálogo inteiro sai dele, por escala declarada:
 *
 * | | efeito | quantidade | preço | marco |
 * |---|---|---|---|---|
 * | comum | **20% do teto do tipo** | 3× | 20 F$ | 1 |
 * | **raro (a âncora)** | **40% do teto** | **42** | **50 F$** | **5** |
 * | único | **60% do teto** | **1** | 500 F$ | 10 |
 *
 * ⚠️ **A escala é fração do TETO DO TIPO, e não um bps absoluto — e foi um teste que me corrigiu.**
 *
 * A primeira versão escalava em valor absoluto a partir dos 2000 bps da Broca (comum 1000, raro
 * 2000, único 3000). Reproduz a âncora, e quebra fora dela: `DESCONTO_TRIBUTO` tem teto de **3000**,
 * então a peça única do Comando nascia **exatamente no teto** — sozinha, ela zerava o valor de todo
 * selo e toda cifra do mesmo tipo. A peça lendária apagaria o catálogo em vez de coroá-lo.
 *
 * Em fração isso não acontece por construção, e a âncora continua de pé: o teto de `PRODUCAO_BONUS`
 * é 5000, e 40% dele é **exatamente os 2000 bps** que o operador escolheu à mão. O que era caso
 * particular do Drone (teto 10.000, escala dobrada) também deixa de ser exceção — vira a mesma
 * conta.
 *
 * O topo em 60% é decisão: acima disso o único sozinho torna o resto do catálogo decorativo; abaixo,
 * ele não se distingue do raro. Entre os dois, ele é a melhor peça do mundo **e** ainda vale a pena
 * empilhar com as outras.
 *
 * ## Uma seção, um efeito
 *
 * Cada seção do casco entrega o que ela era quando a nave voava: o Comando negocia (tributo), o
 * Núcleo de Propulsão anda (velocidade), a Matriz de Comunicação enxerga longe (raio do Drone), a
 * Baía Criogênica dura (bateria). É o que faz o mapa da Endurance ser um mapa, e não uma lista.
 *
 * ⚠️ **O único mora em três seções, não em oito.** Único em toda seção é a armadilha que o próprio
 * §11.1 nomeia: *"evitar que 'único' se transforme apenas em mais uma categoria de drop repetível"*.
 *
 * ## ⚠️ O raro não é o comum maior: é o comum MAIS o vizinho de casco (D-245)
 *
 * O D-134 registrou em julho a queixa do usuário sobre a Loja antiga: *"qual a diferença entre
 * comprar um item comum ou de reputação?"*, e a resposta honesta era **pouca** — as camadas eram a
 * mesma mecânica em magnitudes crescentes. O D-135 refez a Loja no mesmo dia e o vocabulário de
 * camadas morreu, mas a **queixa reapareceu neste catálogo**: comum e raro de uma seção tinham o
 * mesmo efeito, no mesmo alvo, só maior. Colecionar seria comprar a mesma coisa mais cara.
 *
 * A regra que resolve, e ela é do casco: **o raro carrega o efeito da própria seção mais o da seção
 * a que ela era acoplada na nave.** O Comando ficava colado à Matriz de Comunicação, o Núcleo de
 * Propulsão à Seção de Acoplagem, a Baía Criogênica ao Módulo Médico, o Silo ao Anel Habitacional.
 * Uma peça arrancada da fronteira entre dois módulos traz um pedaço dos dois.
 *
 * Isso usa uma capacidade que o D-135 já tinha construído e que ninguém usava — **efeitos
 * empilhados por item** — e não inventa mecânica nenhuma: são os mesmos 6 tipos ligados ao motor,
 * combinados. O vizinho entra na fração do **comum** (20%), então o raro é estritamente melhor que o
 * comum sem virar dois itens num só.
 *
 * Idempotente: `updateOrCreate` pela `item_key`, e os efeitos são reescritos por item. A Broca do
 * operador **não é tocada** — ela tem `item_key` própria e continua exatamente como ele a criou.
 */
class EnduranceItemSeeder extends Seeder
{
    /**
     * A fração do teto do tipo que cada raridade vale.
     *
     * O `raro` em 40% **é** a âncora: o teto de `PRODUCAO_BONUS` é 5000, e 40% dele são os 2000 bps
     * da Broca que o operador criou à mão em 23/07.
     */
    private const FRACAO_DO_TETO = ['comum' => 0.20, 'raro' => 0.40, 'unico' => 0.60];

    /** A âncora, nas dimensões que não dependem do tipo de efeito. */
    private const RARO_QTD = 42;

    private const RARO_PRECO = 50;

    /**
     * O lote de partida da escavação (D-256), aprovado pelo usuário em 15/10: um comum e um raro em
     * três seções, que a loja NÃO vende — só se acham escavando (D-249).
     *
     * As três seções sem peça única na loja: é nelas que achar alguma coisa é novidade. O estoque é o
     * peso no sorteio (uma unidade, um bilhete), então 40 + 10 dá ~80% comum e ~20% raro por seção.
     * Sem único: a peça única é conteúdo de outra natureza, com descobridor, e não sai num lote.
     */
    public const LOTE_DE_ESCAVACAO = ['silo_suprimentos', 'baia_criogenica', 'secao_acoplagem'];

    private const ESCAVACAO_QTD = ['comum' => 40, 'raro' => 10];

    public function run(): void
    {
        foreach ($this->catalogo() as $item) {
            $efeitos = $item['efeitos'];
            unset($item['efeitos']);

            /*
             * ⚠️ `quantidade_vendida` só na criação (D-257). Era `updateOrCreate` com o `0` junto, e
             * re-semear em produção devolvia à prateleira tudo o que já tinha sido vendido — e, desde
             * o D-256, tudo o que já tinha sido achado escavando. O seeder é dono do conteúdo da
             * peça; o estoque vendido é placar do jogo.
             */
            $existente = EnduranceItem::where('item_key', $item['item_key'])->first();

            $linha = $existente
                ? tap($existente)->update(array_diff_key($item, ['quantidade_vendida' => true]))
                : EnduranceItem::create($item);

            /*
             * Os efeitos são reescritos, não acumulados: re-semear duas vezes não pode dar a uma
             * peça o dobro do bônus. A chave do item é o contrato; os efeitos são conteúdo dele.
             */
            DB::table('endurance_item_effects')->where('endurance_item_id', $linha->id)->delete();
            DB::table('endurance_item_effects')->insert(array_map(fn ($e) => [
                'endurance_item_id' => $linha->id,
                'tipo_efeito' => $e['tipo'],
                'alvo' => $e['alvo'] ?? null,
                'valor_bps' => $e['bps'],
                'created_at' => now(),
                'updated_at' => now(),
            ], $efeitos));
        }
    }

    /**
     * As oito seções do casco, cada uma com o que ela era quando a nave voava.
     *
     * @return list<array<string,mixed>>
     */
    private function catalogo(): array
    {
        $itens = [];

        $secoes = $this->secoes();

        foreach ($secoes as $secao => $s) {
            $itens[] = $this->item($secao, 'comum', $s, $secoes);
            $itens[] = $this->item($secao, 'raro', $s, $secoes);

            if (($s['unico'] ?? false) === true) {
                $itens[] = $this->item($secao, 'unico', $s, $secoes);
            }
        }

        return [...$itens, ...$this->loteDeEscavacao()];
    }

    /**
     * As peças do lote de partida da escavação, na mesma régua das da loja: fração do teto do tipo, e
     * o raro com o vizinho de casco. Pública porque a migration que as põe em produção lê daqui — os
     * números moram num lugar só.
     *
     * @return list<array<string,mixed>>
     */
    public function loteDeEscavacao(): array
    {
        $secoes = $this->secoes();
        $itens = [];

        foreach (self::LOTE_DE_ESCAVACAO as $secao) {
            $s = $secoes[$secao];

            foreach (['comum', 'raro'] as $raridade) {
                $item = $this->item($secao, $raridade, $s, $secoes);

                $itens[] = array_merge($item, [
                    'item_key' => $s['escavacao']['chave'][$raridade],
                    'nome' => $s['escavacao']['nome'][$raridade],
                    'descricao' => $s['escavacao']['descricao'][$raridade],
                    'origem' => EnduranceItem::ESCAVACAO,
                    'quantidade_total' => self::ESCAVACAO_QTD[$raridade],
                ]);
            }
        }

        return $itens;
    }

    /**
     * @param  array<string,mixed>  $s
     * @param  array<string,array<string,mixed>>  $secoes
     */
    private function item(string $secao, string $raridade, array $s, array $secoes): array
    {
        [$qtd, $preco, $marco] = match ($raridade) {
            'comum' => [self::RARO_QTD * 3, 20, 1],
            'raro' => [self::RARO_QTD, self::RARO_PRECO, 5],
            'unico' => [1, 500, 10],
        };

        /*
         * O bps sai do TETO DO TIPO, e não de um número absoluto — ver o docblock da classe. Foi um
         * teste que corrigiu isto: em absoluto, o único do Comando nascia exatamente no teto de
         * `DESCONTO_TRIBUTO` (3000) e apagava o comum e o raro do mesmo tipo.
         */
        $bps = (int) round(Efeitos::tetoBps($s['efeito']) * self::FRACAO_DO_TETO[$raridade]);

        $efeitos = [['tipo' => $s['efeito'], 'alvo' => $s['alvo'] ?? null, 'bps' => $bps]];

        /*
         * ⚠️ O VIZINHO DE CASCO (D-245): do raro para cima, a peça traz também o efeito da seção a
         * que a sua era acoplada na nave. É o que impede o raro de ser "o comum, maior" — a queixa
         * que o D-134 registrou em julho e que este catálogo tinha herdado sem perceber.
         *
         * Entra na fração do COMUM, sempre: o vizinho é o pedaço que veio junto na solda, não a
         * razão de a peça existir. Assim o raro é estritamente melhor que o comum sem virar dois
         * itens colados.
         */
        if ($raridade !== 'comum' && isset($s['vizinho'])) {
            $v = $secoes[$s['vizinho']];

            $efeitos[] = [
                'tipo' => $v['efeito'],
                'alvo' => $v['alvo'] ?? null,
                'bps' => (int) round(Efeitos::tetoBps($v['efeito']) * self::FRACAO_DO_TETO['comum']),
            ];
        }

        return [
            'item_key' => $s['chave'][$raridade],
            'secao' => $secao,
            'nome' => $s['nome'][$raridade],
            'tipo' => $raridade,
            'quantidade_total' => $qtd,
            'quantidade_vendida' => 0,
            'preco_micro' => $preco * 1_000_000,
            'marco_minimo' => $marco,
            'vendavel_em_leilao' => true,
            'descricao' => $s['descricao'][$raridade],
            'admin_id' => null,
            'efeitos' => $efeitos,
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function secoes(): array
    {
        return [
            'comando' => [
                'efeito' => Efeitos::DESCONTO_TRIBUTO,
                // A ponte ficava colada à Matriz: quem negociava era quem enxergava longe.
                'vizinho' => 'matriz_comunicacao',
                'unico' => true,
                'chave' => [
                    'comum' => 'comando_selo_de_transito',
                    'raro' => 'comando_cifra_aduaneira',
                    'unico' => 'comando_credencial_do_capitao',
                ],
                'nome' => [
                    'comum' => 'Selo de Trânsito da Endurance',
                    'raro' => 'Cifra Aduaneira do Comando',
                    'unico' => 'Credencial do Capitão',
                ],
                'descricao' => [
                    'comum' => 'Um selo de carga da tripulação original. A alfândega da Capital ainda o reconhece, e cobra menos por isso.',
                    'raro' => 'A cifra com que o Comando declarava carga em trânsito. Vale desconto em cada entrega — ninguém na Capital quer ser o funcionário que a recusou.',
                    'unico' => 'A credencial pessoal do comandante da Endurance. Existe uma. Quem a porta atravessa a alfândega como quem volta para casa.',
                ],
            ],
            'nucleo_propulsao' => [
                'efeito' => Efeitos::VELOCIDADE_VEICULO,
                'alvo' => Efeitos::ALVO_TODOS_OS_VEICULOS,
                // O núcleo empurrava o convés de carga: propulsão e acoplagem eram a mesma solda.
                'vizinho' => 'secao_acoplagem',
                'unico' => true,
                'chave' => [
                    'comum' => 'propulsao_rolamento_ceramico',
                    'raro' => 'propulsao_injetor_de_plasma',
                    'unico' => 'propulsao_coracao_da_endurance',
                ],
                'nome' => [
                    'comum' => 'Rolamento Cerâmico',
                    'raro' => 'Injetor de Plasma Recuperado',
                    'unico' => 'Coração da Endurance',
                ],
                'descricao' => [
                    'comum' => 'Rolamento do eixo secundário, feito para girar trezentos anos sem manutenção. Girou. Ainda gira.',
                    'raro' => 'Um injetor do núcleo, adaptado às caldeiras dos veículos de superfície. Bebe mais e anda muito mais.',
                    'unico' => 'A câmara central de propulsão, inteira. Não voa mais nada em Fertways — mas nada em Fertways anda como quem a carrega.',
                ],
            ],
            'matriz_comunicacao' => [
                'efeito' => Efeitos::DRONE_RAIO,
                'vizinho' => 'comando',
                'unico' => true,
                'chave' => [
                    'comum' => 'comunicacao_antena_de_bordo',
                    'raro' => 'comunicacao_repetidor_de_longo_alcance',
                    'unico' => 'comunicacao_olho_da_matriz',
                ],
                'nome' => [
                    'comum' => 'Antena de Bordo',
                    'raro' => 'Repetidor de Longo Alcance',
                    'unico' => 'Olho da Matriz',
                ],
                'descricao' => [
                    'comum' => 'Antena de corredor, das que só falavam com a sala ao lado. Num Drone, fala com o horizonte.',
                    'raro' => 'O repetidor que mantinha a Endurance em contato com a Terra. A Terra não responde; o Drone, sim, e de muito mais longe.',
                    'unico' => 'O prato principal da Matriz. Um só foi recuperado inteiro, e ele enxerga o que nenhum outro Drone do planeta enxerga.',
                ],
            ],
            'baia_criogenica' => [
                'efeito' => Efeitos::DRONE_BATERIA,
                // Os berços frios eram operados de dentro do Módulo Médico.
                'vizinho' => 'modulo_medico',
                'chave' => [
                    'comum' => 'criogenia_celula_de_reserva',
                    'raro' => 'criogenia_banco_criogenico',
                ],
                'nome' => [
                    'comum' => 'Célula de Reserva Criogênica',
                    'raro' => 'Banco Criogênico',
                ],
                'descricao' => [
                    'comum' => 'Célula que mantinha um berço frio por décadas com o que hoje se gasta num dia. O Drone voa mais tempo com ela a bordo.',
                    'raro' => 'Um banco inteiro de células, do corredor dos berços. Pesado, teimoso, e a bateria simplesmente não acaba.',
                ],
                // D-256: o que só se acha escavando — a loja não vende.
                'escavacao' => [
                    'chave' => [
                        'comum' => 'criogenia_escavada_serpentina_de_resfriamento',
                        'raro' => 'criogenia_escavada_berco_intacto',
                    ],
                    'nome' => [
                        'comum' => 'Serpentina de Resfriamento',
                        'raro' => 'Berço Criogênico Intacto',
                    ],
                    'descricao' => [
                        'comum' => 'Tubo de cobre arrancado da parede de um berço, ainda frio ao toque depois de tudo. Ninguém vende isto: alguém tem de ir buscar.',
                        'raro' => 'Um berço que nunca abriu, achado sob o entulho do corredor. O que ele guardava já não importa; a carga que o mantinha frio, sim.',
                    ],
                ],
            ],
            'secao_acoplagem' => [
                'efeito' => Efeitos::CAPACIDADE_VEICULO,
                'alvo' => Efeitos::ALVO_TODOS_OS_VEICULOS,
                'vizinho' => 'nucleo_propulsao',
                'chave' => [
                    'comum' => 'acoplagem_trilho_de_carga',
                    'raro' => 'acoplagem_garra_de_atracacao',
                ],
                'nome' => [
                    'comum' => 'Trilho de Carga',
                    'raro' => 'Garra de Atracação',
                ],
                'descricao' => [
                    'comum' => 'Trilho do convés de carga. Instalado numa caçamba, faz caber o que antes ficava para a segunda viagem.',
                    'raro' => 'A garra que segurava módulos de dez toneladas em órbita. Num furgão, ela ri da carga.',
                ],
                // D-256: o que só se acha escavando — a loja não vende.
                'escavacao' => [
                    'chave' => [
                        'comum' => 'acoplagem_escavada_cinta_de_amarracao',
                        'raro' => 'acoplagem_escavada_trava_de_doca',
                    ],
                    'nome' => [
                        'comum' => 'Cinta de Amarração',
                        'raro' => 'Trava de Doca Soterrada',
                    ],
                    'descricao' => [
                        'comum' => 'Uma cinta do porão de carga, enterrada desde a queda. A trama aguenta o que nenhuma corda de Fertways aguentaria.',
                        'raro' => 'A trava que prendia a doca à nave, desenterrada da cratera da acoplagem. Leva dias para soltar; depois, segura qualquer carga.',
                    ],
                ],
            ],
            'silo_suprimentos' => [
                'efeito' => Efeitos::PRODUCAO_BONUS,
                'alvo' => 'fazenda',
                // O silo alimentava o anel onde a tripulação morava.
                'vizinho' => 'anel_habitacional',
                'chave' => [
                    'comum' => 'silo_semente_dormente',
                    'raro' => 'silo_banco_genetico',
                ],
                'nome' => [
                    'comum' => 'Semente Dormente',
                    'raro' => 'Banco Genético do Silo',
                ],
                'descricao' => [
                    'comum' => 'Um lote de sementes que atravessou o vazio dormindo. Acordou com fome de solo, e a Fazenda agradece.',
                    'raro' => 'O banco genético que a Endurance trouxe da Terra para o dia da chegada. Este é o dia.',
                ],
                // D-256: o que só se acha escavando — a loja não vende.
                'escavacao' => [
                    'chave' => [
                        'comum' => 'silo_escavado_saca_selada',
                        'raro' => 'silo_escavado_estufa_de_bordo',
                    ],
                    'nome' => [
                        'comum' => 'Saca Selada do Silo',
                        'raro' => 'Estufa de Bordo Soterrada',
                    ],
                    'descricao' => [
                        'comum' => 'Uma saca de adubo selada a vácuo, achada sob as placas do silo. A data no lacre é de antes da partida.',
                        'raro' => 'A estufa de testes da tripulação, inteira debaixo da poeira. As lâmpadas acendem. A Fazenda que a recebe não reconhece o próprio solo.',
                    ],
                ],
            ],
            'anel_habitacional' => [
                'efeito' => Efeitos::PRODUCAO_BONUS,
                'alvo' => 'captacao_de_agua',
                'vizinho' => 'silo_suprimentos',
                'chave' => [
                    'comum' => 'habitacional_filtro_de_ciclo',
                    'raro' => 'habitacional_condensador_do_anel',
                ],
                'nome' => [
                    'comum' => 'Filtro de Ciclo Fechado',
                    'raro' => 'Condensador do Anel',
                ],
                'descricao' => [
                    'comum' => 'Filtro dos alojamentos: cada gota que a tripulação bebeu passou por um destes umas mil vezes.',
                    'raro' => 'O condensador que tirava água do próprio ar respirado por quatro mil pessoas. Em Fertways ele tira do que houver.',
                ],
            ],
            'modulo_medico' => [
                'efeito' => Efeitos::PRODUCAO_BONUS,
                'alvo' => 'refinaria_quimica',
                'vizinho' => 'baia_criogenica',
                'chave' => [
                    'comum' => 'medico_kit_de_reagentes',
                    'raro' => 'medico_sintetizador_de_farmacos',
                ],
                'nome' => [
                    'comum' => 'Kit de Reagentes',
                    'raro' => 'Sintetizador de Fármacos',
                ],
                'descricao' => [
                    'comum' => 'Reagentes da enfermaria, ainda lacrados. A Refinaria Química os usa para coisas que a enfermaria não aprovaria.',
                    'raro' => 'O sintetizador do Módulo Médico. Fazia remédio a partir de quase nada, e continua fazendo — só que agora chamam de composto.',
                ],
            ],
        ];
    }
}
