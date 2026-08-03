<?php

namespace App\Domain\Federacao;

use App\Exceptions\DomainRuleException;
use App\Models\Colony;
use App\Models\Federation;
use App\Models\Ledger;
use Illuminate\Support\Facades\DB;

/**
 * Um membro põe Fert$ no fundo da federação (A2.10, pré-requisito da decisão 3).
 *
 * ## ⚠️ Por que isto teve de existir
 *
 * O fundo era só `federation_holdings`, uma tabela de **recursos**, abastecida por doação física
 * (alguém dirigir um veículo carregado até lá). A decisão 3 do D-193 manda a guerra custar **Fert$ do
 * fundo** — e não havia Fert$ nenhum ali, nem caminho para pôr.
 *
 * Sem este serviço, o custo decidido seria **impagável por construção**: o saldo nasceria em zero e
 * ficaria lá. Uma regra que ninguém consegue cumprir não é regra, é impedimento.
 *
 * ## Qualquer membro contribui; só Líder e Intendente sacam
 *
 * Assimetria deliberada, e é a mesma do `SacarDoFundo` (D-114): pôr dinheiro no caixa comum não
 * precisa de cargo — tirar, sim.
 */
class ContribuirParaOFundo
{
    public function handle(Colony $colonia, int $fertMicro): Federation
    {
        if ($fertMicro < 1) {
            throw new DomainRuleException('valor_invalido', 'Diga quanto quer contribuir.');
        }

        return DB::transaction(function () use ($colonia, $fertMicro) {
            $c = Colony::whereKey($colonia->id)->lockForUpdate()->firstOrFail();

            if (! $c->federation_id) {
                throw new DomainRuleException('sem_federacao', 'Você não está em uma federação.');
            }

            if ((int) $c->fert_micro < $fertMicro) {
                throw new DomainRuleException(
                    'fert_insuficiente',
                    'Você não tem esse saldo em Fert$.',
                );
            }

            $c->decrement('fert_micro', $fertMicro);
            Federation::whereKey($c->federation_id)->increment('fert_micro', $fertMicro);

            /*
             * ⚠️ O `federation_ledger` exige `resource_type` NOT NULL, e Fert$ não é recurso. Por
             * isso a contribuição em dinheiro fica no `Ledger` da colônia — que é onde o dinheiro
             * dela já é registrado — em vez de forçar uma linha torta no livro da federação.
             *
             * Quando o fundo em Fert$ tiver mais de um movimento, vale abrir a coluna. Hoje seria
             * mudar um esquema para um caso só.
             */
            Ledger::create([
                'colony_id' => $c->id,
                'type' => 'contribuicao_fundo',
                'amount' => -$fertMicro,
                'resource_type' => null,
                'ref' => 'federacao:fundo:'.$c->federation_id.':'.now()->getTimestampMs(),
                'created_at' => now(),
            ]);

            return Federation::whereKey($c->federation_id)->firstOrFail();
        });
    }
}
