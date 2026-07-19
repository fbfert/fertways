<?php

namespace App\Domain\Federacao;

use App\Domain\Chat\ContaSistema;
use App\Domain\Chat\EnviarMensagem;
use App\Exceptions\DomainRuleException;
use App\Models\Colony;
use App\Models\Federation;
use App\Models\FederationInvite;
use Illuminate\Support\Facades\DB;

/**
 * Resolve um convite/pedido pendente (docs/decisoes.md D-114).
 *
 * Quem aceita/recusa um CONVITE é a colônia convidada; quem aceita/recusa um PEDIDO é o
 * Líder/Diplomata da federação. A colônia que ENTRA ao aceitar é sempre `$invite->colony` — nunca
 * necessariamente quem clicou (num pedido, é o Líder que clica e a candidata que entra).
 */
class ResponderConviteOuPedido
{
    public function __construct(private readonly EnviarMensagem $chat) {}

    public function aceitar(FederationInvite $invite, Colony $ator): void
    {
        DB::transaction(function () use ($invite, $ator) {
            $invite = FederationInvite::whereKey($invite->id)->lockForUpdate()->firstOrFail();

            if (! $invite->pendente()) {
                throw new DomainRuleException('convite_resolvido', 'Este convite/pedido já foi respondido.');
            }

            $this->exigirPermissao($invite, $ator);

            $federation = Federation::whereKey($invite->federation_id)->lockForUpdate()->firstOrFail();

            if ($federation->dissolvida()) {
                throw new DomainRuleException('federacao_dissolvida', 'Esta federação não existe mais.');
            }

            $entrando = Colony::whereKey($invite->colony_id)->lockForUpdate()->firstOrFail();

            if ($entrando->federation_id !== null) {
                throw new DomainRuleException('ja_tem_federacao', "{$entrando->name} já entrou em outra federação.");
            }

            // Refeito sob lock: a federação pode ter enchido entre o convite e o aceite.
            $membrosAtuais = Colony::where('federation_id', $federation->id)->get();

            if ($membrosAtuais->count() >= Federation::MAX_COLONIAS) {
                throw new DomainRuleException(
                    'federacao_cheia',
                    'A federação já está no teto de '.Federation::MAX_COLONIAS.' colônias.',
                );
            }

            $entrando->forceFill([
                'federation_id' => $federation->id,
                'federation_role' => Federation::MEMBRO,
            ])->save();

            $invite->forceFill(['status' => FederationInvite::ACEITO, 'decided_at' => now()])->save();

            // Aceitar um cancela os outros pendentes da mesma colônia — convite de outra federação,
            // ou pedido que ela mesma tinha feito em paralelo a uma terceira.
            FederationInvite::where('colony_id', $entrando->id)
                ->where('id', '!=', $invite->id)
                ->where('status', FederationInvite::PENDENTE)
                ->update(['status' => FederationInvite::CANCELADO, 'decided_at' => now()]);

            // D-121: os membros que já estavam lá são avisados de quem chegou — a lista de
            // membros muda e ninguém fica sabendo por que, a não ser abrindo a tela de novo.
            foreach ($membrosAtuais as $membro) {
                if ($usuario = $membro->user) {
                    $this->chat->sistema(
                        ContaSistema::federacao(),
                        $usuario,
                        "{$entrando->name} entrou na federação.",
                    );
                }
            }
        });
    }

    public function recusar(FederationInvite $invite, Colony $ator): void
    {
        DB::transaction(function () use ($invite, $ator) {
            $invite = FederationInvite::whereKey($invite->id)->lockForUpdate()->firstOrFail();

            if (! $invite->pendente()) {
                throw new DomainRuleException('convite_resolvido', 'Este convite/pedido já foi respondido.');
            }

            $this->exigirPermissao($invite, $ator);

            $invite->forceFill(['status' => FederationInvite::RECUSADO, 'decided_at' => now()])->save();
        });
    }

    /** Cancela um convite/pedido ainda pendente — só quem o criou pode desistir dele. */
    public function cancelar(FederationInvite $invite, Colony $ator): void
    {
        DB::transaction(function () use ($invite, $ator) {
            $invite = FederationInvite::whereKey($invite->id)->lockForUpdate()->firstOrFail();

            if (! $invite->pendente()) {
                throw new DomainRuleException('convite_resolvido', 'Este convite/pedido já foi respondido.');
            }

            if ($invite->created_by_colony_id !== $ator->id) {
                throw new DomainRuleException('sem_permissao', 'Só quem criou este convite/pedido pode cancelá-lo.');
            }

            $invite->forceFill(['status' => FederationInvite::CANCELADO, 'decided_at' => now()])->save();
        });
    }

    private function exigirPermissao(FederationInvite $invite, Colony $ator): void
    {
        if ($invite->kind === FederationInvite::CONVITE) {
            if ($ator->id !== $invite->colony_id) {
                throw new DomainRuleException('sem_permissao', 'Só a colônia convidada responde a este convite.');
            }

            return;
        }

        // PEDIDO: quem responde é o Líder/Diplomata da federação, não a candidata. Relido sob
        // lock, nunca o objeto que o controller passou (mesma razão de `EnviarConviteOuPedido`).
        $atorFresco = Colony::whereKey($ator->id)->lockForUpdate()->firstOrFail();

        if ($atorFresco->federation_id !== $invite->federation_id || ! $atorFresco->podeConvidarParaFederacao()) {
            throw new DomainRuleException(
                'sem_permissao',
                'Só o Líder ou o Diplomata da federação respondem a um pedido de entrada.',
            );
        }
    }
}
