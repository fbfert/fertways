<?php

namespace App\Domain\Missoes;

use App\Domain\Chat\ContaSistema;
use App\Domain\Chat\EnviarMensagem;
use App\Domain\Marco\ConcederXp;
use App\Models\Colony;
use App\Models\Ledger;
use App\Models\MissionAssignment;
use App\Models\ResourceType;
use Illuminate\Support\Facades\DB;

/**
 * O progresso das missões (§06; D-78) — o ouvido que escuta os atos do jogo.
 *
 * Os MESMOS ganchos do XP (D-75) chamam aqui: obra concluída, despacho, mercado, acordo, zona,
 * combate… A pergunta "há missão ativa desta ação nesta colônia?" quase sempre responde não, e o
 * índice (colony_id, status, acao) a torna barata o bastante para viver no caminho quente.
 *
 * **Concluir PAGA na hora** — sem botão de resgate: a recompensa de missão é emissão publicada
 * (§06 lista "recompensas de missão" entre as entradas de Fert$), o ledger registra cada perna, e
 * o XP entra pelo ledger do Marco com o valor DO TEMPLATE (as missões pagam o que o catálogo diz,
 * não o que a tabela de atos do D-75 diz).
 *
 * E avisa: a conta de sistema "Missões" manda pelo rádio o que acabou de ser pago (pedido do
 * usuário) — mesmo desenho do aviso do Pátio (D-91), só que disparado uma vez, na conclusão.
 */
class Progresso
{
    public function __construct(
        private readonly ConcederXp $xp,
        private readonly EnviarMensagem $chat,
    ) {}

    public function registrar(int $colonyId, string $acao, int $vezes = 1): void
    {
        $ids = MissionAssignment::ativa()
            ->where('colony_id', $colonyId)
            ->where('acao', $acao)
            ->pluck('id');

        foreach ($ids as $id) {
            DB::transaction(function () use ($id, $vezes) {
                $missao = MissionAssignment::whereKey($id)->lockForUpdate()->first();

                if (! $missao || $missao->status !== 'ativa') {
                    return;
                }

                $novoProgresso = min($missao->meta, $missao->progresso + max(1, $vezes));
                $this->avancar($missao, $novoProgresso);

                // Missão de federação (D-116): o feito é UM só — todo o grupo vê o mesmo placar,
                // não soma por irmã. Quem cruza a meta primeiro leva as irmãs junto.
                if ($missao->federation_id === null) {
                    return;
                }

                $irmas = MissionAssignment::where('federation_id', $missao->federation_id)
                    ->where('template_id', $missao->template_id)
                    ->where('id', '!=', $missao->id)
                    ->where('status', 'ativa')
                    ->lockForUpdate()
                    ->get();

                foreach ($irmas as $irma) {
                    $this->avancar($irma, $novoProgresso);
                }
            });
        }
    }

    private function avancar(MissionAssignment $missao, int $novoProgresso): void
    {
        $missao->progresso = $novoProgresso;

        if ($missao->progresso >= $missao->meta) {
            $missao->status = 'concluida';
            $missao->concluded_at = now();
            $missao->save();

            $this->pagar($missao);

            return;
        }

        $missao->save();
    }

    private function pagar(MissionAssignment $missao): void
    {
        $t = $missao->template;
        $ref = "missao:{$missao->id}:{$t->chave}";

        if ((int) $t->recompensa_fert_micro > 0) {
            Colony::whereKey($missao->colony_id)->increment('fert_micro', (int) $t->recompensa_fert_micro);

            Ledger::create([
                'colony_id' => $missao->colony_id,
                'type' => 'recompensa_missao',
                'amount' => (int) $t->recompensa_fert_micro,
                'resource_type' => null,
                'ref' => $ref,
                'created_at' => now(),
            ]);
        }

        foreach ($t->recompensa_recursos ?? [] as $recurso => $qtd) {
            Colony::find($missao->colony_id)->resources()
                ->where('resource_type', $recurso)->increment('amount', (int) $qtd);

            Ledger::create([
                'colony_id' => $missao->colony_id,
                'type' => 'recompensa_missao',
                'amount' => (int) $qtd,
                'resource_type' => $recurso,
                'ref' => $ref,
                'created_at' => now(),
            ]);
        }

        if ((int) $t->recompensa_xp > 0) {
            $this->xp->direto($missao->colony_id, 'missao_concluida', (int) $t->recompensa_xp, $ref);
        }

        $this->pagarAFederacao($missao, $t);

        $this->avisar($missao);
    }

    /**
     * O prêmio do objetivo federativo, que vai ao FUNDO e não a quem cumpriu (A2.5, item 4).
     *
     * ## Por que existe
     *
     * As missões `categoria = 'federacao'` eram missões pessoais com placar compartilhado: doze
     * membros cumpriam um objetivo comum e **nada era produzido para a federação**. O que distingue
     * um objetivo federativo é justamente isto — o produto do esforço coletivo é coletivo.
     *
     * O XP pessoal continua sendo pago acima: quem trabalhou merece o reconhecimento. O que muda é
     * de quem é o produto.
     *
     * ## ⚠️ Uma vez por federação, não uma por membro
     *
     * Cada membro tem a sua linha de missão, e todas concluem. Sem guarda, uma federação de doze
     * receberia doze prêmios pelo mesmo objetivo.
     *
     * A guarda é **estrutural**, no molde que a casa usa para o tributo: a `ref` carrega a
     * federação, o template e a JANELA da missão — nunca o `id` da linha pessoal, que é diferente
     * para cada membro e faria a chave deixar de colidir. O índice único
     * `federation_ledger(federation_id, ref)` recusa a segunda, e `insertOrIgnore` devolvendo zero é
     * o sinal de que alguém já pagou.
     */
    private function pagarAFederacao(MissionAssignment $missao, $t): void
    {
        $premio = $t->recompensa_federacao ?? [];

        /*
         * A federação vem da LINHA DA MISSÃO, e não da colônia agora.
         *
         * `mission_assignments.federation_id` é a federação de quando a missão foi atribuída. Quem
         * saiu no meio da semana não deve pagar prêmio a uma federação de que já não faz parte, e
         * quem entrou depois tem linha própria (o `Atribuir` cuida disso). Ler a colônia neste
         * instante trocaria o dono do prêmio conforme a hora da conclusão.
         */
        $federacaoId = $missao->federation_id;

        if ($premio === [] || ! $federacaoId) {
            return;
        }

        /*
         * A janela entra na chave — via `expires_at`, que é o fim dela — porque o objetivo é
         * SEMANAL: sem isso, a federação receberia o prêmio uma vez na vida em vez de uma por
         * semana.
         */
        $ref = "objetivo:{$federacaoId}:{$t->chave}:{$missao->expires_at?->toDateString()}";

        foreach ($premio as $recurso => $qtd) {
            /*
             * ⚠️ A GUARDA É A PRÓPRIA LINHA DO PRÊMIO, e não uma linha-sentinela.
             *
             * A primeira versão gravava uma sentinela com `resource_type` nulo — e a coluna é
             * `NOT NULL`. O `insertOrIgnore` engole QUALQUER violação, inclusive essa: devolvia
             * zero, a função saía cedo, e o prêmio nunca era pago. A funcionalidade inteira não
             * fazia nada, em silêncio, e só um teste que conferia o saldo pegou.
             *
             * Com dado de verdade não há o que violar, e a primeira volta decide: se ela foi
             * ignorada, esta federação já recebeu o prêmio desta janela.
             */
            $inserido = DB::table('federation_ledger')->insertOrIgnore([
                'federation_id' => $federacaoId,
                'colony_id' => $missao->colony_id,
                'type' => 'credito',
                'amount' => (int) $qtd,
                'resource_type' => $recurso,
                'ref' => "{$ref}:{$recurso}",
                'created_at' => now(),
            ]);

            if ($inserido === 0) {
                return;
            }

            DB::table('federation_holdings')->insertOrIgnore([
                'federation_id' => $federacaoId,
                'resource_type' => $recurso,
                'amount' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('federation_holdings')
                ->where('federation_id', $federacaoId)
                ->where('resource_type', $recurso)
                ->increment('amount', (int) $qtd);
        }
    }

    private function avisar(MissionAssignment $missao): void
    {
        $usuario = Colony::find($missao->colony_id)?->user;

        if (! $usuario) {
            return;
        }

        $t = $missao->template;
        $ganhos = [];

        if ((int) $t->recompensa_fert_micro > 0) {
            $ganhos[] = number_format($t->recompensa_fert_micro / 1_000_000, 2, ',', '.').' Fert$';
        }

        foreach ($t->recompensa_recursos ?? [] as $recurso => $qtd) {
            $nome = ResourceType::find($recurso)?->nome ?? $recurso;
            $ganhos[] = "{$qtd}x {$nome}";
        }

        if ((int) $t->recompensa_xp > 0) {
            $ganhos[] = "{$t->recompensa_xp} XP";
        }

        $recompensa = $ganhos === [] ? '' : ' Você ganhou '.implode(', ', $ganhos).'.';

        $this->chat->sistema(
            ContaSistema::missoes(),
            $usuario,
            "Missão concluída: \"{$t->titulo}\".{$recompensa}",
        );
    }
}
