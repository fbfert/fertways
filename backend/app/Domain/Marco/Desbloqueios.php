<?php

namespace App\Domain\Marco;

use App\Domain\Logistics\RequisitosDeOcupacao;
use App\Models\Colony;
use App\Models\EnduranceItem;
use App\Models\MilestoneSetting;

/**
 * O que o Marco abre, e de onde vem XP (D-247).
 *
 * ## ⚠️ O jogo cobrava o Marco e nunca disse como se sobe nem para quê
 *
 * A tela mostra `Marco 12 · Pioneiro · 2.600 / 3.000 XP` desde o D-75, e é tudo. **Nada** no jogo
 * diz que existe XP por comerciar, nem que ocupar território pede o marco 20 — o jogador descobre o
 * portão ao esbarrar nele, e a fonte, nunca.
 *
 * Isso deixou de ser detalhe quando o D-241 mediu a torneira: **7 das 9 colônias humanas estão
 * travadas no marco**, e o XP semanal do planeta é de 900. Parte da seca é de informação — não
 * adianta pagar XP por comércio se ninguém sabe que ele paga.
 *
 * ## Os portões saem de onde eles são cobrados
 *
 * `ExigirMarco::GATES` é a lista dos gates fixos do §05, e ela mora ao lado do código que os cobra.
 * O território sai do `RequisitosDeOcupacao`, que é quem calcula a régua de verdade — inclusive
 * quando um evento a dobra (D-232). E a Endurance sai do **catálogo**: cada item traz o seu
 * `marco_minimo`, então a lista se atualiza sozinha quando o operador cria uma peça nova.
 *
 * Nenhuma frase escrita à mão. É a lição do D-224 e do D-240: uma tela que reescreve a regra
 * envelhece sozinha e passa meses mentindo.
 *
 * ## As fontes saem do painel do operador
 *
 * `milestone_settings` é a autoridade sobre quanto vale cada ato (D-75). Fonte zerada **não aparece**
 * — zero desliga a fonte, e anunciar "0 XP por comércio" seria pior do que calar: parece defeito.
 *
 * ⚠️ O teto diário do Mercado (D-241) vai junto na frase. Sem ele o jogador lê "50 XP por execução"
 * e conclui que basta negociar mil vezes.
 */
class Desbloqueios
{
    public function __construct(private RequisitosDeOcupacao $ocupacao) {}

    /**
     * O que ainda está fechado para esta colônia, do mais perto ao mais longe.
     *
     * @return list<array{marco: int, xp: int, o_que: string}>
     */
    public function proximos(Colony $colonia): array
    {
        $marcoAtual = Curva::marco((int) $colonia->xp);
        $portoes = [];

        foreach (ExigirMarco::GATES as $marco => $oQue) {
            $portoes[] = ['marco' => $marco, 'o_que' => $oQue];
        }

        /*
         * O território não vem de `GATES`: a régua dele é dobrável por evento, e quem sabe o valor
         * de hoje é o `RequisitosDeOcupacao` — o mesmo objeto que o painel do mapa lê. Aqui o que
         * importa é o MARCO, então convertemos o XP exigido de volta em marco: durante uma Cesta o
         * portão desce de verdade, e a lista precisa dizer a verdade de hoje.
         */
        $portoes[] = [
            'marco' => Curva::marco($this->ocupacao->xpExigido()),
            'o_que' => 'Ocupar zona neutra',
        ];

        /*
         * A Endurance se declara sozinha: cada item traz o seu `marco_minimo`, e a lista acompanha
         * o catálogo sem ninguém precisar lembrar deste arquivo. Só o que ainda está à venda —
         * prometer um portão para uma peça esgotada é prometer uma porta que não abre nada.
         */
        $daEndurance = EnduranceItem::query()
            ->whereColumn('quantidade_vendida', '<', 'quantidade_total')
            ->whereNotNull('marco_minimo')
            ->where('marco_minimo', '>', $marcoAtual)
            ->get(['marco_minimo', 'tipo'])
            ->groupBy('marco_minimo');

        foreach ($daEndurance as $marco => $itens) {
            $quantas = $itens->count();

            $portoes[] = [
                'marco' => (int) $marco,
                'o_que' => $quantas === 1
                    ? 'Uma peça da Endurance'
                    : "{$quantas} peças da Endurance",
            ];
        }

        return collect($portoes)
            ->filter(fn ($p) => $p['marco'] > $marcoAtual)
            ->map(fn ($p) => [...$p, 'xp' => Curva::xpDoMarco($p['marco'])])
            ->sortBy('marco')
            ->values()
            ->all();
    }

    /**
     * De onde vem XP, com o valor que o operador declarou. Fonte zerada não entra.
     *
     * @return list<array{acao: string, rotulo: string, xp: int, nota: ?string}>
     */
    public function fontes(): array
    {
        $c = MilestoneSetting::singleton();
        $teto = (int) $c->xp_mercado_teto_diario;

        $fontes = [
            ['acao' => 'obra_concluida', 'rotulo' => 'Concluir um nível de construção',
                'xp' => (int) $c->xp_obra_por_nivel, 'nota' => null],
            ['acao' => 'missao_concluida', 'rotulo' => 'Cumprir uma missão',
                'xp' => 0, 'nota' => 'cada missão paga o que o catálogo dela diz'],
            ['acao' => 'mercado_executado', 'rotulo' => 'Negociar no Mercado Central',
                'xp' => (int) $c->xp_mercado_executado,
                'nota' => $teto > 0 ? "até {$teto} vezes por dia" : null],
            ['acao' => 'acordo_executado', 'rotulo' => 'Cumprir um Acordo de Troca',
                'xp' => (int) $c->xp_acordo_executado, 'nota' => null],
            ['acao' => 'zona_ocupada', 'rotulo' => 'Ocupar uma zona neutra',
                'xp' => (int) $c->xp_zona_ocupada, 'nota' => null],
            ['acao' => 'combate_vencido', 'rotulo' => 'Vencer um combate',
                'xp' => (int) $c->xp_combate_vencido, 'nota' => null],
        ];

        /*
         * A missão fica mesmo com `xp = 0`: ela não tem valor único, cada template paga o seu
         * (D-78). É o único caso em que zero não quer dizer "desligada", e por isso ela é a exceção
         * do filtro abaixo — a nota explica.
         */
        return collect($fontes)
            ->filter(fn ($f) => $f['xp'] > 0 || $f['nota'] !== null)
            ->values()
            ->all();
    }
}
