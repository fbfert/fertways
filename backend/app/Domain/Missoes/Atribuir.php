<?php

namespace App\Domain\Missoes;

use App\Models\Colony;
use App\Models\MissionAssignment;
use App\Models\MissionTemplate;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Entrega as missões à mão da colônia (§06; D-78) — LAZY, no primeiro pedido da janela.
 *
 * Nada de fila no tick: quem nunca abre a tela de missões não ganha linha no banco, e o sorteio
 * das 3 diárias acontece quando o colono chega — do pool de 30+ (`mission_templates` ativas),
 * sem repetir template na mesma janela (a rejeição repõe com um que ainda não passou pelo dia).
 */
class Atribuir
{
    public const DIARIAS_POR_DIA = 3;

    /** As 5 da tutoria, na fundação — "5 missões, dias 1 a 3" (§06): expiram em 3 dias. */
    public function tutoria(Colony $colony): void
    {
        $agora = now();

        $linhas = MissionTemplate::where('categoria', 'tutoria')->where('ativa', true)
            ->orderBy('id')
            ->get()
            ->map(fn (MissionTemplate $t) => [
                'colony_id' => $colony->id,
                'template_id' => $t->id,
                'categoria' => 'tutoria',
                'acao' => $t->acao,
                'progresso' => 0,
                'meta' => $t->meta,
                'status' => 'ativa',
                'expires_at' => $agora->copy()->addDays(3),
                'created_at' => $agora,
            ])
            ->all();

        // Banco sem o catálogo (testes que não semeiam missões): nada a entregar, nada a quebrar.
        if ($linhas !== []) {
            MissionAssignment::insert($linhas);
        }
    }

    /** Garante as missões da janela corrente e devolve todas as visíveis. */
    public function garantir(Colony $colony): Collection
    {
        $dia = Janela::diaAtual();
        $semana = Janela::semanaAtual();

        DB::transaction(function () use ($colony, $dia, $semana) {
            // Trava a colônia: dois pedidos simultâneos não podem sortear duas mãos diárias.
            Colony::whereKey($colony->id)->lockForUpdate()->first();

            $diariasDoDia = MissionAssignment::where('colony_id', $colony->id)
                ->where('categoria', 'diaria')
                ->where('created_at', '>=', $dia)
                ->count();

            if ($diariasDoDia === 0) {
                $this->sortear($colony, 'diaria', self::DIARIAS_POR_DIA, Janela::proximoDia());
            }

            $semanalDaSemana = MissionAssignment::where('colony_id', $colony->id)
                ->where('categoria', 'semanal')
                ->where('created_at', '>=', $semana)
                ->exists();

            if (! $semanalDaSemana && now()->lte(Janela::fimDaSemana())) {
                $this->sortear($colony, 'semanal', 1, Janela::fimDaSemana());
            }
        });

        return MissionAssignment::with('template')
            ->where('colony_id', $colony->id)
            ->where(fn ($q) => $q
                // A tutoria aparece enquanto viver (3 dias) ou até concluída.
                ->where('categoria', 'tutoria')
                ->orWhere(fn ($d) => $d->where('categoria', 'diaria')->where('created_at', '>=', $dia))
                ->orWhere(fn ($w) => $w->where('categoria', 'semanal')->where('created_at', '>=', $semana)))
            ->orderBy('id')
            ->get();
    }

    /** Sorteia do pool, sem repetir template que já passou pela colônia nesta janela. */
    public function sortear(Colony $colony, string $categoria, int $quantas, \Carbon\CarbonInterface $expira): int
    {
        $inicioDaJanela = $categoria === 'diaria' ? Janela::diaAtual() : Janela::semanaAtual();

        $jaVistas = MissionAssignment::where('colony_id', $colony->id)
            ->where('categoria', $categoria)
            ->where('created_at', '>=', $inicioDaJanela)
            ->pluck('template_id');

        $sorteadas = MissionTemplate::where('categoria', $categoria)->where('ativa', true)
            ->whereNotIn('id', $jaVistas)
            ->inRandomOrder()
            ->limit($quantas)
            ->get();

        $agora = now();

        foreach ($sorteadas as $t) {
            MissionAssignment::create([
                'colony_id' => $colony->id,
                'template_id' => $t->id,
                'categoria' => $categoria,
                'acao' => $t->acao,
                'progresso' => 0,
                'meta' => $t->meta,
                'status' => 'ativa',
                'expires_at' => $expira,
                'created_at' => $agora,
            ]);
        }

        return $sorteadas->count();
    }
}
