<?php

namespace App\Domain\Missoes;

use App\Models\Colony;
use App\Models\Federation;
use App\Models\GameEvent;
use App\Models\MissionAssignment;
use App\Models\MissionTemplate;
use Carbon\CarbonInterface;
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

    /**
     * A tutoria, na fundação (A2.1).
     *
     * **Era uma lista plana de 5, entregues de uma vez e expirando em 3 dias.** Virou uma sequência
     * encadeada que não expira — o mesmo mecanismo da narrativa (D-140), agora generalizado em
     * `garantirEncadeada()`.
     *
     * Duas razões para não expirar mais:
     *
     * - a fase obrigatória não pode expirar por definição: uma etapa que o colono é obrigado a
     *   cumprir e que some sozinha em 3 dias é uma contradição;
     * - e expirar o meio de uma sequência deixaria o colono ENCALHADO — o degrau seguinte só chega
     *   quando o anterior conclui, então um degrau expirado tranca a escada inteira.
     *
     * Entrega só o primeiro degrau aqui; os demais chegam por `garantirEncadeada()`, chamada a cada
     * pedido de `MissoesController::index()`.
     */
    public function tutoria(Colony $colony): void
    {
        $this->garantirEncadeada($colony, 'tutoria');
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
    public function sortear(Colony $colony, string $categoria, int $quantas, CarbonInterface $expira): int
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

    /**
     * As missões narrativas (D-140) — lazy, como as demais: chamada por `MissoesController::index()`
     * a cada pedido. Sem ciclo (não sorteia, não expira, `expires_at` fica nulo): cada capítulo
     * (`categoria = narrativa`) chega à mão UMA VEZ SÓ, na ordem do catálogo, e só depois de o
     * capítulo anterior (`requer_template_id`) estar `concluida`. Um capítulo já entregue nunca é
     * entregue de novo — o `where` por `template_id` já visto cobre isso, o mesmo padrão de
     * `sortear()` (não repetir template na mesma janela), só que aqui a "janela" é a vida inteira
     * da colônia.
     */
    /** Compatibilidade: a narrativa é só um caso da sequência encadeada. */
    /**
     * As missões dos eventos que valem agora (A2.8 §12.2; D-250).
     *
     * Cada evento vigente que lista moldes em `game_events.missoes` entrega cada um deles, uma vez,
     * a esta colônia — com prazo no fim do evento. A categoria é `eventuais`, que existe desde o D-1xx
     * para *"evento, sazonal, sem sorteio automático"*: o sorteio diário e o semanal nunca a olham.
     *
     * ## Idempotente sem coordenação
     *
     * Três portas chamam isto (a tela, o comando diário e o de cinco em cinco minutos). O
     * `insertOrIgnore` contra o índice único `(colônia, evento, molde)` é o que deixa as três
     * conviverem: quem chegar depois colide e não duplica.
     *
     * ⚠️ Só molde `eventuais` e ativo. Um evento que listasse uma diária faria a mesma missão
     * chegar duas vezes, por dois caminhos, com dois prazos.
     *
     * @return int quantas missões novas nasceram
     */
    public function garantirEventos(Colony $colony): int
    {
        $agora = now();

        $eventos = GameEvent::where('status', 'ativo')
            ->whereNotNull('missoes')
            ->where('comeca_em', '<=', $agora)
            ->where('termina_em', '>', $agora)
            ->whereNull('cancelado_em')
            ->alcanca($colony)
            ->get();

        $novas = 0;

        foreach ($eventos as $evento) {
            $moldes = MissionTemplate::whereIn('id', $evento->missoes ?? [])
                ->where('categoria', 'eventuais')
                ->where('ativa', true)
                ->get();

            foreach ($moldes as $t) {
                $novas += DB::table('mission_assignments')->insertOrIgnore([
                    'colony_id' => $colony->id,
                    'game_event_id' => $evento->id,
                    'template_id' => $t->id,
                    'categoria' => 'eventuais',
                    'acao' => $t->acao,
                    'progresso' => 0,
                    'meta' => $t->meta,
                    'status' => 'ativa',
                    'expires_at' => $evento->termina_em,
                    'created_at' => $agora,
                ]);
            }
        }

        return $novas;
    }

    /**
     * O evento foi cancelado: as missões dele ainda abertas vencem AGORA (D-250).
     *
     * O que já foi concluído fica concluído — a recompensa saiu, e o ledger é append-only. É a
     * mesma regra do motor: cancelar encerra o futuro e preserva o passado.
     */
    public function encerrarDoEvento(GameEvent $evento): int
    {
        return MissionAssignment::where('game_event_id', $evento->id)
            ->where('status', 'ativa')
            ->update(['expires_at' => $evento->cancelado_em ?? now()]);
    }

    public function garantirNarrativa(Colony $colony): void
    {
        $this->garantirEncadeada($colony, 'narrativa');
    }

    /**
     * Entrega o próximo degrau de uma sequência encadeada — narrativa (D-140) ou tutoria (A2.1).
     *
     * Cada template chega à colônia UMA VEZ SÓ, na ordem do catálogo, e só depois de o anterior
     * (`requer_template_id`) estar `concluida`. Sem ciclo, sem sorteio e sem expiração: aqui a
     * "janela" é a vida inteira da colônia.
     */
    public function garantirEncadeada(Colony $colony, string $categoria): void
    {
        DB::transaction(function () use ($colony, $categoria) {
            Colony::whereKey($colony->id)->lockForUpdate()->first();

            $templates = MissionTemplate::where('categoria', $categoria)->where('ativa', true)
                ->orderBy('id')
                ->get();

            if ($templates->isEmpty()) {
                return;
            }

            $existentes = MissionAssignment::where('colony_id', $colony->id)
                ->where('categoria', $categoria)
                ->get()
                ->keyBy('template_id');

            $agora = now();

            foreach ($templates as $t) {
                if ($existentes->has($t->id)) {
                    continue;
                }

                $liberado = $t->requer_template_id === null
                    || $existentes->get($t->requer_template_id)?->status === 'concluida';

                if (! $liberado) {
                    continue;
                }

                MissionAssignment::create([
                    'colony_id' => $colony->id,
                    'template_id' => $t->id,
                    'categoria' => $categoria,
                    'acao' => $t->acao,
                    'progresso' => 0,
                    'meta' => $t->meta,
                    'status' => 'ativa',
                    'expires_at' => null,
                    'created_at' => $agora,
                ]);
            }
        });
    }
}
