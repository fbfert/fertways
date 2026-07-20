<?php

namespace App\Domain\Production;

use App\Domain\Colony\TetoDoTanque;
use App\Domain\Endurance\EfeitosDaEndurance;
use App\Models\Building;
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
    private const RECEITA_DESTILARIA = ['biomassa' => 2, 'energia' => 3];

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
    private const RECEITA_COMPOSTOS = [
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
        private EfeitosDaEndurance $efeitosDaEndurance,
    ) {}

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

            $colony->update(['last_tick_at' => $agora]);
        });
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
        app(\App\Domain\Marco\ConcederXp::class)->handle(
            $colony->id, 'obra_concluida', "build:{$building->type}:n{$item->target_level}",
        );
        app(\App\Domain\Missoes\Progresso::class)->registrar($colony->id, 'obra_concluida');

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

    private function produzir(Colony $colony, CarbonInterface $de, CarbonInterface $ate): void
    {
        // Timestamps inteiros, nunca diffInSeconds: em Carbon 3 ele retorna float.
        $segundos = $ate->getTimestamp() - $de->getTimestamp();

        if ($segundos <= 0) {
            return;
        }

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
        $bonusPara = fn (string $tipo): int => min(
            $tetoProducao,
            max(0, ($bonusPorAlvo[$tipo] ?? 0) + ($bonusPorAlvo[EfeitosDaEndurance::ALVO_GLOBAL] ?? 0)),
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

        // Energia é estoque e fluxo: o Reator credita, toda construção debita o consumo
        // operacional. O saldo pode ficar negativo — o GDD não define o que ocorre então,
        // e nós apenas travamos o estoque em zero. Ver docs/decisoes.md D-20.
        $taxas['energia'] = ($taxas['energia'] ?? 0) - $consumoEnergia;

        foreach ($consumosExtras as $recurso => $qtd) {
            $taxas[$recurso] = ($taxas[$recurso] ?? 0) - $qtd;
        }

        $estoque = $colony->resources()->lockForUpdate()->get()->keyBy('resource_type');

        foreach ($taxas as $recurso => $porHora) {
            $this->acumular($estoque[$recurso], $porHora * $segundos);
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
            $this->converter($estoque, $taxaCompostos * $segundos, self::RECEITA_COMPOSTOS, 'compostos_quimicos');
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

            $this->converter($estoque, $taxa * $segundos, $this->receita($codigo), 'componentes_eletronicos');
        }

        foreach ($estoque as $linha) {
            $linha->save();
        }
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
     * @param array<string,int> $receita insumo => quantidade por unidade produzida
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
    private function receita(string $codigo): array
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
