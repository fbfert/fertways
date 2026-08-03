<?php

namespace App\Domain\Production;

use App\Domain\Colony\TetoDoEstoque;
use App\Domain\Colony\TetoDoTanque;
use App\Domain\Endurance\EfeitosDaEndurance;
use App\Domain\Eventos\Modificadores;
use App\Domain\Marco\ConcederXp;
use App\Domain\Missoes\Progresso;
use App\Domain\Pesquisa\ConcluirPesquisa;
use App\Domain\Pesquisa\EfeitosDaPesquisa;
use App\Domain\Populacao\Ciclo;
use App\Domain\Populacao\Parametros;
use App\Models\BuildQueue;
use App\Models\Colony;
use App\Models\Ledger;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Avança uma colônia até `agora`, por delta de tempo. Sem processo persistente: o cron
 * chama `schedule:run` a cada minuto e isto roda por alguns segundos.
 *
 * O delta é FATIADO em cada conclusão de upgrade. Uma colônia parada por dois dias com um
 * upgrade concluindo no meio precisa produzir com o nível antigo até a conclusão e com o
 * novo depois. Produzir tudo com o nível final (ou com o inicial) falsearia a economia.
 *
 * Aritmética inteira, sem float: a produção é acumulada em unidades de 1/3600 (um segundo
 * de produção horária) e o resto fica em `resources.production_remainder`. Um tick de um
 * minuto sobre 100/h renderia 1 unidade em vez de 1,67 se truncássemos.
 */
class ColonyTick
{
    /** Produção que o GDD define sem receita de insumo — pode ser creditada direto. */
    private const PRODUCAO_SEM_INSUMO = [
        'gerador_de_atmosfera', 'captacao_de_agua', 'fazenda', 'reator_de_energia', 'mina_local',
    ];

    /**
     * "A Destilaria converte 2 Biomassas + 3 Energias em 1 Biocombustível. A conversão não
     * tem receita alternativa: a taxa é fixa" (GDD §18.2).
     */
    public const RECEITA_DESTILARIA = ['biomassa' => 2, 'energia' => 3];

    /**
     * A receita da Refinaria Química (docs/decisoes.md D-83). O §19.3 publica a taxa e nunca a
     * receita; o texto do §17.2 só diz "a partir de minerais e água" — o resto é arbitragem do
     * usuário, pesos relativos 1:10:5:6 entre os quatro insumos.
     *
     * ⚠️ A taxa publicada (30/h no nível 1) foi ABANDONADA de propósito: nessa proporção, ela
     * pediria 300 Água/h contra os 80/h que a Captação nível 1 produz — a Refinaria nunca
     * rodaria perto da capacidade nominal. A taxa em `production.json` é outra, menor (2/h no
     * nível 1), calibrada para caber com folga na produção dos essenciais do mesmo nível.
     */
    public const RECEITA_COMPOSTOS = [
        'metal_bruto' => 1, 'agua' => 10, 'biomassa' => 5, 'energia' => 6,
    ];

    /**
     * Ligas Metálicas: o GDD publica a taxa da Oficina (§19.3, "Metal Bruto é extraído, Ligas
     * são transformadas") e nunca a receita. Diferente dos Compostos, a saída não é arbitrar uma
     * receita — é ABANDONAR a produção pela Oficina: Ligas Metálicas agora só nascem da
     * Indústria Siderúrgica (D-82), que já converte Metal Bruto numa proporção real. Duas fontes
     * de "Metal Bruto vira Ligas" com regras diferentes seria confuso, não redundante — por isso
     * `ligas_metalicas` nem consta mais em `production.json` da Oficina.
     */

    /** Receita usada pela Oficina quando o jogador não escolheu nenhuma. */
    public const RECEITA_PADRAO = 'basica';

    public function __construct(
        private TetoDoTanque $tetoDoTanque,
        private TetoDoEstoque $tetoDoEstoque,
        private Modificadores $eventos,
        private EfeitosDaPesquisa $efeitosDaPesquisa,
        private ConcluirPesquisa $concluirPesquisa,
        private EfeitosDaEndurance $efeitosDaEndurance,
        private Parametros $parametrosDePopulacao,
        private Ciclo $cicloDePopulacao,
    ) {}

    /**
     * A eficiência da população neste tick, em pontos-base. 10.000 = sem penalidade.
     *
     * Estado de instância porque `produzir()` é chamado várias vezes por tick (uma por fatia de
     * upgrade) e todas têm de usar a MESMA medida — ver `handle()`.
     */
    private int $eficienciaDaPopulacao = 10_000;

    /**
     * Quanto a escassez de insumo essencial está custando à produção (A2.6).
     *
     * ⚠️ Esta é a segunda etapa da ativação da população, que o D-178 adiou de propósito: *"primeiro
     * a população existe e aparece; depois, com semanas de dados reais, ela restringe"*. O `Ciclo`
     * calculava `eficiencia_bps` desde então e **ninguém consumia** — era o número correto indo para
     * o vazio.
     *
     * ⚠️ E medi contra a produção real antes de ligar: com energia na cesta de consumo, **17 das 29
     * colônias** cairiam para metade da produção de uma vez. Energia saiu da cesta por dupla
     * contagem — ver a migration `energia_fora_da_cesta_da_populacao`. Sem ela, as 29 ficam em 100%.
     */
    private function medirEficiencia(Colony $colony): int
    {
        if (! $this->parametrosDePopulacao->ativo() || (int) $colony->populacao <= 0) {
            return 10_000;
        }

        $estoque = $colony->resources()->pluck('amount', 'resource_type')->all();

        return (int) $this->cicloDePopulacao->avancar($colony, $estoque, 1.0)['eficiencia_bps'];
    }

    public function handle(Colony $colony, CarbonInterface $agora): void
    {
        // Trunca ao segundo. `diffInSeconds` do Carbon 3 devolve FLOAT, e um delta de
        // 3600,99 s contaminaria toda a aritmética inteira da produção: a Destilaria
        // consumia 41 de biomassa onde devia consumir 40.
        $agora = $agora->copy()->startOfSecond();

        DB::transaction(function () use ($colony, $agora) {
            $colony = Colony::whereKey($colony->id)->lockForUpdate()->firstOrFail();
            $cursor = $colony->last_tick_at->copy()->startOfSecond();

            if ($cursor >= $agora) {
                return;
            }

            /*
             * ⚠️ A eficiência da população é medida ANTES de qualquer produção deste delta (A2.6).
             *
             * Se fosse medida depois, a colônia produziria cheio e só então descobriria que passou o
             * intervalo inteiro em escassez — a penalidade chegaria sempre um tick atrasada, e no
             * tick seguinte já não haveria escassez porque a produção do anterior a resolveu.
             *
             * Uma vez só para o delta inteiro, e não por fatia de upgrade: as fatias são o mesmo
             * intervalo de tempo real, e remedi-las daria pesos diferentes ao mesmo minuto.
             */
            /*
             * ⚠️ A2.3: as pesquisas vencidas concluem ANTES de qualquer produção deste delta.
             *
             * O docblock de `ConcluirPesquisa` já afirmava que *"`ColonyTick` chama isto antes de
             * calcular produção"* — e o `ColonyTick` NÃO o chamava. A frase descrevia uma intenção
             * que ninguém tinha ligado, e o efeito era pior que não ter a frase: uma pesquisa
             * iniciada nunca terminava, e a colônia perdia a vaga do Laboratório para sempre sem
             * receber bônus nenhum.
             *
             * Antes da produção, e não depois, porque senão o bônus recém-conquistado só valeria a
             * partir do minuto seguinte — e o jogador veria a barra encher sem nada mudar.
             */
            $this->concluirPesquisa->handle($colony);

            $this->eficienciaDaPopulacao = $this->medirEficiencia($colony);

            // Fatia o delta em cada conclusão de upgrade, na ordem em que ocorreram.
            while (true) {
                $emObra = BuildQueue::where('colony_id', $colony->id)
                    ->where('status', 'building')
                    ->where('finishes_at', '<=', $agora)
                    ->orderBy('finishes_at')->first();

                if (! $emObra) {
                    break;
                }

                $this->produzir($colony, $cursor, $emObra->finishes_at);
                $cursor = $emObra->finishes_at;
                $this->concluir($colony, $emObra, $cursor);
            }

            $this->produzir($colony, $cursor, $agora);

            /*
             * População (A2.2), **depois** da produção e uma vez por tick.
             *
             * Depois, e não antes, porque os colonos consomem o que a colônia acabou de produzir —
             * a ordem inversa faria a produção do intervalo chegar tarde demais para alimentar quem
             * estava lá durante ele.
             *
             * Uma vez por tick, e não por fatia de obra: o delta pode ser fatiado em várias partes
             * quando há upgrades concluindo, e cobrar consumo em cada fatia cobraria o mesmo período
             * mais de uma vez.
             */
            $this->popular($colony, $colony->last_tick_at, $agora);

            $colony->update(['last_tick_at' => $agora]);
        });
    }

    /**
     * Faz a população consumir e crescer no intervalo (A2.2.2 e A2.2.3).
     *
     * ## ⚠️ O que esta primeira ativação FAZ e o que NÃO faz
     *
     * **Faz:** a população cresce até o teto habitacional e consome água, oxigênio, biomassa e
     * energia — cerca de 3% da produção, por decisão do D-177 (população é mão de obra, não bocas).
     *
     * **NÃO faz, ainda:** não aplica a penalidade de eficiência por escassez, e não bloqueia nada
     * por falta de operadores. Essas são as travas da A2.6, e ligá-las junto seria mudar duas coisas
     * de uma vez num mundo com colônias reais — sem forma de saber qual delas causou o quê.
     *
     * É lançamento em duas etapas de propósito: primeiro a população passa a existir, crescer e
     * aparecer nos números; **depois**, com dados reais de semanas de jogo, ela passa a restringir.
     * Os parâmetros saíram de simulação, e simulação não é o mundo.
     *
     * Nada disto acontece enquanto `population_settings.ativo` for `false`.
     */
    private function popular(Colony $colony, CarbonInterface $de, CarbonInterface $ate): void
    {
        if (! $this->parametrosDePopulacao->ativo()) {
            return;
        }

        $horas = $de->diffInSeconds($ate) / 3600;

        if ($horas <= 0) {
            return;
        }

        $colony->load(['buildings', 'resources']);
        $estoque = $colony->resources->pluck('amount', 'resource_type')->all();

        $r = $this->cicloDePopulacao->avancar($colony, $estoque, $horas);

        foreach ($r['consumo'] as $recurso => $quanto) {
            $inteiro = (int) floor($quanto);

            if ($inteiro <= 0) {
                continue;
            }

            /*
             * `where amount >= x` no UPDATE, como o resto do domínio faz: a coluna é unsigned, e um
             * saldo negativo seria erro de banco em vez de bug silencioso. Se não couber, não
             * consome — a população não morre por isso (§6.6: degrada, não se perde).
             */
            $colony->resources()
                ->where('resource_type', $recurso)
                ->where('amount', '>=', $inteiro)
                ->decrement('amount', $inteiro);
        }

        $colony->update([
            'populacao' => $r['populacao_nova'],
            'populacao_resto_milli' => $r['resto_milli'],
        ]);
    }

    /** Conclui o upgrade, lança o subsídio e promove o próximo da fila. */
    private function concluir(Colony $colony, BuildQueue $item, CarbonInterface $em): void
    {
        $building = $item->building;
        $building->update([
            'level' => $item->target_level,
            'upgrade_started_at' => null,
            'upgrade_finish_at' => null,
        ]);

        // `position` só vale entre os ativos: liberá-la aqui é o que permite à próxima construção
        // ocupar a posição 1 de novo, sem colidir no índice único. Ver D-53.
        $item->update(['status' => 'done', 'position' => null]);

        // O Marco anda por atos (D-75), e concluir um nível de obra é o mais comum deles. Um
        // `concluir` = um nível — a fila nunca pula degrau.
        app(ConcederXp::class)->handle(
            $colony->id, 'obra_concluida', "build:{$building->type}:n{$item->target_level}",
        );
        app(Progresso::class)->registrar($colony->id, 'obra_concluida');

        // §24.7: "o ledger registra subsídio de 100% no momento de concluir".
        // Quem foi subsidiado não pagou nada no enfileiramento (D-15); o lançamento aqui é
        // o registro contábil do que o Governo Central custeou.
        if ($item->subsidized) {
            foreach ($item->quoted_cost_json as $recurso => $qtd) {
                Ledger::create([
                    'colony_id' => $colony->id,
                    'type' => 'subsidio_governo',
                    'amount' => $qtd,
                    'resource_type' => $recurso,
                    'ref' => "build:{$building->type}:n{$item->target_level}",
                    'created_at' => $em,
                ]);
            }
        }

        $proximo = BuildQueue::where('colony_id', $colony->id)
            ->where('status', 'queued')->orderBy('position')->first();

        if ($proximo) {
            $spec = DB::table('building_specs')
                ->where(['building_type' => $proximo->building->type, 'level' => $proximo->target_level])
                ->first();

            $proximo->update([
                'status' => 'building',
                'starts_at' => $em,
                'finishes_at' => $em->copy()->addSeconds($spec->build_time_seconds),
            ]);
            $proximo->building->update([
                'upgrade_started_at' => $proximo->starts_at,
                'upgrade_finish_at' => $proximo->finishes_at,
            ]);
        }
    }

    /**
     * As taxas nominais por hora de toda construção erguida — extraído de `produzir()` (sem
     * bônus além do de Endurance, sem leitura/escrita de estoque) para ser reaproveitado pelo
     * card "Recursos por hora" (`Domain\Production\TaxasDeProducao`), sem duplicar receita,
     * manutenção ou lote nenhum. `produzir()` chama isto e faz o netting de energia/consumosExtras
     * por cima; quem só quer a taxa BRUTA (produzido separado de consumido) usa o array como está.
     *
     * @return array{
     *     taxas: array<string,int>,
     *     consumoEnergia: int,
     *     consumosExtras: array<string,int>,
     *     taxaDestilaria: int,
     *     taxaSiderurgica: int,
     *     taxaCompostos: int,
     *     taxaComponentes: array<string,int>,
     * }
     */
    public function taxasNominais(Colony $colony): array
    {
        $erguidas = $this->erguidasComSpec($colony);
        $manutencao = $this->manutencaoPorTipo($erguidas);

        /*
         * Bônus de produção da Endurance (D-135) — uma query batched, não uma por construção
         * erguida (mesmo espírito anti-N+1 de `manutencaoPorTipo()`, logo acima). `$bonusPara()`
         * soma o bônus específico do tipo com o `'global'` (peça que bonifica TUDO) e aplica o
         * teto agregado uma vez só — mesma leitura de `EfeitosDaEndurance::bonusDeProducao()`.
         */
        $bonusPorAlvo = $this->efeitosDaEndurance->bonusDeProducaoPorAlvo($colony);
        $tetoProducao = EfeitosDaEndurance::tetoBps(EfeitosDaEndurance::PRODUCAO_BONUS);

        /*
         * ⚠️ A2.3: a PESQUISA soma aqui, e o teto é AGREGADO — aplicado uma vez sobre as duas fontes.
         *
         * Cada fonte respeitar o teto sozinha permitiria que duas de 30% dessem 60%, e aí o teto
         * deixaria de ser teto. Ele existe para limitar o TOTAL que uma colônia pode acumular; de
         * quantos lugares o bônus veio é assunto de quem o produz, não de quem o limita.
         *
         * Com a pesquisa desligada — ou sem tecnologia concluída — isto devolve zero e nada muda.
         */
        $bonusDePesquisa = $this->efeitosDaPesquisa->bonusDeProducaoPorAlvo($colony);

        $bonusPara = fn (string $tipo): int => min(
            $tetoProducao,
            max(0, ($bonusPorAlvo[$tipo] ?? 0) + ($bonusPorAlvo[EfeitosDaEndurance::ALVO_GLOBAL] ?? 0)
                + ($bonusDePesquisa[$tipo] ?? 0) + ($bonusDePesquisa[EfeitosDaEndurance::ALVO_GLOBAL] ?? 0)),
        );

        // Taxas por hora, somadas entre construções. Desde o D-59 a soma é por LINHA, não por
        // tipo: Mina Local, Oficina, Refinaria e Destilaria podem ocupar mais de um slot, e duas
        // Minas nível 1 produzem 15+15. Antes isto era indexado por tipo e a segunda cópia
        // simplesmente sumia da conta.
        $taxas = [];
        $consumoEnergia = 0;
        // Manutenção de estruturas (D-112) — aditiva ao consumo de energia do GDD, por cima:
        // qualquer recurso primário/industrial que o admin tiver configurado por construção.
        $consumosExtras = [];
        $taxaDestilaria = 0;
        $taxaSiderurgica = 0;
        $taxaCompostos = 0;
        // Componentes por receita: cada Oficina escolhe a sua (§24.5), e duas Oficinas com
        // receitas diferentes consomem insumos diferentes. Somar as taxas antes de saber a
        // receita misturaria as duas contas.
        $taxaComponentes = [];

        foreach ($erguidas as ['tipo' => $tipo, 'spec' => $spec, 'recipe' => $recipe]) {
            $consumoEnergia += $spec->energia_consumo_hora;

            foreach ($manutencao[$tipo] ?? [] as $recurso => $qtd) {
                $consumosExtras[$recurso] = ($consumosExtras[$recurso] ?? 0) + $qtd;
            }

            if (! $spec->producao_hora_json) {
                continue;
            }

            $producao = json_decode($spec->producao_hora_json, true);

            if ($tipo === 'destilaria') {
                // D-135: bônus de produção na Destilaria é THROUGHPUT — mais saída, mas também
                // mais Biomassa/Energia consumida (a taxa de ENTRADA é o que se multiplica; quem
                // decide isso é `converter()`, mais abaixo, pela receita).
                $taxaDestilaria += EfeitosDaEndurance::aplicarBonus($producao['biocombustivel'], $bonusPara($tipo));

                continue;
            }

            if ($tipo === 'industria_siderurgica') {
                // O JSON reaproveita a chave `metal_bruto` da Mina, mas aqui é o que ela
                // PROCESSA por hora, não o que produz (D-82) — por isso o tratamento à parte.
                // D-135: mesmo throughput da Destilaria — processa mais Metal Bruto por hora.
                $taxaSiderurgica += EfeitosDaEndurance::aplicarBonus($producao[Siderurgica::INSUMO] ?? 0, $bonusPara($tipo));

                continue;
            }

            if ($tipo === 'refinaria_quimica') {
                $taxaCompostos += EfeitosDaEndurance::aplicarBonus($producao['compostos_quimicos'] ?? 0, $bonusPara($tipo));

                continue;
            }

            if ($tipo === 'oficina') {
                // Ligas Metálicas não estão mais no JSON da Oficina (D-83): só a Indústria
                // Siderúrgica as produz agora. Só Componentes entram via §24.5.
                $taxa = EfeitosDaEndurance::aplicarBonus($producao['componentes_eletronicos'] ?? 0, $bonusPara($tipo));
                $taxaComponentes[$recipe] = ($taxaComponentes[$recipe] ?? 0) + $taxa;

                continue;
            }

            if (in_array($tipo, self::PRODUCAO_SEM_INSUMO, true)) {
                // D-135: aqui o bônus é de graça — estas 5 construções não consomem insumo, então
                // "mais saída" não custa nada a mais.
                $bonus = $bonusPara($tipo);

                foreach ($producao as $recurso => $qtd) {
                    $taxas[$recurso] = ($taxas[$recurso] ?? 0) + EfeitosDaEndurance::aplicarBonus($qtd, $bonus);
                }
            }
        }

        return compact(
            'taxas',
            'consumoEnergia',
            'consumosExtras',
            'taxaDestilaria',
            'taxaSiderurgica',
            'taxaCompostos',
            'taxaComponentes',
        );
    }

    private function produzir(Colony $colony, CarbonInterface $de, CarbonInterface $ate): void
    {
        // Timestamps inteiros, nunca diffInSeconds: em Carbon 3 ele retorna float.
        $segundos = $ate->getTimestamp() - $de->getTimestamp();

        if ($segundos <= 0) {
            return;
        }

        [
            'taxas' => $taxas,
            'consumoEnergia' => $consumoEnergia,
            'consumosExtras' => $consumosExtras,
            'taxaDestilaria' => $taxaDestilaria,
            'taxaSiderurgica' => $taxaSiderurgica,
            'taxaCompostos' => $taxaCompostos,
            'taxaComponentes' => $taxaComponentes,
        ] = $this->taxasNominais($colony);

        // Energia é estoque e fluxo: o Reator credita, toda construção debita o consumo
        // operacional. O saldo pode ficar negativo — o GDD não define o que ocorre então,
        // e nós apenas travamos o estoque em zero. Ver docs/decisoes.md D-20.
        $taxas['energia'] = ($taxas['energia'] ?? 0) - $consumoEnergia;

        foreach ($consumosExtras as $recurso => $qtd) {
            $taxas[$recurso] = ($taxas[$recurso] ?? 0) - $qtd;
        }

        $estoque = $colony->resources()->lockForUpdate()->get()->keyBy('resource_type');

        foreach ($taxas as $recurso => $porHora) {
            /*
             * §6.6 e §7.x: a escassez DEGRADA a produção, não mata ninguém nem destrói nada. Só o
             * ganho encolhe — o consumo (taxa negativa) passa inteiro, senão a penalidade viraria
             * um desconto no gasto, que é o oposto de uma penalidade.
             */
            if ($porHora > 0 && $this->eficienciaDaPopulacao < 10_000) {
                $porHora = intdiv($porHora * $this->eficienciaDaPopulacao, 10_000);
            }

            /*
             * A2.8: o Motor de Eventos entra AQUI, e só aqui — mexendo na TAXA.
             *
             * ⚠️ O evento nunca escreve no ledger: quem credita continua sendo este laço. É o que
             * mantém "um lançamento por fato econômico" e impede que a telemetria derivada do ledger
             * (D-163) passe a ver receita que ninguém produziu.
             *
             * O sinal da taxa decide qual modificador se aplica: taxa positiva é PRODUÇÃO, taxa
             * negativa é CONSUMO. Ler o sinal em vez de manter duas listas foi a lição do D-164.
             */
            $modificador = $porHora >= 0 ? Modificadores::PRODUCAO : Modificadores::CONSUMO;
            $bps = $this->eventos->para($colony, $modificador, $de, $ate, $recurso);

            if ($bps !== 10_000) {
                // `intdiv` trunca em direção a zero, que é o certo para os dois sinais.
                $porHora = intdiv($porHora * $bps, 10_000);
            }

            $numerador = $porHora * $segundos;

            /*
             * §14: o teto TRAVA a extração — ela simplesmente para de render, e o que já está
             * guardado não é tocado. Só o ganho é limitado: consumo (numerador negativo) passa
             * inteiro, senão o teto viraria uma trava de gasto, que ninguém pediu.
             */
            if ($numerador > 0) {
                $numerador = min($numerador, $this->espacoNumerador($colony, $estoque[$recurso], $recurso));
            }

            $this->acumular($estoque[$recurso], $numerador);
        }

        // Ordem importa: a receita Avançada de Componentes consome Biocombustível, que a
        // Destilaria acabou de produzir neste mesmo segmento.
        if ($taxaDestilaria > 0) {
            // §21.9/D-131: o Tanque de Combustível trava a produção no teto — a Destilaria para
            // de converter (sem gastar Biomassa/Energia à toa) assim que ele enche.
            $teto = $this->tetoDoTanque->capacidade($colony);
            $this->converter($estoque, $taxaDestilaria * $segundos, self::RECEITA_DESTILARIA, 'biocombustivel', $teto);
        }

        /*
         * A Refinaria Química (D-83) e a Indústria Siderúrgica (D-82) DISPUTAM o mesmo Metal
         * Bruto da colônia — a ordem entre as duas decide quem come primeiro quando ele escasseia.
         * A Refinaria vem antes: o consumo dela é pequeno (1 por Composto) perto do da Siderúrgica,
         * então processá-la primeiro raramente falta a vez da outra.
         */
        if ($taxaCompostos > 0) {
            $this->converter(
                $estoque, $taxaCompostos * $segundos, self::RECEITA_COMPOSTOS, 'compostos_quimicos',
                $this->tetoDoEstoque->capacidade($colony, 'compostos_quimicos'),
            );
        }

        if ($taxaSiderurgica > 0) {
            $this->processarSiderurgica($colony, $estoque, $taxaSiderurgica, $segundos);
        }

        // Uma conversão por receita em uso. Duas Oficinas na mesma receita entram somadas; em
        // receitas distintas, cada uma consome os seus insumos. `ksort` só para a ordem não
        // depender de qual Oficina foi lida primeiro: quando os insumos escasseiam, quem converte
        // antes leva — e isso não pode variar a cada tick.
        ksort($taxaComponentes);

        foreach ($taxaComponentes as $codigo => $taxa) {
            if ($taxa <= 0) {
                continue;
            }

            $this->converter(
                $estoque, $taxa * $segundos, $this->receita($codigo), 'componentes_eletronicos',
                $this->tetoDoEstoque->capacidade($colony, 'componentes_eletronicos'),
            );
        }

        foreach ($estoque as $linha) {
            $linha->save();
        }
    }

    /**
     * Quanto ainda cabe, em unidades de 1/3600 — `PHP_INT_MAX` quando não há teto.
     *
     * O sentinela é o infinito da aritmética, e não um número grande escolhido a dedo: qualquer
     * `min()` contra ele devolve o outro lado, que é exatamente o comportamento de "sem teto".
     */
    private function espacoNumerador(Colony $colony, $linha, string $recurso): int
    {
        $teto = $this->tetoDoEstoque->capacidade($colony, $recurso);

        if ($teto === null) {
            return PHP_INT_MAX;
        }

        return max(0, $teto * 3600 - ($linha->amount * 3600 + $linha->production_remainder));
    }

    /**
     * Soma `numerador` unidades de 1/3600 ao estoque, carregando o resto. Trava em zero:
     * a coluna é unsigned e o GDD não define dívida de recurso.
     */
    private function acumular($linha, int $numerador): void
    {
        $total = $linha->amount * 3600 + $linha->production_remainder + $numerador;

        if ($total < 0) {
            $total = 0;
        }

        $linha->amount = intdiv($total, 3600);
        $linha->production_remainder = $total % 3600;
    }

    /**
     * Converte insumos em saída, limitado pelo insumo disponível. Serve à Destilaria
     * (2 Biomassa + 3 Energia -> 1 Biocombustível, §18.2) e à Oficina (§24.5).
     *
     * `$numeradorDesejado` e o resultado estão em unidades de 1/3600, como o resto do tick.
     * Se faltar qualquer insumo, produz-se o máximo possível — nunca parcialmente debitado.
     *
     * `$tetoSaida` (§21.9/D-131, só a Destilaria passa) trava a produção no que ainda cabe: o
     * insumo não é gasto além do que o Tanque tem espaço para receber. Nula para as outras duas
     * saídas (Compostos Químicos, Componentes Eletrônicos), que não têm teto publicado.
     *
     * @param  array<string,int>  $receita  insumo => quantidade por unidade produzida
     */
    private function converter($estoque, int $numeradorDesejado, array $receita, string $saida, ?int $tetoSaida = null): void
    {
        $possivel = $numeradorDesejado;

        foreach ($receita as $insumo => $porUnidade) {
            if (! isset($estoque[$insumo])) {
                return;   // insumo fora do catálogo da colônia: não converte nada
            }
            $disponivel = $estoque[$insumo]->amount * 3600 + $estoque[$insumo]->production_remainder;
            $possivel = min($possivel, intdiv($disponivel, $porUnidade));
        }

        if ($tetoSaida !== null) {
            $linhaSaida = $estoque[$saida];
            $livre = $tetoSaida * 3600 - ($linhaSaida->amount * 3600 + $linhaSaida->production_remainder);
            $possivel = min($possivel, max(0, $livre));
        }

        if ($possivel <= 0) {
            return;
        }

        foreach ($receita as $insumo => $porUnidade) {
            $this->acumular($estoque[$insumo], -$possivel * $porUnidade);
        }

        $this->acumular($estoque[$saida], $possivel);
    }

    /**
     * A Indústria Siderúrgica processa Metal Bruto em Ligas Metálicas e nos cinco minerais
     * eletrônicos (D-82) — mas só em LOTES INTEIROS de `Siderurgica::BASE` (1000): a receita tem
     * seis saídas simultâneas, e um lote fracionado deixaria alguma delas sem unidade inteira
     * pra creditar.
     *
     * Por isso não é `converter()` comum: o excedente que não fecha um lote fica guardado em
     * `colonies.siderurgica_lote_remainder` — como o `production_remainder` de cada recurso, só
     * que o que se acumula aqui é PROGRESSO RUMO AO PRÓXIMO LOTE, não uma fração de unidade. Sem
     * isto, uma taxa de 15 Metal Bruto/h (0,015 Tungstênio/h) nunca acumularia Tungstênio nenhum:
     * cada tick isolado processa bem menos que os 1000 de um lote.
     */
    private function processarSiderurgica(Colony $colony, $estoque, int $taxaPorHora, int $segundos): void
    {
        if (! isset($estoque[Siderurgica::INSUMO])) {
            return;
        }

        $insumo = $estoque[Siderurgica::INSUMO];
        $desejado = $taxaPorHora * $segundos;
        $disponivel = $insumo->amount * 3600 + $insumo->production_remainder;
        $possivel = min($desejado, $disponivel);

        if ($possivel <= 0) {
            return;
        }

        $this->acumular($insumo, -$possivel);

        $loteNumerador = Siderurgica::BASE * 3600;
        $total = $possivel + $colony->siderurgica_lote_remainder;
        $lotes = intdiv($total, $loteNumerador);
        $colony->siderurgica_lote_remainder = $total % $loteNumerador;

        if ($lotes <= 0) {
            return;
        }

        /*
         * ⚠️ O lote é indivisível, e por isso o teto o trava POR INTEIRO.
         *
         * A receita tem seis saídas simultâneas. Se uma delas não couber, creditar as outras cinco
         * derramaria a sexta — e o §14 promete que nada transborda e vira desperdício. Então o
         * limite é o número de lotes que cabem na saída MAIS APERTADA.
         */
        foreach (Siderurgica::SAIDAS as $recurso => $porLote) {
            if (! isset($estoque[$recurso])) {
                continue;
            }

            $espaco = $this->espacoNumerador($colony, $estoque[$recurso], $recurso);

            if ($espaco !== PHP_INT_MAX) {
                $lotes = min($lotes, intdiv($espaco, $porLote * 3600));
            }
        }

        if ($lotes <= 0) {
            /*
             * Devolve o progresso ao acumulador em vez de perdê-lo: o Metal Bruto já foi debitado
             * acima, e engolir o lote junto com o teto cheio cobraria o insumo por nada.
             */
            $colony->siderurgica_lote_remainder = min(
                $loteNumerador - 1, $colony->siderurgica_lote_remainder + $loteNumerador,
            );

            return;
        }

        foreach (Siderurgica::SAIDAS as $recurso => $porLote) {
            if (isset($estoque[$recurso])) {
                $this->acumular($estoque[$recurso], $lotes * $porLote * 3600);
            }
        }
    }

    /**
     * §24.5 dá três receitas. O parêntese do GDD mistura faixa de nível da Oficina
     * ("Oficina nível 1-2") com unidades de destino ("Furgão, Robô Minerador"), e a Oficina
     * não custa Componentes nos níveis 1-2 — então "destino" não pode ser a leitura.
     * O jogador escolhe a receita; o padrão é a Básica. Ver docs/decisoes.md D-23.
     *
     * @return array<string,int>
     */
    public function receita(string $codigo): array
    {
        $insumos = DB::table('component_recipes')->where('code', $codigo)->value('insumos_json');

        return json_decode($insumos ?? '{}', true);
    }

    /**
     * Uma entrada por construção ERGUIDA — não por tipo (D-59).
     *
     * A versão anterior devolvia um mapa `tipo => spec`, o que era exato enquanto valia o
     * `unique(colony_id, type)`. Com a repetição, esse mapa engoliria a segunda Mina em silêncio:
     * a produção sairia pela metade e ninguém veria erro nenhum. É lista, e é lista de propósito.
     *
     * @return list<array{tipo: string, spec: object, recipe: string}>
     */
    private function erguidasComSpec(Colony $colony): array
    {
        $erguidas = $colony->buildings()->where('level', '>', 0)->get(['type', 'level', 'recipe']);

        if ($erguidas->isEmpty()) {
            return [];
        }

        $specs = DB::table('building_specs')
            ->whereIn('building_type', $erguidas->pluck('type')->unique())
            ->get()->keyBy(fn ($s) => "{$s->building_type}:{$s->level}");

        $saida = [];
        foreach ($erguidas as $b) {
            if ($spec = $specs->get("{$b->type}:{$b->level}")) {
                $saida[] = [
                    'tipo' => $b->type,
                    'spec' => $spec,
                    'recipe' => $b->recipe ?? self::RECEITA_PADRAO,
                ];
            }
        }

        return $saida;
    }

    /**
     * Consumo extra de manutenção (D-112), por tipo de construção — batched numa query só pros
     * tipos presentes na colônia, igual `erguidasComSpec()` faz pra specs, pra não virar N+1
     * dentro do loop de `produzir()`.
     */
    private function manutencaoPorTipo(array $erguidas): array
    {
        if ($erguidas === []) {
            return [];
        }

        $tipos = array_unique(array_column($erguidas, 'tipo'));

        return DB::table('manutencao_estruturas')
            ->whereIn('building_type', $tipos)
            ->get()
            ->groupBy('building_type')
            ->map(fn ($linhas) => $linhas->pluck('qtd_hora', 'resource_type')->all())
            ->all();
    }
}
