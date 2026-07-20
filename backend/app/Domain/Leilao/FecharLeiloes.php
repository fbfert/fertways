<?php

namespace App\Domain\Leilao;

use App\Domain\Marco\ConcederXp;
use App\Domain\Missoes\Progresso;
use App\Domain\Trade\AcordoSpecs;
use App\Domain\Treasury\Tesouro;
use App\Models\Auction;
use App\Models\Colony;
use App\Models\Ledger;
use App\Models\ResourceType;
use Illuminate\Support\Facades\DB;

/**
 * Fecha os leilões vencidos (D-129). Chamado pelo tick, como `ExpirarAcordos` fecha o Acordo de
 * Troca — o mesmo "prazo publicado que se resolve sozinho", só que aqui não há calote possível: o
 * lance já está em escrow desde que foi dado (`DarLance`), então o fechamento é só liquidação.
 *
 * Sem lance nenhum: devolve o lote ao vendedor, sem tributo — nada foi vendido.
 * Com lance: transfere o lote ao arrematante, credita o vendedor líquido de tributo (mesma
 * `resource_types.tax_bps` do Mercado Central) e concede XP/missão pela mesma trilha
 * `mercado_executado` — um leilão fechado é, para o Marco, mais um negócio fechado no Mercado; o
 * GDD não publica um ato "leilão vencido" para inventar uma trilha nova só para ele.
 */
class FecharLeiloes
{
    public function __construct(private Tesouro $tesouro) {}

    /** @return array{arrematados: int, sem_lance: int} */
    public function handle(): array
    {
        $vencidos = Auction::where('status', 'aberto')
            ->where('deadline_at', '<=', now())
            ->orderBy('id')
            ->get();

        $arrematados = 0;
        $semLance = 0;

        foreach ($vencidos as $leilao) {
            $fechado = DB::transaction(fn () => $this->fechar($leilao->id));

            if ($fechado === 'arrematado') {
                $arrematados++;
            } elseif ($fechado === 'sem_lance') {
                $semLance++;
            }
        }

        return ['arrematados' => $arrematados, 'sem_lance' => $semLance];
    }

    private function fechar(int $leilaoId): ?string
    {
        $l = Auction::whereKey($leilaoId)->lockForUpdate()->first();

        if (! $l || ! $l->aberto()) {
            return null;
        }

        if ($l->lance_colony_id === null) {
            $this->creditarLote($l, $l->colony_id);
            $this->lancar($l->colony_id, 'estorno', $l->qty, $l->resource_type, "leilao:{$l->id}:sem_lance");

            $l->forceFill(['status' => 'sem_lance'])->save();

            return 'sem_lance';
        }

        $valor = $l->lance_atual_micro;
        // Item da Endurance (D-135, Fase 2): sem `tax_bps` nenhum — `EnduranceItem` não tem esse
        // campo (o preço já é arbitragem do admin, não vale duplicar arbitragem com uma alíquota
        // também inventada). Tributo zero, registrado como fato — não omitido.
        $bps = $l->ehItem() ? 0 : (int) ResourceType::find($l->resource_type)->tax_bps;
        $taxa = intdiv($valor * $bps, 10_000);
        $liquido = $valor - $taxa;
        $ref = "leilao:{$l->id}:fechamento";

        $inserido = DB::table('tax_events')->insertOrIgnore([
            'economic_event_key' => $ref,
            'kind' => 'leilao_venda',
            'colony_id' => $l->colony_id,
            // Como em `mercado_venda`: a base é o valor em micro-Fert$, e `resource_type` só
            // registra qual recurso gerou o fato tributável — null para item (a FK exige um código
            // de `resource_types`, que um item da Endurance não é).
            'resource_type' => $l->resource_type,
            'base_amount' => $valor,
            'tax_bps' => $bps,
            'tax_amount' => $taxa,
            'created_at' => now(),
        ]);

        if ($inserido === 0) {
            // O tick não roda duas vezes o mesmo leilão (a trava e o status já impedem), mas a
            // chave única do `tax_events` é a mesma rede de segurança que o Mercado Central usa.
            return null;
        }

        DB::table('colonies')->where('id', $l->colony_id)->increment('fert_micro', $liquido);
        $this->lancar($l->colony_id, 'venda_leilao', $liquido, null, $ref);

        if ($taxa > 0) {
            $this->lancar($l->colony_id, 'tributo', -$taxa, null, $ref);
            $this->tesouro->creditarFert($taxa, "tributo_leilao:{$ref}");
        }

        $this->creditarLote($l, $l->lance_colony_id);
        $this->lancar($l->lance_colony_id, 'compra_leilao', $l->qty, $l->resource_type, $ref);

        // Mesmo piso anti-farm do D-43/D-117: um lote a centavos não deve render XP de graça.
        if ($valor >= AcordoSpecs::PISO_REPUTACAO_MICRO) {
            $xp = app(ConcederXp::class);
            $missoes = app(Progresso::class);

            $xp->handle($l->colony_id, 'mercado_executado', $ref);
            $missoes->registrar($l->colony_id, 'mercado_executado');

            $xp->handle($l->lance_colony_id, 'mercado_executado', $ref);
            $missoes->registrar($l->lance_colony_id, 'mercado_executado');
        }

        $l->forceFill(['status' => 'arrematado'])->save();

        return 'arrematado';
    }

    /**
     * Credita o lote a uma colônia — vendedor (sem lance) ou arrematante (fechado com lance). Os
     * dois casos usam o MESMO `insertOrIgnore` + `increment`: para item da Endurance (D-135, Fase
     * 2) o vendedor já tem a linha (ela nasceu no `decrement` de `ListarLeilao::handleItem`), mas o
     * arrematante pode nunca ter possuído aquele item — `insertOrIgnore` cobre os dois sem
     * precisar de dois caminhos.
     */
    private function creditarLote(Auction $l, int $colonyId): void
    {
        if ($l->ehItem()) {
            DB::table('colony_endurance_items')->insertOrIgnore([
                'colony_id' => $colonyId, 'endurance_item_id' => $l->endurance_item_id,
                'quantidade' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('colony_endurance_items')
                ->where('colony_id', $colonyId)
                ->where('endurance_item_id', $l->endurance_item_id)
                ->increment('quantidade', $l->qty);

            return;
        }

        DB::table('market_accounts')->insertOrIgnore([
            'colony_id' => $colonyId, 'resource_type' => $l->resource_type, 'amount' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('market_accounts')
            ->where('colony_id', $colonyId)
            ->where('resource_type', $l->resource_type)
            ->increment('amount', $l->qty);
    }

    private function lancar(int $colonyId, string $tipo, int $valor, ?string $recurso, string $ref): void
    {
        Ledger::create([
            'colony_id' => $colonyId,
            'type' => $tipo,
            'amount' => $valor,
            'resource_type' => $recurso,
            'ref' => $ref,
            'created_at' => now(),
        ]);
    }
}
