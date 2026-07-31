<?php

namespace App\Domain\Populacao;

use App\Models\Colony;

/**
 * Sustento e crescimento (A2.2.2 e A2.2.3): o que a população faz a cada intervalo de tempo.
 *
 * ## Puro de propósito: calcula e devolve, não escreve
 *
 * `avancar()` recebe o estoque e devolve o que deveria acontecer. Quem grava é o chamador — o tick
 * do jogo ou o simulador da trilha A2.S. É essa separação que permite ao simulador rodar **o código
 * de domínio real** num mundo descartável sem tocar em banco nenhum: a regra 1 da trilha diz que um
 * simulador que reescreve a fórmula "diverge do jogo na primeira mudança e passa a mentir com
 * aparência de autoridade".
 *
 * ## A escassez degrada, não mata
 *
 * Faltando insumo, a população **não morre** e a colônia **não é destruída**: a eficiência cai
 * enquanto durar a falta. É a mesma escolha do §6.6 para zona abaixo dos operadores exigidos —
 * degrada, não se perde. Num jogo persistente sem reset, matar colono de quem passou o fim de
 * semana fora não é dificuldade, é hostilidade.
 *
 * ## E o crescimento para antes de a escassez começar
 *
 * Abaixo de `crescimento_min_suprimento_bps` a população deixa de crescer, mesmo que ainda haja
 * estoque. Sem esse freio, uma colônia cresceria até a fome — o modelo produziria por conta própria
 * a espiral que ninguém pediu.
 */
class Ciclo
{
    public function __construct(private Parametros $parametros, private Populacao $populacao) {}

    /**
     * @param  array<string,int|float>  $estoque  quanto a colônia tem de cada recurso essencial
     * @return array{consumo: array<string,float>, populacao_nova: int, razao_suprimento_bps: int,
     *               eficiencia_bps: int, cresceu: bool, faltou: list<string>}
     */
    public function avancar(Colony $colonia, array $estoque, float $horas): array
    {
        $total = (int) $colonia->populacao;
        $capacidade = $this->populacao->capacidade($colonia);

        if ($total <= 0 || $horas <= 0) {
            // Devolve o total ATUAL, e não zero: um tick de delta nulo não pode esvaziar a colônia.
            return $this->parado($total);
        }

        // ── quanto a população QUERIA consumir no intervalo
        $desejado = [];
        foreach ($this->parametros->consumoMilliPorColonoHora() as $recurso => $milli) {
            $desejado[$recurso] = $milli * $total * $horas / 1000;
        }

        /*
         * A razão de suprimento é a do recurso MAIS ESCASSO, e não a média.
         *
         * Média esconderia exatamente o caso que interessa: uma colônia nadando em água e sem
         * oxigênio nenhum teria razão "boa" e continuaria crescendo rumo à asfixia. O gargalo manda
         * — é o mesmo princípio do "qual recurso essencial satura primeiro" que a trilha A2.S pede.
         */
        $razao = 1.0;
        $faltou = [];

        foreach ($desejado as $recurso => $quanto) {
            if ($quanto <= 0) {
                continue;
            }

            $tem = (float) ($estoque[$recurso] ?? 0);
            $r = min(1.0, $tem / $quanto);

            if ($r < 1.0) {
                $faltou[] = $recurso;
            }

            $razao = min($razao, $r);
        }

        $razaoBps = (int) round($razao * 10000);

        // Consome o que couber: o que falta simplesmente não é consumido, e a eficiência paga.
        $consumo = [];
        foreach ($desejado as $recurso => $quanto) {
            $consumo[$recurso] = min($quanto, (float) ($estoque[$recurso] ?? 0));
        }

        $p = $this->parametros->todos();

        /*
         * Eficiência: 100% com tudo suprido; `escassez_eficiencia_bps` no pior caso. Interpola
         * linearmente pela razão do gargalo — meio suprimento, meio caminho entre os dois.
         */
        $piso = (int) $p->escassez_eficiencia_bps;
        $eficiencia = $razaoBps >= 10000
            ? 10000
            : (int) round($piso + ($razaoBps / 10000) * (10000 - $piso));

        // ── crescimento
        $cresce = $razaoBps >= (int) $p->crescimento_min_suprimento_bps && $total < $capacidade;

        $nova = $total;

        /*
         * O resto fracionário ACUMULA entre chamadas, em milésimos de colono.
         *
         * Sem isto o crescimento não acontece de jeito nenhum para colônia pequena: 5 colonos a
         * 0,5%/h dão 5,025 num passo de uma hora, o `floor` devolve 5, e a população fica presa em 5
         * para sempre. Foi a primeira rodada do simulador da trilha A2.S que mostrou isso — a curva
         * saiu perfeitamente horizontal por 60 dias.
         *
         * Mesmo idioma de `siderurgica_lote_remainder`, que a casa já usa para o lote da Indústria.
         */
        $restoMilli = (int) ($colonia->populacao_resto_milli ?? 0);

        if ($cresce) {
            $taxa = (int) $p->crescimento_bps_hora / 10000;
            $ganhoMilli = $restoMilli + (int) round($total * $taxa * $horas * 1000);

            $nova = $total + intdiv($ganhoMilli, 1000);
            $restoMilli = $ganhoMilli % 1000;

            /*
             * O teto TRAVA, não derrama — mesma regra que a A2.7 fixou para estoque. Crescer além
             * da capacidade e "perder o excedente" puniria quem construiu habitação de menos com
             * uma perda invisível; travar mostra o limite.
             */
            if ($nova >= $capacidade) {
                // No teto o resto zera: guardar fração de um crescimento que não pode acontecer
                // faria a população saltar sozinha assim que alguém subisse a habitação um nível.
                $nova = $capacidade;
                $restoMilli = 0;
            }

            /*
             * ⚠️ Limitação conhecida, anotada em vez de disfarçada: com taxa pequena e intervalo
             * curto, `floor` devolve o mesmo número e a população não anda. Uma colônia de 10
             * colonos a 0,5%/h não cresce em ticks de um minuto — só quando o delta acumulado
             * bastar. Não é bug de cálculo, é a granularidade do inteiro, e a simulação da trilha
             * A2.S é justamente onde isso aparece antes de virar reclamação de jogador.
             */
        }

        return [
            'consumo' => $consumo,
            'populacao_nova' => $nova,
            'resto_milli' => $restoMilli,
            'razao_suprimento_bps' => $razaoBps,
            'eficiencia_bps' => $eficiencia,
            'cresceu' => $nova > $total,
            'faltou' => $faltou,
        ];
    }

    private function parado(int $total): array
    {
        return [
            'consumo' => [],
            'populacao_nova' => $total,
            'resto_milli' => 0,
            'razao_suprimento_bps' => 10000,
            'eficiencia_bps' => 10000,
            'cresceu' => false,
            'faltou' => [],
        ];
    }
}
