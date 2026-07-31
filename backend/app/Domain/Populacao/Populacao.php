<?php

namespace App\Domain\Populacao;

use App\Models\Colony;
use Illuminate\Support\Facades\DB;

/**
 * O modelo de população (A2.2.1): os cinco estados que a fase pede.
 *
 * - **total** — o que está guardado em `colonies.populacao`;
 * - **capacidade** — o teto habitacional, da Estrutura de Sobrevivência;
 * - **alocada em construções** — a soma dos requisitos do que está erguido;
 * - **alocada em zonas** — a soma dos requisitos das zonas ocupadas;
 * - **disponível** — total menos as duas alocações.
 *
 * ## Só o total é guardado; o resto é derivado
 *
 * Guardar contadores paralelos de "alocada" criaria duas verdades sobre a mesma coisa, e a segunda
 * dessincroniza na primeira demolição que alguém esquecer de descontar. Derivar custa uma consulta
 * e nunca mente.
 *
 * ## Disponível pode ser NEGATIVO, de propósito
 *
 * É o estado de quem foi grandfatherizado com folga curta e ergueu uma construção a mais, ou de quem
 * teve a população encolhida por escassez. Zerar o negativo esconderia exatamente a situação que o
 * jogo precisa mostrar ao colono — e a regra do §6.6 é clara: abaixo do exigido **degrada, não se
 * perde**. Quem lê este número decide o que fazer com ele; quem o produz não deve maquiá-lo.
 */
class Populacao
{
    public function __construct(private Parametros $parametros) {}

    /**
     * @return array{total:int, capacidade:int, em_construcoes:int, em_zonas:int, disponivel:int}
     */
    public function estado(Colony $colonia): array
    {
        $total = (int) $colonia->populacao;
        $construcoes = $this->alocadaEmConstrucoes($colonia);
        $zonas = $this->alocadaEmZonas($colonia);

        return [
            'total' => $total,
            'capacidade' => $this->capacidade($colonia),
            'em_construcoes' => $construcoes,
            'em_zonas' => $zonas,
            'disponivel' => $total - $construcoes - $zonas,
        ];
    }

    /**
     * O teto habitacional, dado pela Estrutura de Sobrevivência.
     *
     * É o que finalmente dá função à construção que o `Funcoes::CATALOGO` descreve como
     * `'efeito' => 'nenhum'`, com a nota honesta de que "o GDD não diz quantos colonos ela abriga".
     * O quanto continua sendo arbitragem — mas o QUE ela faz deixa de ser nada.
     */
    public function capacidade(Colony $colonia): int
    {
        $nivel = (int) ($colonia->buildings
            ->firstWhere('type', 'estrutura_de_sobrevivencia')?->level ?? 0);

        return $this->parametros->capacidade($nivel);
    }

    /**
     * Soma dos operadores exigidos pelo que a colônia tem erguido.
     *
     * Tabela **esparsa**: construção sem linha não exige ninguém. É o padrão seguro — o requisito
     * precisa ser afirmado, nunca herdado de um default que ligaria mão de obra em coisas que o
     * desenho nunca pensou em ligar.
     */
    public function alocadaEmConstrucoes(Colony $colonia): int
    {
        $erguidas = $colonia->buildings->map(fn ($b) => [$b->type, (int) $b->level]);

        if ($erguidas->isEmpty()) {
            return 0;
        }

        $requisitos = DB::table('building_operator_requirements')
            ->get(['building_type', 'level', 'operadores'])
            ->keyBy(fn ($r) => $r->building_type.'|'.$r->level);

        $soma = 0;

        foreach ($erguidas as [$tipo, $nivel]) {
            /*
             * O requisito é o do nível ATUAL, e não a soma dos níveis percorridos: uma Fazenda
             * nível 5 pede a equipe de uma Fazenda nível 5, não a de todas as fazendas de 1 a 5.
             * Somar a escada faria o requisito explodir com o progresso e tornaria a expansão
             * impossível por acidente aritmético.
             */
            $soma += (int) ($requisitos->get($tipo.'|'.$nivel)->operadores ?? 0);
        }

        return $soma;
    }

    /** §7.4: pequena comparada à população da colônia — humanos supervisionam automação. */
    public function alocadaEmZonas(Colony $colonia): int
    {
        $niveis = DB::table('neutral_zones')
            ->where('owner_colony_id', $colonia->id)
            ->pluck('level');

        $soma = 0;

        foreach ($niveis as $nivel) {
            $soma += $this->parametros->operadoresDeZona((int) $nivel);
        }

        return $soma;
    }

    /**
     * Quanta população seria necessária para operar tudo o que a colônia JÁ tem (A2.2.6 / §6.7).
     *
     * É a conta do grandfathering, e a folga vem por cima: *"nenhuma colônia existente pode parar de
     * produzir por uma regra que não existia quando ela foi construída"*.
     */
    public function necessariaParaOQueJaTem(Colony $colonia): int
    {
        $preciso = $this->alocadaEmConstrucoes($colonia) + $this->alocadaEmZonas($colonia);
        $folga = (int) $this->parametros->todos()->migracao_folga_bps;

        return (int) ceil($preciso * (10000 + $folga) / 10000);
    }
}
