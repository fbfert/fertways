<?php

namespace App\Domain\Zona;

use App\Exceptions\DomainRuleException;
use App\Models\Colony;
use App\Models\NeutralZone;
use Illuminate\Support\Facades\DB;

/**
 * Ergue e evolui as estruturas da zona neutra (GDD §17.4; docs/decisoes.md D-67).
 *
 * **O material vem do CANTEIRO, não do estoque da colônia** — e essa é a decisão que dá forma a tudo
 * aqui. As obras da zona exigem **entrega física**: despacha-se um veículo com Metal Bruto e Ligas, o
 * material entra no canteiro (`zone_materials`), e só então a obra pode começar.
 *
 * ⚠️ Isso **contradiz a ocupação**, que debita direto da colônia e ergue o Posto de Comando sem
 * veículo nenhum (D-52). É deliberado: a ocupação é o ato de **chegar**, e as obras são o ato de
 * **investir**. Não "conserte" um pelo outro sem perguntar.
 *
 * E há uma consequência que ninguém planejou e que é boa: **o cerco impede fortificar sob sítio**.
 * Nada entra nem sai de uma zona cercada (D-66), então o material não chega, então a obra não começa.
 * Quem cerca impede o cercado de se defender melhor.
 */
class ConstruirNaZona
{
    public function handle(Colony $colony, NeutralZone $zona, string $estrutura): void
    {
        if (! in_array($estrutura, Estruturas::CONSTRUIVEIS, true)) {
            throw new DomainRuleException(
                'estrutura_invalida',
                "A zona não tem {$estrutura}. O Posto de Comando nasce com a ocupação e não se ergue.",
            );
        }

        DB::transaction(function () use ($colony, $zona, $estrutura) {
            $zona = NeutralZone::whereKey($zona->id)->lockForUpdate()->firstOrFail();

            if ($zona->owner_colony_id !== $colony->id) {
                throw new DomainRuleException('zona_nao_e_sua', 'Esta zona neutra não é sua.');
            }

            /*
             * Cercada, nada entra nem sai (§28.10, D-66) — e isso alcança a obra. O material já no
             * canteiro poderia, em tese, ser usado; mas erguer uma muralha debaixo do cerco é
             * exatamente o que o sítio existe para impedir. Sem obra sob sítio.
             */
            if ($zona->cercada()) {
                throw new DomainRuleException(
                    'zona_cercada',
                    'A zona está cercada: não se constrói sob sítio. Rompa o cerco ou espere as 48 h.',
                );
            }

            // Uma obra por vez. A zona não é a colônia, que tem fila (D-13): aqui é um canteiro só.
            if ($zona->obraEmCurso()) {
                throw new DomainRuleException(
                    'obra_em_curso',
                    'Já há uma obra em curso nesta zona. Espere-a terminar.',
                );
            }

            $coluna = Estruturas::COLUNA[$estrutura];
            $atual = (int) $zona->{$coluna};
            $alvo = $atual + 1;

            $spec = DB::table('building_specs')
                ->where('building_type', $estrutura)
                ->where('level', $alvo)
                ->first();

            if (! $spec) {
                throw new DomainRuleException(
                    'nivel_maximo',
                    Estruturas::de($estrutura)['nome']." já está no nível máximo ({$atual}).",
                );
            }

            $custo = json_decode($spec->cost_json, true) ?: [];

            $this->debitarDoCanteiro($zona, $custo, $estrutura, $alvo);

            $zona->obras()->create([
                'structure' => $estrutura,
                'target_level' => $alvo,
                'finishes_at' => now()->addSeconds((int) $spec->build_time_seconds),
            ]);
        });
    }

    /**
     * O canteiro paga a obra. Se faltar material, a obra **não começa** — e a mensagem diz o que
     * falta e quanto, porque o colono tem de despachar um veículo com exatamente isso.
     *
     * @param  array<string,int>  $custo
     */
    private function debitarDoCanteiro(NeutralZone $zona, array $custo, string $estrutura, int $alvo): void
    {
        $canteiro = $zona->materiais()->lockForUpdate()->get()->keyBy('resource_type');

        $faltam = [];

        foreach ($custo as $recurso => $qtd) {
            $tem = (int) ($canteiro->get($recurso)?->amount ?? 0);

            if ($tem < $qtd) {
                $faltam[] = "{$recurso}: faltam ".($qtd - $tem);
            }
        }

        if ($faltam !== []) {
            $nome = Estruturas::de($estrutura)['nome'];

            throw new DomainRuleException(
                'canteiro_insuficiente',
                "O canteiro da zona não tem material para {$nome} nível {$alvo}. "
                .implode(' · ', $faltam)
                .'. Despache um veículo com o material até a zona.',
            );
        }

        foreach ($custo as $recurso => $qtd) {
            $canteiro[$recurso]->decrement('amount', $qtd);
        }
    }
}
