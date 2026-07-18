<?php

namespace App\Domain\Missoes;

use App\Models\Colony;
use App\Models\Federation;
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

    public const FEDERACAO_POR_SEMANA = 2;

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

    /**
     * As missões "Federação" da semana (§06, D-116) — 2 cooperativas, uma linha POR COLÔNIA-MEMBRO
     * (não uma linha compartilhada: `mission_assignments.colony_id` continua `NOT NULL`). Todas as
     * linhas do mesmo sorteio compartilham `federation_id` — são "irmãs", e `Progresso::registrar()`
     * espelha o progresso entre elas.
     *
     * Lazy, como `garantir()`: chamada de `MissoesController::index()` quando a colônia que pediu
     * tem federação. Quem chega primeiro na semana sorteia para TODOS os membros atuais; quem
     * chega depois (entrou na federação no meio da semana) ganha a própria linha, com o progresso
     * JÁ ANDADO — não começa do zero por ter entrado depois. Se a missão da semana já terminou
     * (concluída ou expirada) antes de a colônia pedir, ela simplesmente perde esta semana — não
     * há linha "concluída sem pagamento" para trás.
     */
    public function garantirFederacao(Federation $federation, Colony $quemPediu): void
    {
        $semana = Janela::semanaAtual();

        DB::transaction(function () use ($federation, $quemPediu, $semana) {
            // Trava a federação: dois membros pedindo ao mesmo tempo não sorteiam duas mãos.
            Federation::whereKey($federation->id)->lockForUpdate()->first();

            $jaTemLinha = MissionAssignment::where('federation_id', $federation->id)
                ->where('colony_id', $quemPediu->id)
                ->where('categoria', 'federacao')
                ->where('created_at', '>=', $semana)
                ->exists();

            if ($jaTemLinha) {
                return;
            }

            $irmas = MissionAssignment::where('federation_id', $federation->id)
                ->where('categoria', 'federacao')
                ->where('created_at', '>=', $semana)
                ->get()
                ->groupBy('template_id');

            if ($irmas->isNotEmpty()) {
                $agora = now();

                foreach ($irmas as $templateId => $linhas) {
                    $modelo = $linhas->first();

                    // Já foi decidido (concluída ou expirada) antes de eu chegar: perdi esta.
                    if ($modelo->status !== 'ativa') {
                        continue;
                    }

                    MissionAssignment::create([
                        'colony_id' => $quemPediu->id,
                        'federation_id' => $federation->id,
                        'template_id' => $templateId,
                        'categoria' => 'federacao',
                        'acao' => $modelo->acao,
                        'progresso' => $modelo->progresso,
                        'meta' => $modelo->meta,
                        'status' => 'ativa',
                        'expires_at' => $modelo->expires_at,
                        'created_at' => $agora,
                    ]);
                }

                return;
            }

            // Ninguém da federação pediu ainda esta semana: sorteia e cria uma linha por membro
            // atual, todas irmãs do mesmo objetivo.
            $sorteados = MissionTemplate::where('categoria', 'federacao')->where('ativa', true)
                ->inRandomOrder()
                ->limit(self::FEDERACAO_POR_SEMANA)
                ->get();

            if ($sorteados->isEmpty()) {
                return;
            }

            $membros = Colony::where('federation_id', $federation->id)->get();
            $agora = now();
            $expira = Janela::fimDaSemana();

            $linhas = [];
            foreach ($membros as $membro) {
                foreach ($sorteados as $t) {
                    $linhas[] = [
                        'colony_id' => $membro->id,
                        'federation_id' => $federation->id,
                        'template_id' => $t->id,
                        'categoria' => 'federacao',
                        'acao' => $t->acao,
                        'progresso' => 0,
                        'meta' => $t->meta,
                        'status' => 'ativa',
                        'expires_at' => $expira,
                        'created_at' => $agora,
                    ];
                }
            }

            MissionAssignment::insert($linhas);
        });
    }
}
