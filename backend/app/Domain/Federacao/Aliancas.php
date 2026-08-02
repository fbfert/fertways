<?php

namespace App\Domain\Federacao;

use App\Models\Federation;
use Illuminate\Support\Facades\DB;

/**
 * Quem é aliado de quem (A2.5, item 7) — a **consulta**. Quem muda é a `Diplomacia`.
 *
 * ## ⚠️ O "bloco" é o conceito que sustenta o antimonopólio
 *
 * Uma federação aliada a outras duas não é uma federação de 12 colônias: é um **bloco de até 36**
 * operando em conjunto. Se o teto de ocupação de zonas continuasse olhando só a federação, aliar-se
 * viraria lavanderia de monopólio — a regra do §04 seria contornada pela porta da frente, montando
 * três federações aliadas em vez de uma grande.
 *
 * Por isso `bloco()` existe, e por isso `OcuparZonaNeutra` e `Concentracao` passam a contá-lo.
 *
 * ## O bloco é RASO de propósito: aliado de aliado não é aliado
 *
 * O bloco de A são A e as aliadas diretas de A — não o fecho transitivo. Duas razões:
 *
 * 1. **Legibilidade.** O jogador consegue enumerar as suas aliadas; não consegue enumerar as
 *    aliadas das aliadas das aliadas, e um limite que não se consegue prever é um limite que
 *    parece arbitrário.
 * 2. **O teto de aliadas deixaria de significar alguma coisa.** Com transitividade, um teto de 2
 *    ainda produziria uma corrente ligando o mundo inteiro num único bloco, que é exatamente o que
 *    o teto existe para impedir.
 */
class Aliancas
{
    /**
     * As federações aliadas desta, por aliança **aceita**.
     *
     * Proposta pendente não vale: o efeito de jogo nasce do consentimento das duas, e uma proposta
     * é a vontade de uma só.
     *
     * @return list<int>
     */
    public function aliadasDe(int $federacaoId): array
    {
        return DB::table('federation_alliances')
            ->where('status', 'aceita')
            ->where(fn ($q) => $q->where('menor_id', $federacaoId)->orWhere('maior_id', $federacaoId))
            ->get(['menor_id', 'maior_id'])
            ->map(fn ($l) => (int) ($l->menor_id === $federacaoId ? $l->maior_id : $l->menor_id))
            ->values()
            ->all();
    }

    /**
     * A federação **mais** as aliadas diretas dela. Ver o docblock: raso, não transitivo.
     *
     * @return list<int>
     */
    public function bloco(int $federacaoId): array
    {
        return [$federacaoId, ...$this->aliadasDe($federacaoId)];
    }

    public function saoAliadas(?int $a, ?int $b): bool
    {
        if ($a === null || $b === null || $a === $b) {
            return false;
        }

        return DB::table('federation_alliances')
            ->where('status', 'aceita')
            ->where('menor_id', min($a, $b))
            ->where('maior_id', max($a, $b))
            ->exists();
    }

    /**
     * A relação desta federação com todas as outras — o que a tela diplomática mostra.
     *
     * @return list<array{federacao:Federation, status:string, propus:bool}>
     */
    public function relacoesDe(int $federacaoId): array
    {
        $linhas = DB::table('federation_alliances')
            ->where(fn ($q) => $q->where('menor_id', $federacaoId)->orWhere('maior_id', $federacaoId))
            ->get();

        $outras = Federation::whereIn(
            'id',
            $linhas->map(fn ($l) => (int) $l->menor_id === $federacaoId ? $l->maior_id : $l->menor_id),
        )->get()->keyBy('id');

        $relacoes = [];

        foreach ($linhas as $l) {
            $outraId = (int) ($l->menor_id === $federacaoId ? $l->maior_id : $l->menor_id);

            if (! isset($outras[$outraId])) {
                continue;
            }

            $relacoes[] = [
                'federacao' => $outras[$outraId],
                'status' => $l->status,
                // Quem propôs não pode aceitar a própria proposta — a tela precisa saber de que lado está.
                'propus' => (int) $l->proposta_por_id === $federacaoId,
            ];
        }

        return $relacoes;
    }
}
