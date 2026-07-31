<?php

namespace App\Domain\Populacao;

use Illuminate\Support\Facades\DB;

/**
 * Os parâmetros de população (A2.2), lidos da tabela — nunca hardcodados.
 *
 * ⚠️ **Todos são HIPÓTESE.** Nenhum saiu de simulação, e o `BALANCEAMENTO.md` §7.1 os lista como
 * PENDENTE. O critério de saída da fase proíbe promovê-los sem uma rodada registrada do simulador
 * da trilha A2.S. Enquanto `ativo` for `false`, eles só servem ao simulador.
 *
 * Cache de uma leitura por instância: o tick chama isto por colônia, e reler a mesma linha
 * cinquenta vezes por minuto seria desperdício sem propósito.
 */
class Parametros
{
    private ?object $linha = null;

    public function todos(): object
    {
        return $this->linha ??= DB::table('population_settings')->find(1)
            ?? throw new \RuntimeException(
                'population_settings sem a linha 1 — a migration a insere; se sumiu, alguém a apagou à mão.'
            );
    }

    public function ativo(): bool
    {
        return (bool) $this->todos()->ativo;
    }

    /**
     * Capacidade habitacional de uma Estrutura de Sobrevivência no nível dado.
     *
     * `base × fator^(nível−1)`, com o fator em milésimos para não depender de ponto flutuante em
     * dinheiro de jogo. Nível 0 (não construída) é zero: sem habitação não há colono.
     *
     * ⚠️ Esta é a chave com a restrição extra do §7.1: na migração de grandfathering, a capacidade
     * de cada colônia existente precisa **caber** a população concedida. Um valor baixo demais faria
     * veteranas nascerem acima do próprio teto.
     */
    public function capacidade(int $nivel): int
    {
        if ($nivel < 1) {
            return 0;
        }

        $p = $this->todos();
        $valor = (float) $p->capacidade_base;

        for ($i = 1; $i < $nivel; $i++) {
            $valor = $valor * $p->capacidade_fator_milesimos / 1000;
        }

        return (int) floor($valor);
    }

    /** Operadores exigidos por uma zona neutra no nível dado (§7.4: pequeno, por princípio). */
    public function operadoresDeZona(int $nivel): int
    {
        $mapa = json_decode($this->todos()->zona_operadores_por_nivel ?? '{}', true) ?: [];

        return (int) ($mapa[$nivel] ?? $mapa[(string) $nivel] ?? 0);
    }

    /**
     * Consumo por colono por hora, por recurso, em milésimos de unidade.
     *
     * @return array<string,int>
     */
    public function consumoMilliPorColonoHora(): array
    {
        $p = $this->todos();

        return [
            'agua' => (int) $p->agua_milli_por_colono_hora,
            'oxigenio' => (int) $p->oxigenio_milli_por_colono_hora,
            'biomassa' => (int) $p->biomassa_milli_por_colono_hora,
            'energia' => (int) $p->energia_milli_por_colono_hora,
        ];
    }

    /** Esquece o cache — o simulador muda parâmetros entre rodadas. */
    public function recarregar(): void
    {
        $this->linha = null;
    }
}
