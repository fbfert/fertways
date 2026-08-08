<?php

namespace App\Console\Commands;

use App\Domain\Eventos\EntregarCestas;
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

    protected $description = 'Entrega as cestas dos eventos vigentes a quem ainda não recebeu';

    public function handle(EntregarCestas $entregador): int
    {
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
