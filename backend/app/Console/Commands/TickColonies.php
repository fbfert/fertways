<?php

namespace App\Console\Commands;

use App\Domain\Capital\Patio;
use App\Domain\Logistics\ConcluirTrechos;
use App\Domain\Guerra\ChegarReforcos;
use App\Domain\Guerra\ResolverCombates;
use App\Domain\Zona\ConcluirObrasDaZona;
use App\Domain\Zona\ProcessarSiderurgicaNaZona;
use App\Domain\Zona\RefinarNaZona;
use App\Domain\Logistics\ExtrairZonasNeutras;
use App\Domain\Transport\FabricarCaminhoes;
use App\Domain\Ministry\ExpirarPrazos;
use App\Domain\Ministry\PagarConciliadores;
use App\Domain\Production\ColonyTick;
use App\Domain\Trade\ExpirarAcordos;
use App\Models\Colony;
use App\Models\NeutralZone;
use Illuminate\Console\Command;
use Throwable;

/**
 * Motor de tick. Chamado pelo Laravel Scheduler, que o cron do sistema aciona a cada minuto:
 *
 *     * * * * * /usr/bin/php84 /home/fertways/deploy/fertways/backend/artisan schedule:run >/dev/null 2>&1
 *
 * É o `artisan` da cópia de deploy, não o da árvore de trabalho (D-39).
 *
 * Caminho absoluto do php84 de propósito: o alias `php` do Virtualmin só existe em shell
 * interativo, e o cron rodaria o PHP 8.2 do AppStream.
 *
 * Nada aqui depende de "tempo online": cada colônia avança pelo delta entre `last_tick_at`
 * e agora. Uma colônia parada por dois dias recupera exatamente o que produziria.
 */
class TickColonies extends Command
{
    protected $signature = 'fertways:tick {--colony= : processa só esta colônia}';

    protected $description = 'Avança produção, conclui upgrades e expira proteções, por delta de tempo';

    public function handle(
        ColonyTick $tick,
        ConcluirTrechos $trechos,
        ExpirarAcordos $acordos,
        ExpirarPrazos $ministerio,
        PagarConciliadores $folha,
        ExtrairZonasNeutras $zonasNeutras,
        FabricarCaminhoes $fabrica,
        Patio $patio,
        ResolverCombates $combates,
        ChegarReforcos $reforcos,
        RefinarNaZona $refinarias,
        ProcessarSiderurgicaNaZona $siderurgicas,
        ConcluirObrasDaZona $obras,
        \App\Domain\Drone\ConcluirMissoes $drones,
        \App\Domain\Chat\PurgarMensagens $chat,
    ): int {
        $agora = now();
        $processadas = 0;
        $falhas = 0;

        Colony::when($this->option('colony'), fn ($q, $id) => $q->whereKey($id))
            ->where('last_tick_at', '<', $agora)
            ->orderBy('id')
            ->chunkById(200, function ($colonias) use ($tick, $agora, &$processadas, &$falhas) {
                foreach ($colonias as $colony) {
                    try {
                        $tick->handle($colony, $agora);
                        $processadas++;
                    } catch (Throwable $e) {
                        // Uma colônia com estado ruim não pode travar o servidor inteiro.
                        // A transação dela já sofreu rollback; as outras seguem.
                        $falhas++;
                        report($e);
                        $this->error("colônia {$colony->id}: {$e->getMessage()}");
                    }
                }
            });

        $zonas = $this->expirarProtecoes($agora);

        // Extração das zonas neutras ocupadas — fora do laço por colônia, como as entregas: a zona
        // rende por delta próprio, sem relação com o `last_tick_at` de ninguém (§07, D-52).
        $extraidas = $zonasNeutras->handle($agora);

        /*
         * Fora do laço por colônia: um veículo da colônia A entrega na B, e o relógio da viagem
         * não tem relação com o `last_tick_at` de nenhuma das duas. Rodar por colônia entregaria
         * a carga só quando a colônia de origem fosse processada.
         */
        $entregas = $trechos->handle();

        /*
         * As missões de Drone (D-74) andam junto das entregas — são viagens, só que de olhos, e o
         * `ConcluirTrechos` as ignora de propósito (o Drone não carrega carga). Antes do combate,
         * pela mesma lógica dos reforços: a foto tirada neste minuto já vale para quem decide
         * atacar neste minuto.
         */
        $missoes = $drones->handle($agora);

        // A retenção do chat é PUBLICADA (§08: 180/90 dias; privadas ficam) — a purga é lei, não
        // faxina. Deletes indexados por idade: baratos o bastante para todo tick.
        $purgadas = $chat->handle($agora);

        /*
         * Depois das entregas, nunca antes: a carga que chega no último segundo do prazo ainda
         * cumpre o acordo (§26.5, D-41). Expirar primeiro puniria quem entregou a tempo.
         */
        $vencidos = $acordos->handle();

        /*
         * Depois dos acordos, nunca antes: um acordo que vence **neste** tick já pode ser a
         * evidência de uma denúncia (§26.8), e um caso cujo prazo de análise venceu no mesmo minuto
         * deve ser reatribuído com o mundo já atualizado.
         */
        ['reatribuidos' => $reatribuidos, 'encerrados' => $encerrados] = $ministerio->handle();
        $salarios = $folha->handle();

        /*
         * A linha de montagem do Ministério dos Transportes (D-60): fecha o que ficou pronto e
         * repõe a prateleira até 5, consumindo o Tesouro. Vem **depois** das entregas de propósito:
         * um caminhão comprado neste mesmo tick já saiu da prateleira, e a reposição tem de contar
         * a prateleira como ela ficou, não como estava. Se o Tesouro estiver seco, não repõe — e
         * isso não é falha do tick.
         */
        ['prontos' => $prontos, 'encomendados' => $encomendados] = $fabrica->handle();

        /*
         * A hora do Pátio da Capital (D-65). **Depois** das entregas, nunca antes: o veículo que
         * estaciona neste mesmo tick tem de começar a pagar da chegada dele, e não do tick que vem
         * — e quem chegou agora ainda não deve hora nenhuma, porque a primeira só fecha daqui a 60
         * minutos.
         */
        ['cobrados' => $cobrados, 'rebocados' => $rebocados] = $patio->handle($agora);

        /*
         * A guerra (D-66). **Depois da extração, nunca antes**: o saque incide sobre o estoque
         * exposto da zona, e o mineral que rendeu neste mesmo minuto já é butim legítimo. Resolver
         * o combate primeiro faria o vencedor levar uma foto velha do depósito.
         *
         * Cada combate avança TODAS as rodadas que venceram, não uma por tick — se o tick atrasar,
         * a batalha anda pelo relógio, e não pelo número de vezes que o cron acordou.
         */
        /*
         * Os reforços chegam ANTES de as rodadas correrem (D-70). É o que faz "reforços tardios
         * podem ainda mudar o resultado" (§27.5) ser verdade: a tropa que chegou neste minuto tem de
         * estar em campo quando a rodada deste minuto for resolvida. Depois, ela chegaria sempre uma
         * rodada atrasada — e o §27.5 desenhou o combate longo justamente para dar tempo de socorrer.
         */
        $chegaram = $reforcos->handle($agora);

        $batalhas = $combates->handle($agora);

        /*
         * A zona vira lugar (D-67). **Depois da extração e do combate**, e a ordem importa nas duas
         * pontas: a Refinaria converte o minério que a extração acabou de creditar, e o saque já
         * levou o que tinha de levar — refinar o que o inimigo carregou embora seria refinar o nada.
         */
        $obrasFeitas = $obras->handle($agora);
        $refinadas = $refinarias->handle($agora);

        /*
         * A Indústria Siderúrgica (D-82, construção nova, não está no GDD) **disputa o mesmo
         * depósito** que a Refinaria acabou de consumir — decisão do usuário: quem chegar primeiro
         * no tick leva. Roda DEPOIS da Refinaria, de propósito: a ordem em que as duas aparecem
         * aqui é a ordem em que competem pelo Metal Bruto restante.
         */
        $processadasSiderurgica = $siderurgicas->handle($agora);

        $this->info("tick: {$processadas} colônias, {$falhas} falhas, {$zonas} proteções expiradas, {$extraidas} zonas extraídas, {$entregas} trechos concluídos, {$vencidos} acordos vencidos, {$reatribuidos} casos reatribuídos, {$encerrados} casos encerrados, {$salarios} salários pagos, {$prontos} caminhões prontos, {$encomendados} encomendados, {$cobrados} horas de pátio cobradas, {$rebocados} rebocados, {$batalhas} batalhas, {$chegaram} reforços chegados, {$obrasFeitas} obras de zona, {$refinadas} zonas refinaram, {$processadasSiderurgica} zonas com siderúrgica, {$missoes} pernas de drone, {$purgadas} mensagens purgadas");

        return $falhas > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * "Zona neutra elegível protegida por 8 dias completos. Ao término, tornam-se
     * vulneráveis na janela declarada pelo dono." (GDD, precedência da seção 0)
     *
     * O slot principal é inviolável sempre e não passa por aqui.
     */
    private function expirarProtecoes($agora): int
    {
        return NeutralZone::where('status', 'protegida')
            ->whereNotNull('protected_until')
            ->where('protected_until', '<=', $agora)
            ->update(['status' => 'vulneravel']);
    }
}
