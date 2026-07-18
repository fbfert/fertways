<?php

namespace App\Domain\Federacao;

use App\Exceptions\DomainRuleException;
use App\Models\Colony;
use App\Models\Federation;
use Illuminate\Support\Facades\DB;

/**
 * Funda uma federação (GDD §04; docs/decisoes.md D-114). A colônia fundadora vira Líder.
 */
class CriarFederacao
{
    public function handle(Colony $colony, string $nome): Federation
    {
        $nome = trim($nome);

        if ($nome === '') {
            throw new DomainRuleException('nome_invalido', 'Dê um nome à federação.');
        }

        return DB::transaction(function () use ($colony, $nome) {
            $colony = Colony::whereKey($colony->id)->lockForUpdate()->firstOrFail();

            if ($colony->federation_id !== null) {
                throw new DomainRuleException('ja_tem_federacao', 'Sua colônia já pertence a uma federação.');
            }

            // O nome é único no banco (mesmo de uma federação já dissolvida — histórico não se
            // reescreve, e reaproveitar o nome de uma extinta confundiria o extrato de quem lembra
            // dela). A checagem aqui só troca a IntegrityException crua por uma mensagem legível.
            if (Federation::where('name', $nome)->exists()) {
                throw new DomainRuleException('nome_em_uso', "Já existe uma federação chamada «{$nome}».");
            }

            $federation = Federation::create(['name' => $nome]);

            $colony->forceFill([
                'federation_id' => $federation->id,
                'federation_role' => Federation::LIDER,
            ])->save();

            return $federation;
        });
    }
}
