<?php

namespace App\Domain\Colony;

use App\Models\Colony;
use Illuminate\Support\Facades\DB;

/**
 * Quanto cabe de cada recurso na colônia (A2.7 item 4 / BALANCEAMENTO §14).
 *
 * ## O teto trava, não derrama
 *
 * *"Ao encher, a produção para; nada transborda e vira desperdício. O jogador perde oportunidade,
 * nunca estoque."* Quem aplica isso é o `ColonyTick`; aqui mora só **quanto cabe**.
 *
 * ## ⚠️ Não confundir com o `Silo`
 *
 * O `Silo` responde *"quanto está protegido de saque"*; este responde *"quanto cabe"*. Mesmo prédio,
 * duas perguntas — e dois números que precisam se mover em separado. O `Silo` continua plano em
 * 10.000, o que é assunto da proteção e fica para quando o saque de colônia existir.
 *
 * ## O Biocombustível fica de fora, e de propósito
 *
 * Ele já tem o Tanque de Combustível (§21.9/D-131), que é um prédio dedicado com curva própria. Um
 * recurso não deve responder a dois prédios: o jogador subiria o Depósito Local e não veria efeito
 * nenhum, ou pior, veria efeito pela metade.
 *
 * ## Cache por instância
 *
 * O tick chama isto por colônia e por recurso. Reler a mesma linha de parâmetros cinquenta vezes por
 * minuto seria desperdício sem propósito — mesma escolha de `Populacao\Parametros`.
 */
class TetoDoEstoque
{
    /** Tem prédio próprio (§21.9/D-131). Ver o docblock. */
    public const SEM_TETO_GERAL = ['biocombustivel'];

    private ?object $linha = null;

    /** @var array<int,int> nível do Depósito Local por colônia */
    private array $niveis = [];

    /** @var array<int,array<string,int>> o piso pessoal por colônia — ver `piso()`. */
    private array $pisos = [];

    private function parametros(): object
    {
        return $this->linha ??= DB::table('estoque_settings')->find(1)
            ?? throw new \RuntimeException(
                'estoque_settings sem a linha 1 — a migration a insere; se sumiu, alguém a apagou à mão.'
            );
    }

    public function ativo(): bool
    {
        return (bool) $this->parametros()->ativo;
    }

    /**
     * Quanto cabe de um recurso, ou `null` quando não há teto.
     *
     * `null` — e não um número enorme — porque *"sem teto"* e *"teto alto"* são coisas diferentes, e
     * o chamador precisa poder distinguir. Um sentinela numérico viraria, mais cedo ou mais tarde,
     * um teto de verdade que ninguém escolheu.
     */
    public function capacidade(Colony $colonia, string $recurso): ?int
    {
        if (! $this->ativo() || in_array($recurso, self::SEM_TETO_GERAL, true)) {
            return null;
        }

        $nivel = $this->nivelDoDeposito($colonia);

        /*
         * Sem Depósito Local não há teto — e não teto zero. O prédio nasce no nível 1 com a colônia
         * (D-105/106), então isto só acontece em mundo de teste mal montado; devolver zero ali
         * pararia a produção inteira por um dado ausente, que é o pior jeito de falhar.
         */
        if ($nivel < 1) {
            return null;
        }

        $p = $this->parametros();
        $capacidade = (int) $p->capacidade_base;

        // `base × fator^(nível−1)`, em milésimos para não depender de ponto flutuante.
        for ($i = 1; $i < $nivel; $i++) {
            $capacidade = intdiv($capacidade * (int) $p->capacidade_fator_milesimos, 1000);
        }

        /*
         * ⚠️ O PISO PESSOAL (D-191, opção d): `max(curva, o que a colônia já tinha)`.
         *
         * Sem ele, ligar o teto faria as 29 colônias pararem de produzir de uma vez — elas acumularam
         * por meses sem limite nenhum, e a mediana de oxigênio do mundo (90.201) não cabe nem nos
         * 74.501 do nível MÁXIMO do Depósito Local. O §6.7 proíbe exatamente isso.
         *
         * `null` na coluna significa "nunca precisou de piso": a curva governa sozinha, que é o caso
         * de toda colônia nova.
         */
        return max($capacidade, $this->piso($colonia, $recurso));
    }

    /**
     * O piso gravado no dia da virada, ou zero.
     *
     * Uma consulta por colônia, e não por recurso: o tick pergunta o teto de cada recurso da colônia
     * no mesmo instante, e uma consulta por linha seria N+1 no caminho mais quente do jogo.
     */
    private function piso(Colony $colonia, string $recurso): int
    {
        $pisos = $this->pisos[$colonia->id] ??= DB::table('resources')
            ->where('colony_id', $colonia->id)
            ->whereNotNull('storage_cap')
            ->pluck('storage_cap', 'resource_type')
            ->map(fn ($v) => (int) $v)
            ->all();

        return $pisos[$recurso] ?? 0;
    }

    /**
     * Quanto ainda cabe, dado o que já existe.
     *
     * ⚠️ Nunca negativo. Uma colônia **acima** do teto — o que o grandfathering vai encontrar, com
     * ~35 mil de água guardados contra 10.000 de teto — simplesmente não recebe mais nada. O estoque
     * que já existe **não é destruído**: o §14 promete que o jogador perde oportunidade, nunca
     * estoque. É a mesma forma do teto habitacional da população (D-178).
     */
    public function espacoLivre(Colony $colonia, string $recurso, int $jaTem): ?int
    {
        $teto = $this->capacidade($colonia, $recurso);

        return $teto === null ? null : max(0, $teto - $jaTem);
    }

    private function nivelDoDeposito(Colony $colonia): int
    {
        return $this->niveis[$colonia->id] ??= (int) $colonia->buildings()
            ->where('type', 'deposito_local')
            ->value('level');
    }
}
