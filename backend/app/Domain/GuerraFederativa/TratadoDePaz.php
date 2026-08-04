<?php

namespace App\Domain\GuerraFederativa;

use App\Domain\News\PublicarNoticia;
use App\Exceptions\DomainRuleException;
use App\Models\Colony;
use App\Models\FederationWarProposal;
use Illuminate\Support\Facades\DB;

/**
 * O tratado de paz (A2.10, decisão 8 — GDD §8).
 *
 * *"Tratado de paz encerra antes do prazo, e exige consentimento mútuo — simétrico à aliança."*
 *
 * ## O que ele NÃO é
 *
 * ⚠️ **Não há espólio, e é isso que o separa da capitulação.** As duas acabam a guerra antes da hora;
 * o tratado devolve as duas federações ao estado neutro **sem que nada mude de mãos**. Quem quer
 * levar alguma coisa não propõe paz: espera o outro capitular, ou vence no prazo.
 *
 * Sem essa diferença as duas saídas seriam a mesma, e a decisão 9 — o vencedor escolhe o preço —
 * não teria onde acontecer.
 *
 * ## Consentimento mútuo, e por isso qualquer um dos dois propõe
 *
 * Ao contrário da capitulação, que só faz sentido vinda de quem quer sair, a paz pode partir de
 * qualquer lado: os dois podem simplesmente estar cansados. Quem propõe não aceita — a guarda é a
 * mesma da aliança.
 */
class TratadoDePaz
{
    public function __construct(
        private readonly PropostasDeGuerra $propostas,
        private readonly PublicarNoticia $noticias,
    ) {}

    public function propor(Colony $autor, int $warId): FederationWarProposal
    {
        return DB::transaction(function () use ($autor, $warId) {
            [$guerra, $minha, $outra] = $this->propostas->beligerante($autor, $warId);

            $proposta = $this->propostas->abrir(
                $guerra,
                FederationWarProposal::TRATADO,
                $autor,
                $minha,
            );

            /*
             * Pública, como a declaração e a neutralidade. Uma proposta de paz que só as duas
             * lideranças vissem deixaria os membros das duas federações — que são quem está a
             * marchar e a apanhar — sem saber que a guerra pode acabar amanhã.
             */
            $this->noticias->publicar(
                "Proposta de paz: {$minha->name} e {$outra->name}",
                "{$minha->name} propôs um tratado de paz a {$outra->name}. "
                    .'Se aceito, a guerra acaba na hora, sem espólio para nenhum dos dois.',
                'Ministério das Relações',
            );

            return $proposta;
        });
    }

    /** O outro lado aceita: a guerra acaba agora, e nada muda de mãos. */
    public function aceitar(Colony $autor, int $warId): void
    {
        DB::transaction(function () use ($autor, $warId) {
            [$guerra, $minha, $outra] = $this->propostas->beligerante($autor, $warId);

            $proposta = $this->propostas->paraResponder(
                $warId,
                FederationWarProposal::TRATADO,
                $minha,
            );

            $proposta->update([
                'status' => 'aceita',
                'respondida_por_colony_id' => $autor->id,
                'respondida_em' => now(),
            ]);

            $this->propostas->encerrar($guerra, 'tratado');

            $this->noticias->publicar(
                "Paz assinada: {$outra->name} e {$minha->name}",
                'O tratado foi aceito e a guerra terminou antes do prazo. '
                    .'Nenhum território e nenhum valor mudou de mãos.',
                'Ministério das Relações',
            );
        });
    }

    /**
     * O outro lado recusa. A guerra continua, e a mesa fica livre para uma nova proposta.
     *
     * ⚠️ **A capitulação não tem este método, e a assimetria é deliberada.** Recusar uma paz é dizer
     * "quero continuar lutando", que é uma posição legítima. Recusar uma **rendição** seria prender
     * o adversário numa derrota que já aconteceu — o §9 chama isso de tempo perdido, e é a razão
     * declarada de a capitulação existir.
     */
    public function recusar(Colony $autor, int $warId): void
    {
        DB::transaction(function () use ($autor, $warId) {
            [, $minha, $outra] = $this->propostas->beligerante($autor, $warId);

            $proposta = $this->propostas->paraResponder(
                $warId,
                FederationWarProposal::TRATADO,
                $minha,
            );

            $proposta->update([
                'status' => 'recusada',
                'respondida_por_colony_id' => $autor->id,
                'respondida_em' => now(),
            ]);

            $this->noticias->publicar(
                "Paz recusada: {$minha->name} e {$outra->name}",
                "{$minha->name} recusou o tratado proposto por {$outra->name}. A guerra continua.",
                'Ministério das Relações',
            );
        });
    }

    /** Quem propôs pode tirar a proposta da mesa enquanto ninguém respondeu. */
    public function retirar(Colony $autor, int $warId): void
    {
        DB::transaction(function () use ($autor, $warId) {
            [, $minha] = $this->propostas->beligerante($autor, $warId);

            $proposta = $this->propostas->pendente($warId, FederationWarProposal::TRATADO);

            if (! $proposta) {
                throw new DomainRuleException('sem_proposta', 'Não há proposta de paz na mesa.');
            }

            if ((int) $proposta->proponente_federation_id !== $minha->id) {
                throw new DomainRuleException(
                    'proposta_alheia',
                    'Esta proposta é da outra federação. Você pode aceitá-la ou recusá-la.',
                );
            }

            $proposta->update([
                'status' => 'retirada',
                'respondida_por_colony_id' => $autor->id,
                'respondida_em' => now(),
            ]);
        });
    }
}
