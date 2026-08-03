<?php

namespace App\Console\Commands;

use App\Domain\Colony\TetoDoEstoque;
use App\Models\Colony;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Grandfathering do teto de estoque (D-191, opção **d**).
 *
 * ## A regra, que é uma promessa
 *
 * *"Nenhuma colônia existente pode parar de produzir por uma regra que não existia quando ela foi
 * construída."* (§6.7)
 *
 * Cada linha de `resources` que já passa da curva ganha um **piso pessoal** em `storage_cap`: o que
 * a colônia tinha no dia da virada, **mais a folga**. Daí em diante o teto é
 * `max(curva do nível, piso)`.
 *
 * ## ⚠️ Por que ele é indispensável, e não uma gentileza
 *
 * Medido em produção: **as 29 colônias** têm ao menos um dos quatro essenciais acima do teto. Ligar
 * sem isto pararia a produção de todas de uma vez. E o caminho óbvio — subir o Depósito Local —
 * **não existe**: elas precisariam dos níveis 9 a 18, e o prédio para no 10.
 *
 * ## ⚠️ A folga é o que cumpre o §6.7, não conforto
 *
 * Piso igual ao estoque exato faria `espacoLivre` valer zero e a produção parar **no mesmo instante**
 * da virada — a regra que o piso existe para evitar. A folga dá tempo de ver o teto chegar.
 *
 *     php84 artisan fertways:estoque-grandfather
 *     php84 artisan fertways:estoque-grandfather --aplicar
 */
class EstoqueGrandfather extends Command
{
    protected $signature = 'fertways:estoque-grandfather
                            {--aplicar : sem isto, só relata o que faria}';

    protected $description = 'Grava o piso pessoal do teto de estoque nas colônias existentes';

    public function handle(TetoDoEstoque $teto): int
    {
        $p = DB::table('estoque_settings')->find(1);
        $folga = (int) $p->grandfather_folga_bps;

        /*
         * ⚠️ A curva é lida com a chave LIGADA de mentira, dentro de uma leitura só.
         *
         * `TetoDoEstoque::capacidade()` devolve `null` enquanto `ativo` for falso — e o
         * grandfathering roda ANTES da virada, por definição. Sem isto o comando mediria o nada e
         * diria que não há nada a fazer, que é o pior jeito possível de falhar.
         */
        $curvaDe = function (Colony $c) use ($p): int {
            $nivel = max(1, (int) $c->buildings->firstWhere('type', 'deposito_local')?->level);
            $cap = (int) $p->capacidade_base;

            for ($i = 1; $i < $nivel; $i++) {
                $cap = intdiv($cap * (int) $p->capacidade_fator_milesimos, 1000);
            }

            return $cap;
        };

        $plano = [];
        $colonias = 0;

        foreach (Colony::with(['buildings', 'resources'])->get() as $c) {
            $curva = $curvaDe($c);
            $tocou = false;

            foreach ($c->resources as $r) {
                if (in_array($r->resource_type, TetoDoEstoque::SEM_TETO_GERAL, true)) {
                    continue;
                }

                /*
                 * Só quem PASSA da curva ganha piso. Gravar em todo mundo encheria a coluna de
                 * números que não fazem diferença nenhuma — e um dia alguém leria isso como
                 * "o teto é este", quando o teto é a curva.
                 */
                if ((int) $r->amount <= $curva) {
                    continue;
                }

                $plano[] = [
                    'id' => $r->id,
                    'piso' => (int) ceil((int) $r->amount * (10_000 + $folga) / 10_000),
                ];
                $tocou = true;
            }

            if ($tocou) {
                $colonias++;
            }
        }

        if ($plano === []) {
            $this->info('Nenhuma linha acima da curva. Nada a fazer.');

            return self::SUCCESS;
        }

        $pisos = array_column($plano, 'piso');

        if (! $this->option('aplicar')) {
            $this->warn(count($plano).' linha(s) de estoque em '.$colonias.' colônia(s) ganhariam piso.');
            $this->line('Folga: '.($folga / 100).'% · menor piso: '.min($pisos).' · maior: '.max($pisos));
            $this->line('Sem isto, essas colônias parariam de produzir no instante da virada (§6.7).');
            $this->line('Rode de novo com --aplicar.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($plano) {
            foreach ($plano as $linha) {
                DB::table('resources')->where('id', $linha['id'])->update(['storage_cap' => $linha['piso']]);
            }
        });

        $this->info(count($plano).' piso(s) gravados em '.$colonias.' colônia(s).');
        $this->line('Nenhum estoque foi tocado: o piso só decide até onde a produção pode crescer.');

        return self::SUCCESS;
    }
}
