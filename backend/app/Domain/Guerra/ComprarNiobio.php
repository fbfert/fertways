<?php

namespace App\Domain\Guerra;

use App\Domain\Treasury\Tesouro;
use App\Exceptions\DomainRuleException;
use App\Models\Colony;
use App\Models\Ledger;
use App\Models\WarSetting;
use Illuminate\Support\Facades\DB;

/**
 * O governo vende Nióbio Alienígena (docs/decisoes.md D-66). **É o que destrava a guerra.**
 *
 * Sem isto, a guerra nasce morta e não é figura de retórica: a Sentinela — a única unidade com poder
 * de ataque (§27.1) — custa **3 Nióbio**, o Quartel onde ela é feita custa outros **3**, e **nada no
 * jogo produz Nióbio**. O planeta inteiro tem 20 unidades, todas do kit de fundação. Cada colônia
 * ergueria **uma** Sentinela, com 80 pontos de ataque, contra uma zona guarnecida com 500 de defesa.
 * Atacar seria matematicamente impossível.
 *
 * A saída é canônica, não inventada: o **D-17** lista "contratos do governo" como fonte de raros da
 * Temporada 1, e o Tesouro foi dotado com 10.000 de cada recurso no D-57. O precedente é o Ministério
 * dos Transportes (D-60): o governo fabrica, o colono compra, o caixa é a fonte — e **se o caixa
 * secar, não há mais.**
 *
 * O preço é do **operador** (`war_settings.niobio_preco_micro`), e nasce em 10× o preço de referência
 * do §06 — 3,163 Fert$. Ao preço de referência puro o raro seria enfeite: três Nióbio custariam 95
 * centavos, e o kit inicial de um colono é 50 Fert$.
 */
class ComprarNiobio
{
    private const RECURSO = 'niobio_alienigena';

    public function __construct(private Tesouro $tesouro) {}

    public function handle(Colony $colony, int $quantidade): int
    {
        if ($quantidade < 1) {
            throw new DomainRuleException('quantidade_invalida', 'Compre ao menos uma unidade.');
        }

        return DB::transaction(function () use ($colony, $quantidade) {
            $colony = Colony::whereKey($colony->id)->lockForUpdate()->firstOrFail();

            $preco = WarSetting::singleton()->niobio_preco_micro;
            $total = $preco * $quantidade;

            if ($colony->fert_micro < $total) {
                throw new DomainRuleException(
                    'fert_insuficiente',
                    'Faltam Fert$: '.number_format($total / 1_000_000, 2, ',', '.')
                    .' para '.$quantidade.' de Nióbio.',
                );
            }

            // O Tesouro é a fonte, e ele pode secar — como a linha de montagem do D-60.
            if (! $this->tesouro->gastar([self::RECURSO => $quantidade], "venda_niobio:{$colony->id}")) {
                throw new DomainRuleException(
                    'tesouro_sem_niobio',
                    'O Tesouro não tem Nióbio suficiente. Aguarde uma reposição do governo.',
                );
            }

            $colony->decrement('fert_micro', $total);
            $this->tesouro->creditarFert($total, "venda_niobio:{$colony->id}");

            $colony->resources()
                ->where('resource_type', self::RECURSO)
                ->increment('amount', $quantidade);

            Ledger::create([
                'colony_id' => $colony->id,
                'type' => 'compra_niobio',
                'amount' => -$total,
                'resource_type' => null,
                'ref' => "niobio:{$quantidade}",
                'created_at' => now(),
            ]);

            Ledger::create([
                'colony_id' => $colony->id,
                'type' => 'compra_niobio',
                'amount' => $quantidade,
                'resource_type' => self::RECURSO,
                'ref' => "niobio:{$quantidade}",
                'created_at' => now(),
            ]);

            app(\App\Domain\Missoes\Progresso::class)->registrar($colony->id, 'niobio_comprado');

            return $quantidade;
        });
    }
}
