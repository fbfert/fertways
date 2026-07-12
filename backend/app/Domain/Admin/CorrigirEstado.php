<?php

namespace App\Domain\Admin;

use App\Exceptions\DomainRuleException;
use App\Models\Colony;
use App\Models\Ledger;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Corrigir o estado de jogo de um colono, quando um bug estragar algo (D-61).
 *
 * **Isto cria recurso do nada — e é por isso que o ledger não é opcional.**
 *
 * A regra de ouro deste jogo é que recurso não nasce sem história: o `Ledger` é append-only
 * justamente para isso, e o extrato de um colono tem de explicar cada unidade que ele tem. O
 * operador é o único capaz de furar essa regra, e a única defesa contra isso é ele **não conseguir**
 * furá-la: toda correção vira lançamento **`ajuste_admin`**, com o motivo escrito e o admin que fez.
 *
 * A auditoria guarda o **antes e o depois**; o ledger guarda o **delta**. Os dois, sempre.
 *
 * Os índices de reputação (§26.2) **não vão ao ledger** — eles não são recursos, e o ledger é a
 * contabilidade da economia. Vão só à auditoria, que é onde eles pertencem.
 */
class CorrigirEstado
{
    public function __construct(private readonly Auditoria $auditoria) {}

    /** Os quatro índices do §26.2. Nascem em 500 (D-48). */
    public const INDICES = [
        'confianca_comercial',
        'conduta_social',
        'status_civico',
        'honra_militar_diplomatica',
    ];

    /**
     * @param  array<string,int>  $recursos  code => novo saldo absoluto (não delta)
     */
    public function corrigir(Colony $colony, ?int $fertMicro, array $recursos, array $indices, string $motivo): void
    {
        if (trim($motivo) === '') {
            throw new DomainRuleException(
                'motivo_obrigatorio',
                'Toda correção precisa de um motivo escrito: ela é a única coisa no jogo que cria valor sem origem.',
            );
        }

        DB::transaction(function () use ($colony, $fertMicro, $recursos, $indices, $motivo) {
            $colony = Colony::whereKey($colony->id)->lockForUpdate()->firstOrFail();

            $antes = $this->retrato($colony);

            if ($fertMicro !== null && $fertMicro !== (int) $colony->fert_micro) {
                $delta = $fertMicro - (int) $colony->fert_micro;
                $colony->update(['fert_micro' => $fertMicro]);
                $this->lancar($colony, $delta, null, $motivo);
            }

            foreach ($recursos as $code => $novo) {
                $linha = $colony->resources()->where('resource_type', $code)->first();

                if (! $linha || (int) $linha->amount === (int) $novo) {
                    continue;
                }

                $delta = (int) $novo - (int) $linha->amount;
                $linha->update(['amount' => (int) $novo]);
                $this->lancar($colony, $delta, $code, $motivo);
            }

            // Os índices são do usuário, não da colônia — e não são recursos, então não vão ao ledger.
            $user = $colony->user;

            if ($user) {
                $mudanca = [];

                foreach ($indices as $indice => $valor) {
                    if (! in_array($indice, self::INDICES, true)) {
                        continue;
                    }

                    if ((int) $user->{$indice} !== (int) $valor) {
                        $mudanca[$indice] = (int) $valor;
                    }
                }

                if ($mudanca !== []) {
                    $user->forceFill($mudanca)->save();
                }
            }

            $this->auditoria->registrar(
                'jogador.corrigir',
                "Corrigiu o estado de {$colony->name}. Motivo: {$motivo}",
                "colony:{$colony->id}",
                $antes,
                $this->retrato($colony->fresh()),
            );
        });
    }

    /**
     * O lançamento que dá história ao valor que nasceu do nada.
     *
     * `amount` é o **delta**, com sinal — ele pode ser negativo (uma correção que **tira** recurso de
     * quem o ganhou por um bug). É a única coisa que faz o extrato fechar.
     */
    private function lancar(Colony $colony, int $delta, ?string $recurso, string $motivo): void
    {
        Ledger::create([
            'colony_id' => $colony->id,
            'type' => 'ajuste_admin',
            'amount' => $delta,
            'resource_type' => $recurso,
            'ref' => 'admin:'.mb_substr($motivo, 0, 180),
            'created_at' => now(),
        ]);
    }

    private function retrato(Colony $colony): array
    {
        $user = $colony->user;

        return [
            'fert_micro' => (int) $colony->fert_micro,
            'recursos' => $colony->resources()->pluck('amount', 'resource_type')->toArray(),
            'indices' => $user ? collect(self::INDICES)->mapWithKeys(
                fn (string $i) => [$i => (int) $user->{$i}],
            )->toArray() : [],
        ];
    }
}
