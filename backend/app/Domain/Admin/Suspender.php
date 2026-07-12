<?php

namespace App\Domain\Admin;

use App\Exceptions\DomainRuleException;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Suspender e reintegrar um jogador (D-61).
 *
 * **O que a suspensão faz, e o que ela deliberadamente NÃO faz:**
 *
 *  - **Barra o acesso.** O login é recusado e **os tokens dele são revogados na hora** — sem isso, um
 *    suspenso continuaria jogando pela API com o token que já tinha no bolso, porque token do
 *    Sanctum não expira (é a lição do logout, D-53).
 *  - **Congela só o comércio.** Nenhuma carga sai da colônia dele. Isso **reusa a restrição comercial
 *    do §9.4** — a mesma que o Ministério das Reputações aplica —, em vez de inventar mecânica nova.
 *    Ver `DespacharVeiculo::exigirSemRestricaoComercial`.
 *  - **NÃO congela a colônia.** Ela continua produzindo, a fila anda, e os veículos em rota chegam. O
 *    mundo não para. Foi decisão do usuário, e é a mais previsível: nada se perde, e ao voltar ele
 *    encontra o que produziu. Congelar abriria perguntas feias — o veículo dele a meio caminho da
 *    minha colônia chega ou fica no ar? o acordo que vence durante a suspensão vira calote?
 *  - **NÃO tira o conciliador do cargo.** São ritos diferentes: o §26.7 tem a sua própria suspensão
 *    de conciliador, com reversões e prazo. Um conciliador suspenso simplesmente não entra para
 *    julgar, e os casos dele vencem as 48 h e sobem à equipe — que é o que o §9.2 já manda fazer.
 */
class Suspender
{
    public function __construct(private readonly Auditoria $auditoria) {}

    /** @param  Carbon|null  $ate  nulo = definitiva */
    public function suspender(User $user, string $motivo, ?Carbon $ate): User
    {
        if ($motivo === '') {
            throw new DomainRuleException('motivo_obrigatorio', 'Toda suspensão precisa de um motivo escrito.');
        }

        if ($ate !== null && $ate->isPast()) {
            throw new DomainRuleException('prazo_no_passado', 'O prazo da suspensão já venceu antes de começar.');
        }

        $antes = $this->retrato($user);

        DB::transaction(function () use ($user, $motivo, $ate) {
            $user->forceFill([
                'suspenso_em' => now(),
                'suspenso_ate' => $ate,
                'suspenso_motivo' => $motivo,
            ])->save();

            /*
             * Os tokens morrem AGORA. Sem isto, a suspensão seria só uma porta trancada com a janela
             * aberta: o token do Sanctum não expira, e o suspenso continuaria jogando pela API com o
             * que já tinha no bolso.
             */
            $user->tokens()->delete();
        });

        $this->auditoria->registrar(
            'jogador.suspender',
            "Suspendeu {$user->nickname} — ".($ate ? 'até '.$ate->toDateTimeString() : 'definitivamente').". Motivo: {$motivo}",
            "user:{$user->id}",
            $antes,
            $this->retrato($user->fresh()),
        );

        return $user->fresh();
    }

    public function reintegrar(User $user, string $motivo): User
    {
        if (! self::estaSuspenso($user)) {
            throw new DomainRuleException('nao_esta_suspenso', 'Este jogador não está suspenso.');
        }

        $antes = $this->retrato($user);

        $user->forceFill([
            'suspenso_em' => null,
            'suspenso_ate' => null,
            'suspenso_motivo' => null,
        ])->save();

        $this->auditoria->registrar(
            'jogador.reintegrar',
            "Reintegrou {$user->nickname}. Motivo: {$motivo}",
            "user:{$user->id}",
            $antes,
            $this->retrato($user->fresh()),
        );

        return $user->fresh();
    }

    /**
     * Está suspenso **agora**?
     *
     * A suspensão com prazo **expira sozinha**, sem precisar do tick: comparar a data na leitura é
     * mais barato e mais confiável do que uma varredura periódica que pode não rodar. O campo fica
     * preenchido depois de vencer, e isso é de propósito — o histórico da punição não se apaga só
     * porque ela terminou.
     */
    public static function estaSuspenso(?User $user): bool
    {
        if (! $user || $user->suspenso_em === null) {
            return false;
        }

        return $user->suspenso_ate === null || $user->suspenso_ate->isFuture();
    }

    private function retrato(User $user): array
    {
        return [
            'suspenso_em' => $user->suspenso_em?->toDateTimeString(),
            'suspenso_ate' => $user->suspenso_ate?->toDateTimeString(),
            'suspenso_motivo' => $user->suspenso_motivo,
        ];
    }
}
