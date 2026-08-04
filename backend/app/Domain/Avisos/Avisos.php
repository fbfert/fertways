<?php

namespace App\Domain\Avisos;

use App\Domain\Colony\TetoDoEstoque;
use App\Domain\Populacao\Ciclo;
use App\Domain\Populacao\Parametros;
use App\Models\Colony;
use App\Models\Combat;
use App\Models\NeutralZone;
use Illuminate\Support\Facades\DB;

/**
 * O que exige ação, num lugar só (A2.V2 — docs/decisoes.md D-211).
 *
 * O jogo tinha sinais espalhados por seis telas: o combate só no Quartel, o cerco só em Minhas
 * Zonas, a manutenção atrasada só ao abrir a zona, a vaga do Laboratório só na Pesquisa. Quem não
 * abrisse a tela certa não ficava sabendo — e o §1.1 promete um jogo que **não exige login
 * constante**, o que só é honesto se, ao voltar, o que importa estiver à vista.
 *
 * ## ⚠️ A medição derrubou dois avisos antes de eles existirem
 *
 * Contado em produção, quantas das 29 colônias disparariam cada candidato:
 *
 * | candidato | dispara para |
 * |---|---|
 * | estoque cheio | **1** |
 * | fila de obras vazia | **3** |
 * | laboratório ocioso | 15 |
 * | ~~população no teto~~ | **28** |
 * | ~~sem colonos livres~~ | 19 |
 *
 * **"População no teto" seria papel de parede**: um aviso que 28 de 29 veem sempre deixa de ser
 * aviso e passa a ser moldura — e pior, ensina o jogador a ignorar a faixa inteira, levando junto os
 * que importam. Ele já mora no painel de População (D-210), que é onde a informação pertence.
 * "Sem colonos livres" caiu pelo mesmo motivo e é consequência do mesmo fato.
 *
 * **Aviso não é tudo o que é verdade; é o que é verdade e raro.**
 *
 * ## Três severidades, e elas ordenam
 *
 * `urgente` está destruindo valor agora (combate, cerco). `atencao` está desperdiçando (produção
 * parada, manutenção atrasada). `oportunidade` é ganho não colhido (fila parada, laboratório
 * ocioso). A ordem da lista é a ordem em que se deve agir.
 */
class Avisos
{
    public const URGENTE = 'urgente';

    public const ATENCAO = 'atencao';

    public const OPORTUNIDADE = 'oportunidade';

    public function __construct(
        private readonly TetoDoEstoque $teto,
        private readonly Parametros $parametrosDePopulacao,
        private readonly Ciclo $ciclo,
    ) {}

    /**
     * @return list<array{codigo:string, severidade:string, titulo:string, detalhe:string}>
     */
    public function paraColonia(Colony $colonia): array
    {
        $avisos = [
            ...$this->militares($colonia),
            ...$this->producao($colonia),
            ...$this->territorio($colonia),
            ...$this->oportunidades($colonia),
        ];

        // A ordem da lista é a ordem de agir. `usort` estável não é garantido em PHP < 8.0; é em 8.
        $peso = [self::URGENTE => 0, self::ATENCAO => 1, self::OPORTUNIDADE => 2];
        usort($avisos, fn ($a, $b) => $peso[$a['severidade']] <=> $peso[$b['severidade']]);

        return $avisos;
    }

    /**
     * O que está destruindo valor agora.
     *
     * ⚠️ Zero em produção hoje — nenhum combate jamais aconteceu no jogo. É de propósito que ele
     * exista assim mesmo: um aviso militar que só nasce quando a primeira guerra estourar chegaria
     * tarde, e é justamente o caso em que o jogador **não** está com a tela do Quartel aberta.
     *
     * @return list<array<string,string>>
     */
    private function militares(Colony $colonia): array
    {
        $avisos = [];

        $combates = Combat::where(fn ($q) => $q
            ->where('attacker_colony_id', $colonia->id)
            ->orWhere('defender_colony_id', $colonia->id))
            ->whereIn('status', ['marchando', 'em_curso'])
            ->get(['attacker_colony_id', 'status']);

        $defendendo = $combates->filter(fn ($c) => (int) $c->attacker_colony_id !== $colonia->id)->count();
        $atacando = $combates->count() - $defendendo;

        if ($defendendo > 0) {
            $avisos[] = [
                'codigo' => 'sob_ataque',
                'severidade' => self::URGENTE,
                'titulo' => $defendendo === 1 ? 'Você está sob ataque' : "Você está sob {$defendendo} ataques",
                'detalhe' => 'Reforce a zona ou rompa o cerco pelo Quartel — reforço que chega a tempo ainda muda o resultado.',
            ];
        }

        if ($atacando > 0) {
            $avisos[] = [
                'codigo' => 'atacando',
                'severidade' => self::ATENCAO,
                'titulo' => $atacando === 1 ? 'Um ataque seu está em curso' : "{$atacando} ataques seus em curso",
                'detalhe' => 'Acompanhe pelo Quartel.',
            ];
        }

        $cercadas = NeutralZone::where('owner_colony_id', $colonia->id)
            ->whereNotNull('sieged_at')
            ->count();

        if ($cercadas > 0) {
            $avisos[] = [
                'codigo' => 'zona_cercada',
                'severidade' => self::URGENTE,
                'titulo' => $cercadas === 1 ? 'Uma zona sua está cercada' : "{$cercadas} zonas suas cercadas",
                'detalhe' => 'Nada entra nem sai, e o que se extrai se perde. Só romper o cerco a reabre.',
            ];
        }

        return $avisos;
    }

    /**
     * O que está desperdiçando produção.
     *
     * @return list<array<string,string>>
     */
    private function producao(Colony $colonia): array
    {
        $avisos = [];

        $cheios = [];

        foreach ($colonia->resources as $r) {
            $livre = $this->teto->espacoLivre($colonia, $r->resource_type, (int) $r->amount);

            if ($livre !== null && $livre <= 0) {
                $cheios[] = $r->resource_type;
            }
        }

        if ($cheios !== []) {
            $avisos[] = [
                'codigo' => 'estoque_cheio',
                'severidade' => self::ATENCAO,
                'titulo' => count($cheios) === 1
                    ? 'Um recurso encheu e parou de produzir'
                    : count($cheios).' recursos encheram e pararam de produzir',
                // O teto TRAVA, não derrama (§14): não se perde estoque, perde-se a hora de produção.
                'detalhe' => 'Nada se perde — mas nada mais entra. Gaste, venda ou suba o Depósito Local.',
            ];
        }

        /*
         * A degradação do §6.6: falta de insumo essencial derruba a produção até um piso de 50%.
         * Zero colônias em produção hoje — e é bom que seja o aviso a dizer isso, e não o jogador a
         * descobrir vendo o número cair sem explicação.
         */
        if ($this->parametrosDePopulacao->ativo() && (int) $colonia->populacao > 0) {
            $estoque = $colonia->resources->pluck('amount', 'resource_type')->all();
            $bps = (int) $this->ciclo->avancar($colonia, $estoque, 1.0)['eficiencia_bps'];

            if ($bps < 10_000) {
                $avisos[] = [
                    'codigo' => 'escassez',
                    'severidade' => self::ATENCAO,
                    'titulo' => 'A produção caiu para '.round($bps / 100).'%',
                    'detalhe' => 'Falta insumo essencial para os colonos. A produção degrada até 50% — ninguém morre.',
                ];
            }
        }

        return $avisos;
    }

    /** @return list<array<string,string>> */
    private function territorio(Colony $colonia): array
    {
        $atrasadas = NeutralZone::where('owner_colony_id', $colonia->id)
            ->whereNotNull('maintenance_unpaid_since')
            ->count();

        if ($atrasadas === 0) {
            return [];
        }

        return [[
            'codigo' => 'manutencao_atrasada',
            'severidade' => self::ATENCAO,
            'titulo' => $atrasadas === 1
                ? 'Manutenção territorial atrasada'
                : "{$atrasadas} zonas com manutenção atrasada",
            'detalhe' => 'A defesa da zona degrada enquanto a conta não é paga.',
        ]];
    }

    /**
     * Ganho não colhido. Não é problema — é dinheiro na mesa.
     *
     * @return list<array<string,string>>
     */
    private function oportunidades(Colony $colonia): array
    {
        $avisos = [];

        if (DB::table('build_queue')->where('colony_id', $colonia->id)->count() === 0) {
            $avisos[] = [
                'codigo' => 'fila_vazia',
                'severidade' => self::OPORTUNIDADE,
                'titulo' => 'Nada em construção',
                'detalhe' => 'A fila está parada. Uma colônia que não cresce está a perder tempo, não recurso.',
            ];
        }

        $temLaboratorio = $colonia->buildings->contains(
            fn ($b) => $b->type === 'laboratorio' && $b->level >= 1,
        );

        if ($temLaboratorio) {
            $emCurso = DB::table('colony_technologies')
                ->where('colony_id', $colonia->id)
                // ⚠️ `pesquisando`, o valor que `Pesquisa\Vagas` de facto usa. Medi a primeira vez
                // com `em_curso`, que não existe: a consulta devolvia zero para todo mundo e o
                // aviso teria disparado para TODA colônia com Laboratório. Ver D-211.
                ->where('status', 'pesquisando')
                ->count();

            if ($emCurso === 0) {
                $avisos[] = [
                    'codigo' => 'laboratorio_ocioso',
                    'severidade' => self::OPORTUNIDADE,
                    'titulo' => 'Laboratório parado',
                    'detalhe' => 'Há vaga de pesquisa livre. Tecnologia não se recupera depois — o tempo parado é perdido.',
                ];
            }
        }

        return $avisos;
    }
}
