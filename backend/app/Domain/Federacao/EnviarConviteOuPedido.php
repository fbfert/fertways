<?php

namespace App\Domain\Federacao;

use App\Exceptions\DomainRuleException;
use App\Models\Colony;
use App\Models\Federation;
use App\Models\FederationInvite;
use Illuminate\Support\Facades\DB;

/**
 * Convite (Líder/Diplomata chama uma colônia de fora) ou pedido (uma colônia sem federação pede
 * entrada) — os dois lados do mesmo par convite/aceite (docs/decisoes.md D-114). Mesma tabela,
 * `kind` distingue; `ResponderConviteOuPedido` resolve os dois.
 */
class EnviarConviteOuPedido
{
    public function convidar(Colony $convidante, Federation $federation, Colony $alvo): FederationInvite
    {
        return $this->criar($federation, $alvo, FederationInvite::CONVITE, $convidante, exigirPermissao: true);
    }

    public function pedir(Colony $candidata, Federation $federation): FederationInvite
    {
        return $this->criar($federation, $candidata, FederationInvite::PEDIDO, $candidata, exigirPermissao: false);
    }

    private function criar(Federation $federation, Colony $alvo, string $kind, Colony $autora, bool $exigirPermissao): FederationInvite
    {
        return DB::transaction(function () use ($federation, $alvo, $kind, $autora, $exigirPermissao) {
            $federation = Federation::whereKey($federation->id)->lockForUpdate()->firstOrFail();

            if ($federation->dissolvida()) {
                throw new DomainRuleException('federacao_dissolvida', 'Esta federação não existe mais.');
            }

            // Sempre relida sob lock, nunca o objeto que o controller passou: entre o request
            // chegar e esta transação abrir, o cargo da autora pode ter mudado (ou, em teste, o
            // objeto em memória pode estar defasado — mesma razão pela qual `TransferirLideranca`/
            // `ExpulsarMembro`/`AlterarCargo` já relêem os dois lados sob lock).
            if ($exigirPermissao) {
                $autoraFresca = Colony::whereKey($autora->id)->lockForUpdate()->firstOrFail();

                if ($autoraFresca->federation_id !== $federation->id || ! $autoraFresca->podeConvidarParaFederacao()) {
                    throw new DomainRuleException('sem_permissao', 'Só o Líder ou o Diplomata convidam.');
                }
            }

            $alvo = Colony::whereKey($alvo->id)->lockForUpdate()->firstOrFail();

            if ($alvo->federation_id !== null) {
                throw new DomainRuleException('ja_tem_federacao', "{$alvo->name} já pertence a uma federação.");
            }

            $membros = Colony::where('federation_id', $federation->id)->count();

            if ($membros >= Federation::MAX_COLONIAS) {
                throw new DomainRuleException(
                    'federacao_cheia',
                    'A federação já está no teto de '.Federation::MAX_COLONIAS.' colônias.',
                );
            }

            $existe = FederationInvite::where('federation_id', $federation->id)
                ->where('colony_id', $alvo->id)
                ->where('kind', $kind)
                ->where('status', FederationInvite::PENDENTE)
                ->exists();

            if ($existe) {
                throw new DomainRuleException(
                    'pendencia_duplicada',
                    'Já existe um '.($kind === FederationInvite::CONVITE ? 'convite' : 'pedido')
                    .' pendente para esta colônia.',
                );
            }

            return FederationInvite::create([
                'federation_id' => $federation->id,
                'colony_id' => $alvo->id,
                'kind' => $kind,
                'status' => FederationInvite::PENDENTE,
                'created_by_colony_id' => $autora->id,
            ]);
        });
    }
}
