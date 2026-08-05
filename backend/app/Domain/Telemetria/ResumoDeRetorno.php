<?php

namespace App\Domain\Telemetria;

use App\Models\Colony;
use App\Models\Ledger;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * "Desde sua última visita" (A2.0.3; janela definida no GDD ALPHA 2 §5.1).
 *
 * ## A janela é por RESUMO VISTO, não por sessão
 *
 * E isso não é preciosismo: **o jogo não tem conceito de sessão**. `users` não tinha marca de
 * último acesso e a tabela de sessões do framework é apagada no logout. Amarrar a janela a "sessão"
 * teria exigido inventar a sessão primeiro, e ela mediria a coisa errada de qualquer forma — quem
 * fecha a aba sem sair nunca encerra sessão nenhuma.
 *
 * O marcador é `users.resumo_visto_em`, e ele avança **quando o jogador fecha o resumo**, não
 * quando o abre. Reabrir não repete o que já foi visto e não pula o que não foi.
 *
 * ## Piso de uma hora
 *
 * Se passou menos de uma hora desde o último resumo visto, a tela não aparece. É o que impede que
 * quem recarrega a página, ou entra três vezes seguidas, leve um modal a cada visita.
 *
 * ## A primeira vez não mostra nada
 *
 * `resumo_visto_em` nulo quer dizer que nunca houve resumo. O §5.1 é explícito: não há "desde a
 * última visita" quando não houve visita anterior. Nesse caso o marcador é **plantado** e a tela
 * não aparece — senão o recém-chegado receberia um resumo do começo dos tempos, que para ele é a
 * fundação da própria colônia, contada como se fosse novidade.
 *
 * ## De onde vêm os números
 *
 * Do **ledger** e do **build_queue** — fontes que já existem e já guardam a verdade. Nenhum evento
 * de telemetria novo foi criado para isto, pelo mesmo motivo do D-163: duplicar uma verdade
 * existente cria duas respostas para a mesma pergunta. A conclusão de obra sobrevive em
 * `build_queue` com `status = 'done'` (ninguém apaga essas linhas), mesmo depois de o tick zerar o
 * `upgrade_finish_at` da construção.
 */
class ResumoDeRetorno
{
    /** O piso do §5.1. Menos que isto desde o último resumo, e a tela não aparece. */
    public const PISO_MINUTOS = 60;

    /** Quantos recursos aparecem na lista de produção. Um resumo que cabe em um minuto não é uma tabela. */
    private const QUANTOS_RECURSOS = 6;

    public function __construct(private DirecaoDoLedger $direcao) {}

    /**
     * @return array{mostrar: bool, motivo: string, desde: ?string, ate: string, ...}
     */
    public function montar(User $user, bool $reabrindo = false): array
    {
        $agora = now();
        $colonia = $user->colony;

        if (! $colonia) {
            // Sem colônia não há o que resumir — e é o estado de quem ainda não fundou.
            return $this->nada('sem_colonia', $agora);
        }

        /*
         * ⚠️ Reabertura explícita: mostra DE NOVO a janela anterior, e não uma janela nova.
         *
         * O botão "Ver o que aconteceu desde sua última visita" não abria nada, e a causa estava
         * aqui, não na tela: `resumo_visto_em` avança ao FECHAR, então um minuto depois de fechar a
         * "última visita" era um minuto atrás — janela vazia — e o piso de uma hora ainda barrava.
         * O botão pedia uma janela que ele mesmo já tinha consumido.
         *
         * O piso do §5.1 governa o resumo **automático**, que é o que ele existe para conter: um
         * popup que se convida sozinho a cada carga de página. Um clique explícito não é isso, e
         * negá-lo era transformar uma proteção contra insistência em proibição de reler.
         *
         * Nada é movido aqui: reabrir não consome janela nenhuma.
         */
        if ($reabrindo) {
            $desde = $user->resumo_anterior_em ?? $user->resumo_visto_em;

            if ($desde === null) {
                return $this->nada('sem_janela_anterior', $agora);
            }

            return $this->janela($colonia, $desde, $agora);
        }

        if ($user->resumo_visto_em === null) {
            /*
             * Primeira vez: planta o marcador e não mostra nada. Ver o docblock da classe — mostrar
             * aqui significaria apresentar a fundação da colônia como "novidade desde sua última
             * visita", que é falso e confuso justamente para quem está chegando.
             */
            $user->forceFill(['resumo_visto_em' => $agora])->save();

            return $this->nada('primeira_vez', $agora);
        }

        $desde = $user->resumo_visto_em;

        /*
         * `copy()` antes de somar, e comparação explícita em vez de `diffInMinutes`. Dois motivos,
         * os dois já causaram bug em algum lugar: o Carbon é **mutável**, e somar direto em
         * `$desde` corromperia o atributo do modelo em memória; e o `diffIn*` do Carbon 3 passou a
         * devolver float com sinal, o que torna a comparação sensível à ordem dos operandos.
         */
        if ($agora->lessThan($desde->copy()->addMinutes(self::PISO_MINUTOS))) {
            return $this->nada('piso_de_uma_hora', $agora, $desde);
        }

        return $this->janela($colonia, $desde, $agora);
    }

    /**
     * Monta o resumo de um intervalo. Extraído para que a reabertura explícita e o resumo automático
     * contem **a mesma história** sobre a mesma janela — duas montagens seriam duas verdades.
     */
    private function janela(Colony $colonia, CarbonInterface $desde, CarbonInterface $agora): array
    {
        $producao = $this->producao($colonia, $desde, $agora);
        $fert = $this->fert($colonia, $desde, $agora);
        $obras = $this->obras($colonia, $desde, $agora);

        return [
            'mostrar' => true,
            'motivo' => 'ok',
            'desde' => $desde->toIso8601String(),
            'ate' => $agora->toIso8601String(),
            'producao' => $producao,
            'fert_ganho_micro' => $fert['ganho'],
            'fert_gasto_micro' => $fert['gasto'],
            'obras_concluidas' => $obras,
            /*
             * "Nada aconteceu" é um resultado legítimo e precisa ser dizível: quem passou dois dias
             * fora com a colônia sem energia PRECISA ver que não produziu nada. Um resumo que só
             * aparece quando há boa notícia esconde exatamente o que mais importa.
             */
            'vazio' => $producao === [] && $obras === [] && $fert['ganho'] === 0 && $fert['gasto'] === 0,
        ];
    }

    /**
     * Move o marcador. Chamado quando o jogador FECHA o resumo, não quando o abre.
     *
     * A janela que acabou de ser mostrada é guardada em `resumo_anterior_em` — é ela que o botão
     * "Ver o que aconteceu desde sua última visita" reabre. Sem isto, fechar o resumo apagava para
     * sempre o que ele dizia, e o botão abria uma janela de zero minuto.
     */
    public function marcarVisto(User $user): void
    {
        $user->forceFill([
            'resumo_anterior_em' => $user->resumo_visto_em,
            'resumo_visto_em' => now(),
        ])->save();

        app(RegistrarEvento::class)->handle('resumo_visto', $user);
    }

    private function nada(string $motivo, Carbon $agora, ?Carbon $desde = null): array
    {
        return [
            'mostrar' => false,
            'motivo' => $motivo,
            'desde' => $desde?->toIso8601String(),
            'ate' => $agora->toIso8601String(),
            'producao' => [],
            'fert_ganho_micro' => 0,
            'fert_gasto_micro' => 0,
            'obras_concluidas' => [],
            'vazio' => true,
        ];
    }

    /** O que a colônia produziu na janela, por recurso, do maior para o menor. */
    private function producao(Colony $colonia, Carbon $desde, Carbon $ate): array
    {
        return Ledger::query()
            ->where('colony_id', $colonia->id)
            ->where('type', 'producao')
            ->whereBetween('created_at', [$desde, $ate])
            ->whereNotNull('resource_type')
            ->select('resource_type', DB::raw('SUM(amount) AS total'))
            ->groupBy('resource_type')
            ->orderByDesc('total')
            ->limit(self::QUANTOS_RECURSOS)
            ->get()
            ->map(fn ($l) => ['recurso' => $l->resource_type, 'quantidade' => (int) $l->total])
            ->all();
    }

    /**
     * Fert$ que entrou e saiu na janela.
     *
     * A direção vem do **sinal** do `amount`: o ledger escreve saída como negativo. `DirecaoDoLedger`
     * só decide o que fica FORA da conta — escrow, transferência, estorno, ajuste do operador —, e é
     * a mesma classe que o agregador diário usa, para não haver duas respostas sobre o mesmo fato.
     */
    private function fert(Colony $colonia, Carbon $desde, Carbon $ate): array
    {
        $tipos = Ledger::query()
            ->where('colony_id', $colonia->id)
            ->whereNull('resource_type') // nulo = Fert$ em micro, convenção do ledger
            ->whereBetween('created_at', [$desde, $ate])
            ->distinct()->pluck('type');

        $fora = $tipos->reject(fn ($t) => $this->direcao->contaNoFluxo($t))->all();

        $linha = Ledger::query()
            ->where('colony_id', $colonia->id)
            ->whereNull('resource_type')
            ->whereBetween('created_at', [$desde, $ate])
            ->when($fora !== [], fn ($q) => $q->whereNotIn('type', $fora))
            ->selectRaw('SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END) AS ganho')
            ->selectRaw('SUM(CASE WHEN amount < 0 THEN -amount ELSE 0 END) AS gasto')
            ->first();

        return ['ganho' => (int) ($linha->ganho ?? 0), 'gasto' => (int) ($linha->gasto ?? 0)];
    }

    /**
     * As obras que terminaram na janela.
     *
     * Sai do `build_queue`, e não da tabela `buildings`: o tick zera `upgrade_finish_at` ao
     * concluir, então a construção não guarda **quando** subiu de nível. A fila guarda — a linha
     * fica com `status = 'done'` e o `finishes_at` intacto, e ninguém a apaga.
     */
    private function obras(Colony $colonia, Carbon $desde, Carbon $ate): array
    {
        return DB::table('build_queue')
            ->join('buildings', 'buildings.id', '=', 'build_queue.building_id')
            ->where('build_queue.colony_id', $colonia->id)
            ->where('build_queue.status', 'done')
            ->whereBetween('build_queue.finishes_at', [$desde, $ate])
            ->orderBy('build_queue.finishes_at')
            ->select('buildings.type', 'build_queue.target_level', 'build_queue.finishes_at')
            ->get()
            ->map(fn ($o) => [
                'tipo' => $o->type,
                'nivel' => (int) $o->target_level,
                'em' => Carbon::parse($o->finishes_at)->toIso8601String(),
            ])
            ->all();
    }
}
