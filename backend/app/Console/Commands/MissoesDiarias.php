<?php

namespace App\Console\Commands;

use App\Domain\Missoes\Atribuir;
use App\Domain\Missoes\Janela;
use App\Models\Colony;
use App\Models\XpEntry;
use Illuminate\Console\Command;

/**
 * Entrega as missões do dia a quem está JOGANDO — e não a quem abriu a tela (D-243).
 *
 * ## ⚠️ O que estava acontecendo
 *
 * `Atribuir` é preguiçoso por desenho: só sorteia quando alguém pede `GET /missoes`. A intenção é
 * boa — não fazer trabalho para quem não está lá —, mas o gatilho é **uma tela**, e não o jogo.
 * Medido em 2026-09-09: **nenhuma atribuição desde a semana 32**, cinco semanas, e só 6 das 30
 * colônias com missão ativa. Os colonos simulados jogam sem parar e nunca abrem aquela tela; o XP
 * deles caiu 97,8% em quatro semanas (D-241), e metade da causa era esta.
 *
 * O §06 chama as missões de fonte de XP e de Fert$. Uma fonte que depende de o jogador visitar uma
 * página específica não é fonte do jogo: é fonte da interface.
 *
 * ## A regra continua sendo "não trabalhar para quem não está lá"
 *
 * O que muda é **como se sabe que ele está lá**. Não é mais "abriu a tela", é **agiu**: a colônia
 * tem lançamento em `xp_entries` na janela de atividade. Quem parou de jogar há semanas continua
 * sem receber nada, e o custo do comando é proporcional a quem está de fato em campo.
 *
 * ⚠️ **`xp_entries`, e não o ledger.** O ledger recebe produção a cada tick — inclusive de colônia
 * abandonada, porque a fábrica não para quando o dono some. Ele diria "todo mundo ativo". O XP
 * nasce de **ato**, que é o que esta janela precisa medir.
 *
 * ## E a missão se conclui sozinha
 *
 * `Progresso` paga na hora, sem botão de resgate (D-78). Então basta a missão existir: o ato que o
 * colono já faria a completa e credita. Sem isto, entregar missão a quem não olha seria só encher
 * uma tabela.
 */
class MissoesDiarias extends Command
{
    protected $signature = 'fertways:missoes-diarias
        {--dias=7 : a janela de atividade, em dias}
        {--dry-run : conta e não escreve}';

    protected $description = 'Sorteia as missões do dia para as colônias que agiram na janela';

    public function handle(Atribuir $atribuir): int
    {
        $dias = max(1, (int) $this->option('dias'));
        $desde = now()->subDays($dias);

        $ativas = XpEntry::where('created_at', '>=', $desde)
            ->distinct()
            ->pluck('colony_id');

        $this->line("Colônias com ato nos últimos {$dias} dias: {$ativas->count()}");

        if ($this->option('dry-run')) {
            $this->line('dry-run: nada foi escrito.');

            return self::SUCCESS;
        }

        $dia = Janela::diaAtual();
        $sorteadas = 0;

        foreach (Colony::whereIn('id', $ativas)->get() as $colonia) {
            /*
             * `garantir()` é idempotente por janela — ele confere se a colônia já tem diária do dia
             * antes de sortear. Rodar duas vezes no mesmo dia não dá seis missões, e é isso que
             * permite este comando conviver com o `GET /missoes` sem coordenação nenhuma.
             */
            $atribuir->garantir($colonia);

            /*
             * A narrativa e a tutoria são encadeadas e sem janela (D-140, A2.1): o degrau seguinte
             * nasce quando o anterior conclui. Quem nunca abre a tela travava na escada — e a
             * tutoria é justamente o que ensina o jogo a quem chegou.
             */
            $atribuir->garantirNarrativa($colonia);
            $atribuir->tutoria($colonia);

            if ($colonia->federation_id !== null && $colonia->federation !== null) {
                $atribuir->garantirFederacao($colonia->federation, $colonia);
            }

            $sorteadas++;
        }

        $this->info("Missões garantidas para {$sorteadas} colônias (dia de missão desde {$dia}).");

        return self::SUCCESS;
    }
}
