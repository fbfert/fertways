<?php

namespace App\Domain\Logistics;

use App\Domain\Marco\Curva;
use App\Domain\Populacao\Parametros;
use App\Domain\Zona\Operadores;
use App\Models\Colony;
use App\Models\NeutralZone;
use Illuminate\Support\Facades\DB;

/**
 * O que ocupar uma zona neutra exige, e o que falta a ESTA colônia (A2.V4, D-224).
 *
 * ## Por que existe
 *
 * O painel do mapa oferecia um botão "Ocupar" **sempre habilitado**, com uma frase de custo escrita
 * à mão: *"800 Metal Bruto + 300 Fert$ (Posto de Comando) e 20 Robôs Mineradores"*. Duas mentiras
 * numa linha só:
 *
 * - o Metal Bruto real é **1.020** — os 800 do Posto mais 220 dos robôs, que a frase escondia
 *   atrás da palavra "robôs";
 * - e não citava **1.200 Ligas Metálicas nem 400 Componentes Eletrônicos**, que são a maior parte
 *   da conta.
 *
 * O jogador clicava, o servidor recusava, e o motivo aparecia só depois — um de cada vez, na ordem
 * em que o comando confere. Medido no dia em que o D-223 abriu o portão do marco: os dois líderes
 * humanos ainda estavam bloqueados por **componentes, Fert$ e população livre**, e nada na tela
 * dizia isso.
 *
 * ## A regra e a tela leem o MESMO código
 *
 * `custoDeRecursos()` era privado do `OcuparZonaNeutra` e agora mora aqui, para que a tela não
 * reimplemente a conta. Frase de custo escrita à mão é o que produz a divergência acima: ela nasce
 * certa e envelhece sozinha quando o custo do Robô Minerador muda no painel do operador.
 */
class RequisitosDeOcupacao
{
    /** 1 Fert$ = 1.000.000 de micro (a mesma escala de `colonies.fert_micro`). */
    private const MICRO = 1_000_000;

    /** O marco do §05 que abre território (Desbravador). Ver `OcuparZonaNeutra`. */
    public const MARCO = 20;

    public function __construct(
        private Operadores $operadores,
        private Parametros $parametros,
    ) {
    }

    /**
     * O custo material de ocupar: o Posto, mais a guarnição inicial de Robôs Mineradores.
     *
     * ⚠️ Lê o custo do robô do **catálogo** (`building_specs`), e não de constante: ele é editável
     * pelo operador (D-108), e uma cópia aqui viraria mentira no dia em que ele mudasse.
     *
     * @return array<string,int>
     */
    public function custoDeRecursos(): array
    {
        $custoRobo = json_decode(
            DB::table('building_specs')
                ->where('building_type', 'robo_minerador')->where('level', 1)
                ->value('cost_json') ?? '{}',
            true,
        );

        $custo = ['metal_bruto' => NeutralZone::POSTO_METAL_BRUTO];

        foreach ($custoRobo as $recurso => $qtd) {
            $custo[$recurso] = ($custo[$recurso] ?? 0) + $qtd * NeutralZone::GUARNICAO_INICIAL;
        }

        return $custo;
    }

    /**
     * O retrato completo: o que custa, e o que falta a esta colônia.
     *
     * Devolve **todos** os impedimentos de uma vez, e não o primeiro. O comando confere em ordem e
     * para no primeiro erro — que é o certo para uma transação e péssimo para uma tela: o jogador
     * conseguiria Fert$, clicaria de novo, e só então descobriria que faltam colonos.
     *
     * @return array{
     *     marco: int, fert: int, recursos: array<string,int>, operadores: int,
     *     zonas_ocupadas: int, teto_de_zonas: int,
     *     falta: list<array{tipo: string, o_que: string, tem: int, precisa: int}>,
     *     pode: bool
     * }
     */
    public function para(Colony $colonia): array
    {
        $custo = $this->custoDeRecursos();
        $estoque = $colonia->resources()->pluck('amount', 'resource_type');
        $possuidas = NeutralZone::where('owner_colony_id', $colonia->id)->count();
        $exigidos = $this->parametros->ativo() ? $this->parametros->operadoresDeZona(1) : 0;

        $falta = [];

        $marco = Curva::marco((int) $colonia->xp);
        if ($marco < self::MARCO) {
            $falta[] = [
                'tipo' => 'marco', 'o_que' => 'Marco '.self::MARCO.' (Desbravador)',
                'tem' => (int) $colonia->xp, 'precisa' => Curva::xpDoMarco(self::MARCO),
            ];
        }

        $fert = (int) floor($colonia->fert_micro / self::MICRO);
        if ($fert < NeutralZone::POSTO_FERT) {
            $falta[] = [
                'tipo' => 'fert', 'o_que' => 'Fert$',
                'tem' => $fert, 'precisa' => NeutralZone::POSTO_FERT,
            ];
        }

        foreach ($custo as $recurso => $qtd) {
            $tem = (int) ($estoque[$recurso] ?? 0);
            if ($tem < $qtd) {
                $falta[] = ['tipo' => 'recurso', 'o_que' => $recurso, 'tem' => $tem, 'precisa' => $qtd];
            }
        }

        /*
         * ⚠️ `max(0, ...)`: o disponível pode ser NEGATIVO quando a colônia foi grandfatherada acima
         * do teto (D-178) — medido em produção, um dos líderes estava em −9. Mostrar "−9 de 2" numa
         * tela de requisito confunde; o que o jogador precisa saber é que tem zero livres.
         */
        $livres = $this->parametros->ativo() ? $this->operadores->disponivel($colonia) : 0;
        if ($exigidos > 0 && $livres < $exigidos) {
            $falta[] = [
                'tipo' => 'operadores', 'o_que' => 'colonos livres',
                'tem' => max(0, $livres), 'precisa' => $exigidos,
            ];
        }

        if ($possuidas >= NeutralZone::TETO_ZONAS_POR_COLONIA) {
            $falta[] = [
                'tipo' => 'teto', 'o_que' => 'vaga de zona',
                'tem' => $possuidas, 'precisa' => NeutralZone::TETO_ZONAS_POR_COLONIA,
            ];
        }

        return [
            'marco' => self::MARCO,
            'fert' => NeutralZone::POSTO_FERT,
            'recursos' => $custo,
            'operadores' => $exigidos,
            'zonas_ocupadas' => $possuidas,
            'teto_de_zonas' => NeutralZone::TETO_ZONAS_POR_COLONIA,
            'falta' => $falta,
            'pode' => $falta === [],
        ];
    }
}
