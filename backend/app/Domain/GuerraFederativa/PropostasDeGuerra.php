<?php

namespace App\Domain\GuerraFederativa;

use App\Exceptions\DomainRuleException;
use App\Models\Colony;
use App\Models\Federation;
use App\Models\FederationWar;
use App\Models\FederationWarProposal;
use Illuminate\Support\Facades\DB;

/**
 * As guardas que a capitulação e o tratado de paz têm em comum (A2.10, decisões 8 e 9).
 *
 * As duas saídas antecipadas do §8 fazem coisas diferentes no aceite e **as mesmas cinco perguntas
 * antes dele**: quem fala pela federação, se ela é parte desta guerra, se a guerra ainda corre, se já
 * há proposta pendente, e — na resposta — se quem responde é mesmo o outro lado.
 *
 * Escrever essas cinco duas vezes seria pedir para elas divergirem no primeiro ajuste. É o mesmo
 * argumento que recolheu `EmGuerra` (D-205).
 */
class PropostasDeGuerra
{
    public function __construct(private readonly RatingFederativo $rating) {}

    /**
     * A guerra ativa em que esta colônia é parte, com a federação dela e a adversária.
     *
     * ⚠️ Tudo relido do banco sob trava: entre carregar a tela e clicar o botão, a guerra pode ter
     * acabado pelo prazo e a colônia pode ter trocado de federação. Decidir sobre o modelo em mãos
     * seria decidir sobre um mundo que já não existe.
     *
     * @return array{0: FederationWar, 1: Federation, 2: Federation} guerra, minha federação, a outra
     */
    public function beligerante(Colony $autor, int $warId): array
    {
        $colonia = Colony::whereKey($autor->id)->lockForUpdate()->first();

        if (! $colonia?->federation_id) {
            throw new DomainRuleException('sem_federacao', 'Você não está em uma federação.');
        }

        /* Mesma permissão da aliança, da guerra e da neutralidade: quem fala com fora é um só. */
        if (! $colonia->podeConvidarParaFederacao()) {
            throw new DomainRuleException(
                'sem_permissao',
                'Só o Líder ou o Diplomata negociam o fim de uma guerra.',
            );
        }

        $guerra = FederationWar::whereKey($warId)->lockForUpdate()->first();

        if (! $guerra) {
            throw new DomainRuleException('guerra_inexistente', 'Esta guerra não existe.');
        }

        if ($guerra->status !== 'ativa') {
            throw new DomainRuleException('guerra_encerrada', 'Esta guerra já acabou.');
        }

        $minhaId = (int) $colonia->federation_id;

        $outraId = match ($minhaId) {
            (int) $guerra->declarante_id => (int) $guerra->alvo_id,
            (int) $guerra->alvo_id => (int) $guerra->declarante_id,
            default => throw new DomainRuleException(
                'nao_e_parte',
                'A sua federação não é parte desta guerra.',
            ),
        };

        return [
            $guerra,
            Federation::whereKey($minhaId)->lockForUpdate()->firstOrFail(),
            Federation::whereKey($outraId)->lockForUpdate()->firstOrFail(),
        ];
    }

    /** A proposta pendente deste tipo nesta guerra, se houver. Só pode haver uma. */
    public function pendente(int $warId, string $tipo): ?FederationWarProposal
    {
        return FederationWarProposal::where('war_id', $warId)
            ->where('tipo', $tipo)
            ->where('status', 'pendente')
            ->lockForUpdate()
            ->first();
    }

    /**
     * Abre a proposta, recusando a segunda.
     *
     * ⚠️ Uma pendente por tipo e por guerra, **venha de qual lado vier**. Duas capitulações abertas
     * ao mesmo tempo dariam ao vencedor dois espólios pela mesma rendição; dois tratados fariam cada
     * lado esperar o aceite do outro numa proposta que o outro nem viu.
     */
    public function abrir(
        FederationWar $guerra,
        string $tipo,
        Colony $autor,
        Federation $minha,
    ): FederationWarProposal {
        if ($this->pendente($guerra->id, $tipo)) {
            throw new DomainRuleException(
                'ja_pendente',
                $tipo === FederationWarProposal::TRATADO
                    ? 'Já há um tratado de paz na mesa nesta guerra. Responda a ele.'
                    : 'Já há uma capitulação na mesa nesta guerra.',
            );
        }

        return FederationWarProposal::create([
            'war_id' => $guerra->id,
            'tipo' => $tipo,
            'proponente_federation_id' => $minha->id,
            'proposta_por_colony_id' => $autor->id,
            'status' => 'pendente',
        ]);
    }

    /**
     * A proposta pendente que ESTA colônia pode responder — nunca a própria.
     *
     * Simétrico à aliança (D-?/`Diplomacia`): propor é de um lado, aceitar é do outro. Sem esta
     * guarda, o proponente aceitaria a própria capitulação e escolheria o próprio preço.
     */
    public function paraResponder(int $warId, string $tipo, Federation $minha): FederationWarProposal
    {
        $proposta = $this->pendente($warId, $tipo);

        if (! $proposta) {
            throw new DomainRuleException('sem_proposta', 'Não há proposta pendente para responder.');
        }

        if ((int) $proposta->proponente_federation_id === $minha->id) {
            throw new DomainRuleException(
                'proposta_propria',
                'Quem responde é a outra federação — não quem propôs.',
            );
        }

        return $proposta;
    }

    /**
     * A guerra acaba **agora**.
     *
     * O `status` guarda o COMO (`capitulada`/`tratado`), e não um `encerrada` genérico: a migration
     * do esqueleto (D-193) já reservou esses dois valores por escrito, e quem consultar o histórico
     * precisa distinguir uma guerra que expirou de uma que foi negociada. Todo o resto do jogo só
     * pergunta se o status é `ativa`, então nada além do histórico muda de comportamento.
     *
     * ⚠️ `termina_em` **não** é alterado: o cooldown do par (§10) conta a partir dele, e antecipá-lo
     * transformaria capitular num jeito barato de zerar o cooldown e ser declarado de novo no dia
     * seguinte. Quem capitula compra o fim da guerra, não o fim da proteção que vem depois dela.
     */
    public function encerrar(FederationWar $guerra, string $status, ?float $resultadoDoDeclarante = null): void
    {
        DB::transaction(function () use ($guerra, $status, $resultadoDoDeclarante) {
            $guerra->update([
                'status' => $status,
                'encerrada_em' => now(),
                'motivo_fim' => $status === 'capitulada' ? 'capitulacao' : 'tratado',
            ]);

            /*
             * O ranking federativo (D-207). O **tratado é 0,5 para os dois**: ninguém venceu, e a
             * paz não move espólio — dar vitória a um dos lados por ter proposto premiaria propor.
             * A **capitulação** vem com o resultado de quem se rendeu, que quem chama já sabe.
             */
            $this->rating->aplicar($guerra, $resultadoDoDeclarante ?? 0.5);
        });
    }
}
