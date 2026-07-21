<?php

namespace Database\Seeders;

use App\Models\MissionTemplate;
use Illuminate\Database\Seeder;

/**
 * O catálogo de missões do §06 (D-78): 5 de tutoria, 30+ diárias, 8 semanais.
 *
 * **Recompensas GENEROSAS por arbitragem do usuário** (2× a proposta modesta): diária ~6 F$ ou
 * ~300 XP ou recursos; semanal ~40 F$ + 1.000 XP; tutoria ~30 F$ + recursos no total. O risco
 * anotado: recompensa de missão é EMISSÃO (§06 a lista entre as entradas de Fert$) — se o Fert$
 * inflar, é o primeiro torniquete. Ajusta-se aqui e se re-semeia (`updateOrCreate` pela chave), ou
 * desliga-se um template pelo painel (`ativa`).
 *
 * Idempotente: roda quantas vezes for preciso; a chave é o contrato.
 */
class MissionTemplateSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->catalogo() as $t) {
            MissionTemplate::updateOrCreate(['chave' => $t['chave']], $t);
        }

        $this->encadearNarrativa();
    }

    private function catalogo(): array
    {
        $f = fn (float $fert) => (int) round($fert * 1_000_000);

        // ── A TUTORIA (§06: "5 missões — dias 1 a 3"; §03 desenha os gestos) ──
        $tutoria = [
            ['chave' => 'tut_primeira_obra', 'titulo' => 'A primeira obra',
             'descricao' => 'Conclua 1 nível de construção — enfileire um upgrade e espere o tick entregar.',
             'acao' => 'obra_concluida', 'meta' => 1, 'fert' => 6.0, 'xp' => 100, 'rec' => null],
            ['chave' => 'tut_primeiro_despacho', 'titulo' => 'Pé na estrada',
             'descricao' => 'Despache um veículo para qualquer destino — o planeta é físico, e tudo viaja.',
             'acao' => 'despacho', 'meta' => 1, 'fert' => 6.0, 'xp' => 100, 'rec' => null],
            ['chave' => 'tut_primeiro_lote', 'titulo' => 'O primeiro lote',
             'descricao' => 'Compre no Mercado Central — os seus 50 Fert$ iniciais existem para isto (§03).',
             'acao' => 'mercado_executado', 'meta' => 1, 'fert' => 6.0, 'xp' => 100,
             'rec' => ['ligas_metalicas' => 200]],
            ['chave' => 'tut_primeira_oferta', 'titulo' => 'Do outro lado do balcão',
             'descricao' => 'Coloque uma ordem no Mercado Central — venda ou compra, a vitrine é sua.',
             'acao' => 'ordem_colocada', 'meta' => 1, 'fert' => 6.0, 'xp' => 100, 'rec' => null],
            ['chave' => 'tut_primeira_voz', 'titulo' => 'No rádio do planeta',
             'descricao' => 'Fale num canal público do chat — Fertways é feito de vizinhos.',
             'acao' => 'chat_mensagem', 'meta' => 1, 'fert' => 6.0, 'xp' => 100,
             'rec' => ['biocombustivel' => 100]],
        ];

        // ── AS DIÁRIAS (pool de 30+; "Fert$, recursos OU XP" — cada molde paga UMA classe) ──
        $diarias = [
            // Obras
            ['chave' => 'dia_obra_1', 'titulo' => 'Canteiro vivo', 'descricao' => 'Conclua 1 nível de construção.', 'acao' => 'obra_concluida', 'meta' => 1, 'fert' => 6.0, 'xp' => 0, 'rec' => null],
            ['chave' => 'dia_obra_2', 'titulo' => 'Semana de obras', 'descricao' => 'Conclua 2 níveis de construção.', 'acao' => 'obra_concluida', 'meta' => 2, 'fert' => 0, 'xp' => 300, 'rec' => null],
            ['chave' => 'dia_obra_3', 'titulo' => 'Mestre de obras', 'descricao' => 'Conclua 3 níveis de construção.', 'acao' => 'obra_concluida', 'meta' => 3, 'fert' => 0, 'xp' => 0, 'rec' => ['metal_bruto' => 800]],
            // Logística
            ['chave' => 'dia_despacho_1', 'titulo' => 'Rodas na estrada', 'descricao' => 'Despache 1 viagem.', 'acao' => 'despacho', 'meta' => 1, 'fert' => 0, 'xp' => 200, 'rec' => null],
            ['chave' => 'dia_despacho_3', 'titulo' => 'Frota em movimento', 'descricao' => 'Despache 3 viagens.', 'acao' => 'despacho', 'meta' => 3, 'fert' => 6.0, 'xp' => 0, 'rec' => null],
            ['chave' => 'dia_despacho_5', 'titulo' => 'Dia de pico', 'descricao' => 'Despache 5 viagens.', 'acao' => 'despacho', 'meta' => 5, 'fert' => 0, 'xp' => 0, 'rec' => ['energia' => 300]],
            ['chave' => 'dia_frete', 'titulo' => 'Deixa com o governo', 'descricao' => 'Use o frete público do Mercado 1 vez.', 'acao' => 'frete_publico', 'meta' => 1, 'fert' => 0, 'xp' => 250, 'rec' => null],
            ['chave' => 'dia_manutencao', 'titulo' => 'Oficina aberta', 'descricao' => 'Faça a manutenção de um veículo.', 'acao' => 'manutencao', 'meta' => 1, 'fert' => 6.0, 'xp' => 0, 'rec' => null],
            // Mercado
            ['chave' => 'dia_mercado_1', 'titulo' => 'Negócio fechado', 'descricao' => 'Feche 1 negócio no Mercado Central (acima de 500 F$).', 'acao' => 'mercado_executado', 'meta' => 1, 'fert' => 6.0, 'xp' => 0, 'rec' => null],
            ['chave' => 'dia_mercado_2', 'titulo' => 'Dia de pregão', 'descricao' => 'Feche 2 negócios no Mercado Central.', 'acao' => 'mercado_executado', 'meta' => 2, 'fert' => 0, 'xp' => 350, 'rec' => null],
            ['chave' => 'dia_ordem_1', 'titulo' => 'Na vitrine', 'descricao' => 'Coloque 1 ordem no Mercado Central.', 'acao' => 'ordem_colocada', 'meta' => 1, 'fert' => 0, 'xp' => 200, 'rec' => null],
            ['chave' => 'dia_ordem_2', 'titulo' => 'Banca cheia', 'descricao' => 'Coloque 2 ordens no Mercado Central.', 'acao' => 'ordem_colocada', 'meta' => 2, 'fert' => 6.0, 'xp' => 0, 'rec' => null],
            ['chave' => 'dia_acordo', 'titulo' => 'Palavra cumprida', 'descricao' => 'Tenha 1 Acordo de Troca executado (acima de 500 F$).', 'acao' => 'acordo_executado', 'meta' => 1, 'fert' => 8.0, 'xp' => 0, 'rec' => null],
            // Guerra e defesa
            ['chave' => 'dia_unidade_1', 'titulo' => 'Reforços na linha', 'descricao' => 'Fabrique 1 unidade no Quartel.', 'acao' => 'fabricar_unidade', 'meta' => 1, 'fert' => 0, 'xp' => 250, 'rec' => null],
            ['chave' => 'dia_unidade_3', 'titulo' => 'Linha de produção', 'descricao' => 'Fabrique 3 unidades no Quartel.', 'acao' => 'fabricar_unidade', 'meta' => 3, 'fert' => 6.0, 'xp' => 0, 'rec' => null],
            ['chave' => 'dia_niobio', 'titulo' => 'Contrato do governo', 'descricao' => 'Compre Nióbio Alienígena do governo.', 'acao' => 'niobio_comprado', 'meta' => 1, 'fert' => 0, 'xp' => 250, 'rec' => null],
            ['chave' => 'dia_combate', 'titulo' => 'Dia de glória', 'descricao' => 'Vença 1 combate — atacando, defendendo ou rompendo um cerco.', 'acao' => 'combate_vencido', 'meta' => 1, 'fert' => 12.0, 'xp' => 0, 'rec' => null],
            ['chave' => 'dia_drone_1', 'titulo' => 'Olhos no céu', 'descricao' => 'Mande um Drone em missão de reconhecimento.', 'acao' => 'missao_drone', 'meta' => 1, 'fert' => 0, 'xp' => 250, 'rec' => null],
            ['chave' => 'dia_drone_2', 'titulo' => 'Vigilância dupla', 'descricao' => 'Mande 2 missões de Drone.', 'acao' => 'missao_drone', 'meta' => 2, 'fert' => 6.0, 'xp' => 0, 'rec' => null],
            // Território
            ['chave' => 'dia_zona', 'titulo' => 'Bandeira fincada', 'descricao' => 'Ocupe uma zona neutra (exige o marco 20).', 'acao' => 'zona_ocupada', 'meta' => 1, 'fert' => 12.0, 'xp' => 0, 'rec' => null],
            // Presença
            ['chave' => 'dia_chat_1', 'titulo' => 'Bom dia, Fertways', 'descricao' => 'Fale num canal público do chat.', 'acao' => 'chat_mensagem', 'meta' => 1, 'fert' => 0, 'xp' => 150, 'rec' => null],
            ['chave' => 'dia_chat_3', 'titulo' => 'Voz ativa', 'descricao' => 'Participe da conversa: 3 mensagens públicas.', 'acao' => 'chat_mensagem', 'meta' => 3, 'fert' => 0, 'xp' => 250, 'rec' => null],
            // Variações com recompensa em recursos (a 3ª classe publicada)
            ['chave' => 'dia_obra_rec', 'titulo' => 'Ajuda de custo', 'descricao' => 'Conclua 1 nível de construção.', 'acao' => 'obra_concluida', 'meta' => 1, 'fert' => 0, 'xp' => 0, 'rec' => ['ligas_metalicas' => 400]],
            ['chave' => 'dia_despacho_rec', 'titulo' => 'Diária do estradeiro', 'descricao' => 'Despache 2 viagens.', 'acao' => 'despacho', 'meta' => 2, 'fert' => 0, 'xp' => 0, 'rec' => ['biocombustivel' => 300]],
            ['chave' => 'dia_mercado_rec', 'titulo' => 'Comissão em espécie', 'descricao' => 'Feche 1 negócio no Mercado Central.', 'acao' => 'mercado_executado', 'meta' => 1, 'fert' => 0, 'xp' => 0, 'rec' => ['compostos_quimicos' => 250]],
            ['chave' => 'dia_ordem_rec', 'titulo' => 'Vitrine patrocinada', 'descricao' => 'Coloque 1 ordem no Mercado Central.', 'acao' => 'ordem_colocada', 'meta' => 1, 'fert' => 0, 'xp' => 0, 'rec' => ['agua' => 500]],
            ['chave' => 'dia_unidade_rec', 'titulo' => 'Suprimento militar', 'descricao' => 'Fabrique 2 unidades no Quartel.', 'acao' => 'fabricar_unidade', 'meta' => 2, 'fert' => 0, 'xp' => 0, 'rec' => ['componentes_eletronicos' => 150]],
            ['chave' => 'dia_chat_rec', 'titulo' => 'Correspondente local', 'descricao' => 'Fale 2 vezes nos canais públicos.', 'acao' => 'chat_mensagem', 'meta' => 2, 'fert' => 0, 'xp' => 0, 'rec' => ['oxigenio' => 400]],
            ['chave' => 'dia_manutencao_xp', 'titulo' => 'Zelo de frota', 'descricao' => 'Faça a manutenção de um veículo.', 'acao' => 'manutencao', 'meta' => 1, 'fert' => 0, 'xp' => 300, 'rec' => null],
            ['chave' => 'dia_frete_fert', 'titulo' => 'Cliente do correio', 'descricao' => 'Use o frete público do Mercado 1 vez.', 'acao' => 'frete_publico', 'meta' => 1, 'fert' => 6.0, 'xp' => 0, 'rec' => null],
            ['chave' => 'dia_despacho_xp', 'titulo' => 'Logística fina', 'descricao' => 'Despache 4 viagens.', 'acao' => 'despacho', 'meta' => 4, 'fert' => 0, 'xp' => 350, 'rec' => null],
            ['chave' => 'dia_obra_fert2', 'titulo' => 'Empreitada', 'descricao' => 'Conclua 2 níveis de construção.', 'acao' => 'obra_concluida', 'meta' => 2, 'fert' => 8.0, 'xp' => 0, 'rec' => null],
            ['chave' => 'dia_mercado_3', 'titulo' => 'Pregoeiro', 'descricao' => 'Feche 3 negócios no Mercado Central.', 'acao' => 'mercado_executado', 'meta' => 3, 'fert' => 10.0, 'xp' => 0, 'rec' => null],
        ];

        // ── AS SEMANAIS (qua 07h → ter 23h59; metas de semana, prêmio de semana) ──
        $semanais = [
            ['chave' => 'sem_obras', 'titulo' => 'A colônia cresce', 'descricao' => 'Conclua 8 níveis de construção na semana.', 'acao' => 'obra_concluida', 'meta' => 8, 'fert' => 40.0, 'xp' => 1000, 'rec' => null],
            ['chave' => 'sem_logistica', 'titulo' => 'Semana nas estradas', 'descricao' => 'Despache 15 viagens na semana.', 'acao' => 'despacho', 'meta' => 15, 'fert' => 40.0, 'xp' => 1000, 'rec' => null],
            ['chave' => 'sem_mercado', 'titulo' => 'Barão do pregão', 'descricao' => 'Feche 6 negócios no Mercado Central na semana.', 'acao' => 'mercado_executado', 'meta' => 6, 'fert' => 40.0, 'xp' => 1000, 'rec' => null],
            ['chave' => 'sem_acordos', 'titulo' => 'Palavra de ferro', 'descricao' => 'Tenha 3 Acordos executados na semana.', 'acao' => 'acordo_executado', 'meta' => 3, 'fert' => 40.0, 'xp' => 1000, 'rec' => null],
            ['chave' => 'sem_exercito', 'titulo' => 'Arsenal da semana', 'descricao' => 'Fabrique 8 unidades no Quartel.', 'acao' => 'fabricar_unidade', 'meta' => 8, 'fert' => 40.0, 'xp' => 1000, 'rec' => null],
            ['chave' => 'sem_guerra', 'titulo' => 'Campanha vitoriosa', 'descricao' => 'Vença 2 combates na semana.', 'acao' => 'combate_vencido', 'meta' => 2, 'fert' => 50.0, 'xp' => 1500, 'rec' => null],
            ['chave' => 'sem_reconhecimento', 'titulo' => 'Olhos em toda parte', 'descricao' => 'Mande 5 missões de Drone na semana.', 'acao' => 'missao_drone', 'meta' => 5, 'fert' => 40.0, 'xp' => 1000, 'rec' => null],
            ['chave' => 'sem_presenca', 'titulo' => 'Cidadão de Fertways', 'descricao' => 'Fale 10 vezes nos canais públicos na semana.', 'acao' => 'chat_mensagem', 'meta' => 10, 'fert' => 0, 'xp' => 1200, 'rec' => null],
        ];

        // ── FEDERAÇÃO (§06: "2 por semana", cooperativa; D-116) — meta grande de propósito: uma
        // colônia sozinha dificilmente bate 30 despachos ou 5 combates na semana, mas o grupo sim.
        // "Cooperativa dá mais" (GDD) lido como recompensa maior que a semanal equivalente — sem
        // fórmula de escala por nº de participantes, que o GDD não publica.
        $federacao = [
            ['chave' => 'fed_logistica', 'titulo' => 'Comboio da Aliança',
             'descricao' => 'A federação despacha 30 viagens na semana — cada membro contribui, o placar é de todos.',
             'acao' => 'despacho', 'meta' => 30, 'fert' => 0, 'xp' => 2000, 'rec' => null],
            ['chave' => 'fed_guerra', 'titulo' => 'Defesa Conjunta',
             'descricao' => 'A federação vence 5 combates na semana — cada aliado que luta soma para o grupo inteiro.',
             'acao' => 'combate_vencido', 'meta' => 5, 'fert' => 0, 'xp' => 2500, 'rec' => null],
        ];

        $montar = fn (array $lista, string $categoria) => array_map(fn ($t) => [
            'chave' => $t['chave'],
            'categoria' => $categoria,
            'titulo' => $t['titulo'],
            'descricao' => $t['descricao'],
            'acao' => $t['acao'],
            'meta' => $t['meta'],
            'recompensa_fert_micro' => $f($t['fert']),
            'recompensa_xp' => $t['xp'],
            'recompensa_recursos' => $t['rec'],
            'ativa' => true,
        ], $lista);

        return array_merge(
            $montar($tutoria, 'tutoria'),
            $montar($diarias, 'diaria'),
            $montar($semanais, 'semanal'),
            $montar($federacao, 'federacao'),
            $montar($this->narrativa(), 'narrativa'),
        );
    }

    /**
     * A narrativa da Endurance (D-140) — 4 capítulos, encadeados por `requer_template_id`. O GDD
     * só publica o rótulo ("fonte de missões narrativas", §02/§16.2) — tema, ordem, ação escutada
     * e recompensa são 100% arbitragem, aqui documentada, e outra vez em `docs/decisoes.md` D-140.
     *
     * Cada capítulo reaproveita uma AÇÃO JÁ EXISTENTE — só o 1º ganhou gancho novo
     * (`comprar_item_endurance`, para prender a narrativa a um ato de verdade na própria
     * Endurance). Os demais tematizam a escavação/reconstrução sobre ações genéricas que já têm
     * gancho (mercado, obra, despacho) — o mesmo espírito de "não inventar mecânica nova pelo
     * catálogo" que os efeitos da Loja de Peças já seguem (D-135).
     */
    private function narrativa(): array
    {
        return [
            [
                'chave' => 'end_cap1_primeiro_achado', 'titulo' => 'O Primeiro Achado',
                'descricao' => 'Os destroços da Endurance guardam mais que sucata. Compre a primeira '
                    .'peça recuperável na Loja de Peças — o começo de uma escavação que vai revelar '
                    .'o que a nave-mãe ainda tem a contar.',
                'acao' => 'comprar_item_endurance', 'meta' => 1, 'fert' => 10.0, 'xp' => 150, 'rec' => null,
            ],
            [
                'chave' => 'end_cap2_preco_da_escavacao', 'titulo' => 'O Preço da Escavação', 'requer' => 'end_cap1_primeiro_achado',
                'descricao' => 'Vasculhar os destroços custa caro. Feche 3 negócios no Mercado Central '
                    .'para bancar o resto da escavação — cada Fert$ movimentado é combustível para ir '
                    .'mais fundo no casco.',
                'acao' => 'mercado_executado', 'meta' => 3, 'fert' => 15.0, 'xp' => 300, 'rec' => null,
            ],
            [
                'chave' => 'end_cap3_reconstrucao', 'titulo' => 'Reconstrução', 'requer' => 'end_cap2_preco_da_escavacao',
                'descricao' => 'Com o que foi recuperado, é hora de erguer. Conclua 2 níveis de '
                    .'construção — as peças da Endurance não valem nada empilhadas; valem integradas '
                    .'à colônia que ela ajudou a fundar.',
                'acao' => 'obra_concluida', 'meta' => 2, 'fert' => 20.0, 'xp' => 400,
                'rec' => ['metal_bruto' => 500],
            ],
            [
                'chave' => 'end_cap4_o_legado', 'titulo' => 'O Legado da Endurance', 'requer' => 'end_cap3_reconstrucao',
                'descricao' => 'A escavação termina, mas o legado viaja. Despache 2 cargas — é assim '
                    .'que o que foi encontrado nos destroços chega a quem precisa dele, pelo mesmo '
                    .'planeta que a Endurance um dia tentou alcançar.',
                'acao' => 'despacho', 'meta' => 2, 'fert' => 50.0, 'xp' => 1000,
                'rec' => ['componentes_eletronicos' => 100],
            ],
        ];
    }

    /**
     * A cadeia narrativa (D-140) precisa de dois passos: o molde referencia o ANTERIOR pela
     * `chave`, mas `requer_template_id` só existe depois de o anterior ter ID — não dá para
     * resolver isso no mesmo `updateOrCreate` que cria os dois. Rodado depois de `catalogo()`
     * já ter semeado tudo, então toda `chave` da cadeia já tem linha (e id) no banco.
     */
    private function encadearNarrativa(): void
    {
        $porChave = MissionTemplate::where('categoria', 'narrativa')->pluck('id', 'chave');

        foreach ($this->narrativa() as $t) {
            if (! isset($t['requer'])) {
                continue;
            }

            MissionTemplate::where('chave', $t['chave'])
                ->update(['requer_template_id' => $porChave[$t['requer']] ?? null]);
        }
    }
}
