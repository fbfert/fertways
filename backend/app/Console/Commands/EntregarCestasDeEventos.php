<?php

namespace App\Console\Commands;

use App\Domain\Eventos\AtivarEventos;
use App\Domain\Eventos\EntregarCestas;
use App\Domain\Missoes\Atribuir;
use App\Models\Colony;
use App\Models\XpEntry;
use Illuminate\Console\Command;

/**
 * O entregador das cestas de evento (D-232), acionado pelo scheduler.
 *
 * ## Por que agendado, e não uma vez só na ativação
 *
 * Porque o mundo ganha colônias durante a janela. Entregar no clique de "ativar" serviria as que
 * existiam naquele segundo e nunca mais ninguém — e a decisão do usuário foi o contrário: quem
 * fundar no dia 12 de uma janela de 30 recebe também.
 *
 * ## ⚠️ Por que NÃO mora dentro do tick
 *
 * O tick roda a cada minuto por colônia e é o caminho mais quente do jogo; pendurar nele uma
 * varredura de eventos custaria consulta em todo minuto de todo dia por um fato que acontece
 * algumas vezes por ano. E há a razão operacional: um erro aqui não pode ter como derrubar a
 * produção do mundo inteiro.
 *
 * Não recebe `--aplicar`: entregar é idempotente pela chave única, e um modo seco que listasse
 * destinos sem os marcar seria mais fácil de ler errado do que de usar. Quem quer ensaiar cria o
 * evento com `--colonia`, que é o dry-run em escala de um.
 */
class EntregarCestasDeEventos extends Command
{
    protected $signature = 'fertways:eventos-entregar';

    protected $description = 'Ativa os eventos armados que chegaram a hora, e entrega cestas e missões dos vigentes';

    public function handle(EntregarCestas $entregador, Atribuir $atribuir, AtivarEventos $ativador): int
    {
        /*
         * D-253: primeiro os que se ativam sozinhos (encadeados e por condição). Antes das cestas e
         * das missões de propósito: um evento armado com cesta que se ativa nesta passada entrega
         * nesta passada, e não cinco minutos depois.
         */
        foreach ($ativador->handle() as $slug) {
            $this->info("«{$slug}» ativado sozinho (corrente ou condição).");
        }

        /*
         * D-250: as missões dos eventos também saem daqui, de cinco em cinco minutos — um evento que
         * começa às 14h não pode esperar o comando diário das 07h05 para chegar a quem joga.
         *
         * Só a quem AGIU nos últimos 7 dias (`xp_entries`), a régua do D-243: missão a quem não olha
         * enche tabela. Quem voltar depois recebe pela tela (`GET /missoes`).
         */
        $ativas = XpEntry::where('created_at', '>=', now()->subDays(7))->distinct()->pluck('colony_id');
        $missoes = 0;

        foreach (Colony::whereIn('id', $ativas)->get() as $colonia) {
            $missoes += $atribuir->garantirEventos($colonia);
        }

        if ($missoes > 0) {
            $this->info("Missões de evento entregues: {$missoes}.");
        }

        $feito = $entregador->todos();

        if ($feito === []) {
            $this->line('Nada a entregar.');

            return self::SUCCESS;
        }

        foreach ($feito as $slug => $n) {
            $this->info("«{$slug}»: cesta entregue a {$n} colônia(s).");
        }

        return self::SUCCESS;
    }
}
