<?php

namespace App\Domain\Eventos;

use App\Domain\Telemetria\RegistrarEvento;
use App\Models\GameEvent;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Os eventos que se ativam sozinhos: os encadeados e os por condição (D-253).
 *
 * ## ⚠️ Só o ARMADO se ativa
 *
 * Rascunho continua não valendo nada e nunca se ativando — a regra do D-232 ("criar nunca ativa")
 * fica de pé. Armar é um segundo clique, de propósito, no painel ou no `artisan`: o operador diz
 * "este pode ir ao ar sozinho". Sem isso, um formulário salvo pela metade viraria evento no mundo
 * no dia em que a condição batesse.
 *
 * ## A duração é a que o operador escreveu
 *
 * Um armado guarda uma janela (`comeca_em`/`termina_em`) desde que foi criado; o que se usa dela é
 * a DURAÇÃO. Quando ele se ativa, a janela é recolocada a partir do instante da ativação — que, no
 * encadeado, é o fim do predecessor, e não o minuto em que o comando passou. Um tick atrasado não
 * encurta o elo seguinte.
 *
 * ## A corrente quebra no cancelamento
 *
 * O elo seguinte nasce quando o anterior TERMINA. Cancelar o anterior encerra o futuro — e o futuro
 * de uma corrente é o elo seguinte, que fica armado, parado, até o operador decidir.
 */
class AtivarEventos
{
    public function __construct(private CondicoesDoMundo $condicoes) {}

    /** @return list<string> os slugs ativados nesta passada */
    public function handle(): array
    {
        $ativados = [];
        $medidas = null;

        foreach (GameEvent::where('status', 'armado')->with('predecessor')->orderBy('id')->get() as $e) {
            $inicio = match (true) {
                $e->sucede_event_id !== null => $this->fimDoPredecessor($e),
                $e->gatilho === 'condicao' => ($this->condicoes->satisfeitas($e, $medidas ??= $this->condicoes->medir())
                    ? now() : null),
                default => null,
            };

            if ($inicio === null) {
                continue;
            }

            if ($this->ativar($e, $inicio)) {
                $ativados[] = $e->slug;
            }
        }

        return $ativados;
    }

    private function fimDoPredecessor(GameEvent $e): ?CarbonInterface
    {
        $p = $e->predecessor;

        // Predecessor apagado, cancelado ou ainda valendo: o elo espera (ou fica parado).
        if ($p === null || $p->status !== 'ativo' || $p->cancelado_em !== null || $p->termina_em->isFuture()) {
            return null;
        }

        return $p->termina_em;
    }

    private function ativar(GameEvent $e, CarbonInterface $inicio): bool
    {
        return DB::transaction(function () use ($e, $inicio) {
            $linha = GameEvent::whereKey($e->id)->lockForUpdate()->first();

            // Outro processo já ativou: o lock é a idempotência entre duas passadas simultâneas.
            if (! $linha || $linha->status !== 'armado') {
                return false;
            }

            $duracao = max(60, $linha->comeca_em->diffInSeconds($linha->termina_em));

            $linha->update([
                'status' => 'ativo',
                'comeca_em' => $inicio,
                'termina_em' => $inicio->copy()->addSeconds((int) $duracao),
            ]);

            /*
             * `evento_global` estava declarado na telemetria desde a A2.0 e nunca tinha tido emissor.
             * Um evento que vai ao ar SEM o operador apertar nada é exatamente o que precisa de
             * registro — sem ele, ninguém saberia por que o mundo mudou às 03h.
             */
            app(RegistrarEvento::class)->handle(
                'evento_global', null, null,
                [
                    'evento' => $linha->slug,
                    'como' => $linha->sucede_event_id !== null ? 'encadeado' : 'condicao',
                    'sucede' => $linha->predecessor?->slug,
                ],
                origem: 'sistema',
                adiar: true,
            );

            return true;
        });
    }
}
