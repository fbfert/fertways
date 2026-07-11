<?php

namespace App\Http\Controllers\Admin;

use App\Console\Commands\TickColonies;
use App\Domain\Finance\DeclararIntervencao;
use App\Domain\Ministry\Apelacao;
use App\Domain\Ministry\DecidirCaso;
use App\Domain\Ministry\GerirConciliador;
use App\Domain\News\PublicarNoticia;
use App\Exceptions\DomainRuleException;
use App\Http\Controllers\Controller;
use App\Models\Colony;
use App\Models\News;
use App\Models\Report;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

/**
 * As ações de operador do painel. Cada uma chama a MESMA classe de domínio que o comando artisan
 * correspondente usa (não "shell out"), traduz erros de regra numa mensagem e volta ao dashboard.
 *
 * O que não vive no domínio (tick e realocação de founders — orquestração com guardas) é invocado
 * em processo por `Artisan::call`, que é o mesmo container, não um processo de shell.
 */
class AcoesController extends Controller
{
    // ── Ministério ───────────────────────────────────────────────────────────

    public function julgar(Request $request, Report $report, DecidirCaso $decidir): RedirectResponse
    {
        $procedente = $request->validate(['procedente' => ['required', 'boolean']])['procedente'];

        return $this->tentar(function () use ($decidir, $report, $procedente) {
            $decidir->pelaEquipe($report, (bool) $procedente);

            return 'Caso #'.$report->id.' julgado '.($procedente ? 'procedente' : 'improcedente').'.';
        });
    }

    public function apelacao(Request $request, Report $report, Apelacao $apelacao): RedirectResponse
    {
        $decisao = $request->validate(['decisao' => ['required', 'in:manter,reverter']])['decisao'];

        return $this->tentar(function () use ($apelacao, $report, $decisao) {
            $decisao === 'reverter' ? $apelacao->reverter($report) : $apelacao->manter($report);

            return 'Apelação do caso #'.$report->id.': '.($decisao === 'reverter' ? 'revertida' : 'mantida').'.';
        });
    }

    // ── Conciliadores ────────────────────────────────────────────────────────

    public function conciliadorNomear(Request $request, GerirConciliador $gerir): RedirectResponse
    {
        $nick = $request->validate(['nickname' => ['required', 'string']])['nickname'];
        $colono = User::where('nickname', $nick)->first();

        if (! $colono) {
            return $this->erro("Colono não encontrado: {$nick}");
        }

        return $this->tentar(fn () => $gerir->nomear($colono)
            ? "{$colono->nickname} é conciliador."
            : "{$colono->nickname} já era conciliador.");
    }

    public function conciliadorGerir(Request $request, User $user, GerirConciliador $gerir): RedirectResponse
    {
        $acao = $request->validate(['acao' => ['required', 'in:demitir,reintegrar,suspender']])['acao'];

        return $this->tentar(function () use ($gerir, $user, $acao) {
            match ($acao) {
                'demitir' => $gerir->demitir($user),
                'reintegrar' => $gerir->reintegrar($user),
                'suspender' => $gerir->suspender($user),
            };

            return "{$user->nickname}: {$acao}.";
        });
    }

    // ── Finanças ─────────────────────────────────────────────────────────────

    public function intervencao(Request $request, DeclararIntervencao $declarar): RedirectResponse
    {
        $dados = $request->validate([
            'resource_type' => ['required', 'string'],
            'teto' => ['nullable', 'numeric', 'min:0'],
            'piso' => ['nullable', 'numeric', 'min:0'],
            'motivo' => ['required', 'string', 'max:255'],
            'dias' => ['required', 'integer', 'min:1'],
        ]);

        return $this->tentar(function () use ($declarar, $dados) {
            $i = $declarar->declarar(
                $dados['resource_type'],
                $this->emMicro($dados['teto'] ?? null),
                $this->emMicro($dados['piso'] ?? null),
                $dados['motivo'],
                (int) $dados['dias'],
            );

            return "Intervenção #{$i->id} em {$i->resource_type} declarada.";
        });
    }

    public function intervencaoRevogar(Request $request, DeclararIntervencao $declarar): RedirectResponse
    {
        $recurso = $request->validate(['resource_type' => ['required', 'string']])['resource_type'];
        $n = $declarar->revogar($recurso);

        return $this->ok($n > 0 ? "Revogadas {$n} intervenção(ões) de {$recurso}." : "Nenhuma vigente em {$recurso}.");
    }

    // ── Notícias ─────────────────────────────────────────────────────────────

    public function noticiaPublicar(Request $request, PublicarNoticia $publicar): RedirectResponse
    {
        $dados = $request->validate([
            'titulo' => ['required', 'string', 'max:140'],
            'corpo' => ['required', 'string'],
            'autor' => ['nullable', 'string', 'max:60'],
        ]);

        return $this->tentar(function () use ($publicar, $dados) {
            $n = $publicar->publicar($dados['titulo'], $dados['corpo'], $dados['autor'] ?? null);

            return "Comunicado #{$n->id} publicado.";
        });
    }

    public function noticiaRemover(News $news, PublicarNoticia $publicar): RedirectResponse
    {
        $id = $news->id;
        $publicar->remover($id);

        return $this->ok("Notícia #{$id} removida.");
    }

    // ── Operação (orquestração, via Artisan em processo) ─────────────────────

    public function tick(): RedirectResponse
    {
        Artisan::call(TickColonies::class);

        return $this->ok('Tick disparado. O mundo avançou.');
    }

    public function realocar(): RedirectResponse
    {
        // A própria realocação aborta se algum veículo não estiver ocioso (guarda do comando).
        $codigo = Artisan::call('fertways:realocar-founders', ['--force' => true]);
        $saida = trim(Artisan::output());

        return $codigo === 0
            ? $this->ok('Realocação aplicada. '.$saida)
            : $this->erro('Realocação abortada. '.$saida);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /** Roda a ação, traduz erro de regra e volta ao dashboard. */
    private function tentar(callable $acao): RedirectResponse
    {
        try {
            return $this->ok($acao());
        } catch (DomainRuleException $e) {
            return $this->erro($e->getMessage());
        }
    }

    private function ok(string $msg): RedirectResponse
    {
        return redirect()->route('admin.dashboard')->with('ok', $msg);
    }

    private function erro(string $msg): RedirectResponse
    {
        return redirect()->route('admin.dashboard')->with('erro', $msg);
    }

    private function emMicro(mixed $fert): ?int
    {
        return ($fert === null || $fert === '') ? null : (int) round(((float) $fert) * Colony::MICRO_POR_FERT);
    }
}
