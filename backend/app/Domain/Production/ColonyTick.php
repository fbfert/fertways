<?php

namespace App\Domain\Production;

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
     * Saídas cuja RECEITA o GDD nunca publica, embora publique a taxa em §19.3:
     * Ligas Metálicas ("Metal Bruto é extraído, Ligas são transformadas" — sem proporção) e
     * Compostos Químicos (nada). Creditá-las sem debitar insumo criaria recurso do nada.
     * Ver docs/decisoes.md D-19.
     *
     * Componentes Eletrônicos NÃO estão aqui: §24.5 publica as três receitas.
     */
    private const SAIDAS_SEM_RECEITA = ['ligas_metalicas', 'compostos_quimicos'];

    /** Receita usada pela Oficina quando o jogador não escolheu nenhuma. */
    public const RECEITA_PADRAO = 'basica';

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

        $item->update(['status' => 'done']);

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

        $especificacoes = $this->specsDaColonia($colony);

        // Taxas por hora, somadas entre construções.
        $taxas = [];
        $consumoEnergia = 0;
        $taxaDestilaria = 0;
        $taxaComponentes = 0;

        foreach ($especificacoes as $tipo => $spec) {
            $consumoEnergia += $spec->energia_consumo_hora;

            if (! $spec->producao_hora_json) {
                continue;
            }

            $producao = json_decode($spec->producao_hora_json, true);

            if ($tipo === 'destilaria') {
                $taxaDestilaria = $producao['biocombustivel'];
                continue;
            }

            if ($tipo === 'oficina') {
                // Ligas ficam de fora (sem receita); Componentes entram via §24.5.
                $taxaComponentes = $producao['componentes_eletronicos'] ?? 0;
                continue;
            }

            if (in_array($tipo, self::PRODUCAO_SEM_INSUMO, true)) {
                foreach ($producao as $recurso => $qtd) {
                    if (! in_array($recurso, self::SAIDAS_SEM_RECEITA, true)) {
                        $taxas[$recurso] = ($taxas[$recurso] ?? 0) + $qtd;
                    }
                }
            }
        }

        // Energia é estoque e fluxo: o Reator credita, toda construção debita o consumo
        // operacional. O saldo pode ficar negativo — o GDD não define o que ocorre então,
        // e nós apenas travamos o estoque em zero. Ver docs/decisoes.md D-20.
        $taxas['energia'] = ($taxas['energia'] ?? 0) - $consumoEnergia;

        $estoque = $colony->resources()->lockForUpdate()->get()->keyBy('resource_type');

        foreach ($taxas as $recurso => $porHora) {
            $this->acumular($estoque[$recurso], $porHora * $segundos);
        }

        // Ordem importa: a receita Avançada de Componentes consome Biocombustível, que a
        // Destilaria acabou de produzir neste mesmo segmento.
        if ($taxaDestilaria > 0) {
            $this->converter($estoque, $taxaDestilaria * $segundos, self::RECEITA_DESTILARIA, 'biocombustivel');
        }

        if ($taxaComponentes > 0) {
            $receita = $this->receitaDaOficina($colony);
            $this->converter($estoque, $taxaComponentes * $segundos, $receita, 'componentes_eletronicos');
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
     * @param array<string,int> $receita insumo => quantidade por unidade produzida
     */
    private function converter($estoque, int $numeradorDesejado, array $receita, string $saida): void
    {
        $possivel = $numeradorDesejado;

        foreach ($receita as $insumo => $porUnidade) {
            if (! isset($estoque[$insumo])) {
                return;   // insumo fora do catálogo da colônia: não converte nada
            }
            $disponivel = $estoque[$insumo]->amount * 3600 + $estoque[$insumo]->production_remainder;
            $possivel = min($possivel, intdiv($disponivel, $porUnidade));
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
     * §24.5 dá três receitas. O parêntese do GDD mistura faixa de nível da Oficina
     * ("Oficina nível 1-2") com unidades de destino ("Furgão, Robô Minerador"), e a Oficina
     * não custa Componentes nos níveis 1-2 — então "destino" não pode ser a leitura.
     * O jogador escolhe a receita; o padrão é a Básica. Ver docs/decisoes.md D-23.
     *
     * @return array<string,int>
     */
    private function receitaDaOficina(Colony $colony): array
    {
        $escolhida = $colony->buildings()->where('type', 'oficina')->value('recipe')
            ?? self::RECEITA_PADRAO;

        $receita = DB::table('component_recipes')->where('code', $escolhida)->value('insumos_json');

        return json_decode($receita ?? '{}', true);
    }

    /** @return array<string, object> spec vigente de cada construção erguida */
    private function specsDaColonia(Colony $colony): array
    {
        $erguidas = $colony->buildings()->where('level', '>', 0)->get(['type', 'level']);

        if ($erguidas->isEmpty()) {
            return [];
        }

        $specs = DB::table('building_specs')
            ->whereIn('building_type', $erguidas->pluck('type'))
            ->get()->keyBy(fn ($s) => "{$s->building_type}:{$s->level}");

        $saida = [];
        foreach ($erguidas as $b) {
            if ($spec = $specs->get("{$b->type}:{$b->level}")) {
                $saida[$b->type] = $spec;
            }
        }

        return $saida;
    }
}
