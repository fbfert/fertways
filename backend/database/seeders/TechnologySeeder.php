<?php

namespace Database\Seeders;

use App\Domain\Endurance\EfeitosDaEndurance;
use App\Models\Technology;
use Illuminate\Database\Seeder;

/**
 * A árvore de pesquisa inicial (A2.3) — **uma tecnologia por trilha, todas HIPÓTESE**.
 *
 * ## Por que uma só por trilha, e não uma árvore cheia
 *
 * O GDD **não publica nada** sobre pesquisa: nem tecnologia, nem custo, nem tempo, nem efeito. O
 * `Funcoes::CATALOGO` registra isso na nota do Laboratório desde sempre. Cadastrar quarenta
 * tecnologias seria inventar quarenta conjuntos de números, e o §8.3 diz o que decide se a árvore
 * presta: *"se a maioria dos jogadores pesquisar a mesma sequência, a árvore falhou"*. Isso se
 * responde com simulação e com escolha do usuário, não com volume.
 *
 * O que entra aqui é o **primeiro degrau de cada uma das oito trilhas**: o bastante para a estrutura
 * ser exercitável de ponta a ponta, para a bifurcação existir de verdade, e pouco o suficiente para
 * ninguém confundir com desenho fechado.
 *
 * ## Os efeitos usam o vocabulário do `EfeitosDaEndurance`
 *
 * Mesmas chaves, mesmos alvos, mesmos tetos. Um vocabulário paralelo faria duas fontes de bônus com
 * regras diferentes para a mesma coisa.
 *
 * Idempotente pela `chave`, como o `MissionTemplateSeeder`.
 */
class TechnologySeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->catalogo() as $t) {
            Technology::updateOrCreate(['chave' => $t['chave']], $t);
        }
    }

    private function catalogo(): array
    {
        /*
         * ⚠️ Custo, duração e efeito abaixo são **chute com forma de número**. Existem para o motor
         * ter o que exercitar e para a trilha A2.S ter de onde partir. Nenhum foi promovido, e
         * `research_settings.ativo` está `false` justamente por isso.
         */
        $h = fn (int $horas) => $horas * 3600;

        $efeito = fn (string $tipo, string $alvo, int $bps) => [
            ['tipo' => $tipo, 'alvo' => $alvo, 'valor_bps' => $bps],
        ];

        return [
            [
                'chave' => 'tec_energia_1', 'trilha' => 'energia',
                'nome' => 'Regulação de Reator',
                'descricao' => 'Ajuste fino do Reator de Energia: mais saída pelo mesmo combustível.',
                'custo_json' => ['metal_bruto' => 200, 'componentes_eletronicos' => 20],
                'duracao_segundos' => $h(6), 'nivel_maximo' => 3, 'laboratorio_minimo' => 1,
                'efeitos_json' => $efeito(EfeitosDaEndurance::PRODUCAO_BONUS, 'reator_de_energia', 300),
            ],
            [
                'chave' => 'tec_biosfera_1', 'trilha' => 'biosfera',
                'nome' => 'Cultivo Pressurizado',
                'descricao' => 'Estufas de ciclo fechado aumentam a colheita da Fazenda.',
                'custo_json' => ['biomassa' => 150, 'ligas_metalicas' => 80],
                'duracao_segundos' => $h(6), 'nivel_maximo' => 3, 'laboratorio_minimo' => 1,
                'efeitos_json' => $efeito(EfeitosDaEndurance::PRODUCAO_BONUS, 'fazenda', 300),
            ],
            [
                'chave' => 'tec_industria_1', 'trilha' => 'industria',
                'nome' => 'Metalurgia Aplicada',
                'descricao' => 'Fornos mais eficientes na Refinaria Química.',
                'custo_json' => ['metal_bruto' => 300, 'energia' => 100],
                'duracao_segundos' => $h(8), 'nivel_maximo' => 3, 'laboratorio_minimo' => 2,
                'efeitos_json' => $efeito(EfeitosDaEndurance::PRODUCAO_BONUS, 'refinaria_quimica', 300),
            ],
            [
                'chave' => 'tec_logistica_1', 'trilha' => 'logistica',
                'nome' => 'Suspensão Reforçada',
                'descricao' => 'Todo veículo civil carrega mais pela mesma viagem.',
                'custo_json' => ['ligas_metalicas' => 200, 'componentes_eletronicos' => 30],
                'duracao_segundos' => $h(8), 'nivel_maximo' => 3, 'laboratorio_minimo' => 2,
                'efeitos_json' => $efeito(EfeitosDaEndurance::CAPACIDADE_VEICULO, EfeitosDaEndurance::ALVO_TODOS_OS_VEICULOS, 250),
            ],
            [
                'chave' => 'tec_comercio_1', 'trilha' => 'comercio',
                'nome' => 'Escrituração Aduaneira',
                'descricao' => 'Menos tributo em cada entrega — o §25.8 continua cobrando, só que menos.',
                'custo_json' => ['componentes_eletronicos' => 60],
                'duracao_segundos' => $h(10), 'nivel_maximo' => 2, 'laboratorio_minimo' => 2,
                'efeitos_json' => $efeito(EfeitosDaEndurance::DESCONTO_TRIBUTO, EfeitosDaEndurance::ALVO_GLOBAL, 200),
            ],
            [
                'chave' => 'tec_ciencia_1', 'trilha' => 'ciencia',
                'nome' => 'Método Experimental',
                'descricao' => 'O Laboratório trabalha melhor consigo mesmo.',
                'custo_json' => ['componentes_eletronicos' => 40, 'quartzo_piezoeletrico' => 10],
                'duracao_segundos' => $h(12), 'nivel_maximo' => 1, 'laboratorio_minimo' => 3,
                'efeitos_json' => $efeito(EfeitosDaEndurance::PRODUCAO_BONUS, 'laboratorio', 200),
            ],
            [
                'chave' => 'tec_defesa_1', 'trilha' => 'defesa',
                'nome' => 'Blindagem Modular',
                'descricao' => 'A Torre de Defesa aguenta mais antes de calar.',
                'custo_json' => ['ligas_metalicas' => 250, 'niobio_alienigena' => 5],
                'duracao_segundos' => $h(10), 'nivel_maximo' => 3, 'laboratorio_minimo' => 2,
                'efeitos_json' => $efeito(EfeitosDaEndurance::PRODUCAO_BONUS, 'torre_de_defesa', 200),
            ],
            [
                'chave' => 'tec_territorio_1', 'trilha' => 'territorio',
                'nome' => 'Prospecção Profunda',
                'descricao' => 'A Mina Local encontra mais no mesmo veio.',
                'custo_json' => ['metal_bruto' => 250, 'energia' => 80],
                'duracao_segundos' => $h(8), 'nivel_maximo' => 3, 'laboratorio_minimo' => 1,
                'efeitos_json' => $efeito(EfeitosDaEndurance::PRODUCAO_BONUS, 'mina_local', 300),
            ],
        ];
    }
}
