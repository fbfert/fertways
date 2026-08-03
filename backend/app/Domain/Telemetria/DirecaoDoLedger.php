<?php

namespace App\Domain\Telemetria;

use App\Models\Ledger;

/**
 * Decide o que entra na conta de fluxo da telemetria (A2.0.1.1) — e o que fica fora.
 *
 * ## A direção vem do SINAL, não de uma tabela
 *
 * A primeira versão desta classe classificava cada tipo de lançamento em "entrada" ou "saída" a
 * partir de uma lista escrita à mão. Estava errada, e o erro é instrutivo: eu tinha conferido no
 * banco de desenvolvimento que **nenhum** dos 191 lançamentos era negativo, e concluí que o ledger
 * guardava valor absoluto com a direção implícita no tipo.
 *
 * O banco de dev só não tinha negativos porque só tinha entradas — subsídio, kit inicial, saldo
 * inicial. O **código** escreve saída como negativo em dezenove lugares
 * (`'amount' => -$qtd` em `EnqueueUpgrade`, `ComprarVeiculo`, `CobrarManutencaoTerritorial`…).
 *
 * Quer dizer que o sinal **já está no dado**, e reconstruí-lo por tabela era reinventar, com
 * palpite, uma informação que o ledger já carrega com precisão. Pior: um tipo mal classificado
 * somaria no balde errado sem nenhum sintoma.
 *
 * Então: **`amount > 0` é entrada, `amount < 0` é saída.** Ponto. Esta classe não opina sobre isso.
 *
 * ## O que ela decide, e que o sinal não resolve
 *
 * Alguns lançamentos não são criação nem destruição de valor — são **mudança de lugar** ou
 * **correção**. O escrow tira do depósito e prende numa ordem; a transferência é saída de um lado e
 * entrada do outro; o estorno desfaz um lançamento que já foi contado. Somá-los infla a economia
 * com valor que só andou de bolso, ou conta duas vezes o mesmo fato.
 *
 * Esses ficam de fora, e é só isso que esta classe resolve.
 *
 * ## Nenhum tipo pode ficar sem decisão
 *
 * `contaNoFluxo()` lança em tipo desconhecido em vez de devolver um default. Um `default => true`
 * faria todo tipo novo do ledger entrar mudo na conta, e o sintoma seria um número que muda sem
 * explicação meses depois. `TelemetriaTest` fecha o cerco pelo outro lado, exigindo que todo tipo
 * de `Ledger::TIPOS` esteja declarado num dos dois lados.
 */
class DirecaoDoLedger
{
    /**
     * Mudança de lugar ou correção: não é produção nem consumo, em nenhum sinal.
     *
     * @var list<string>
     */
    public const NAO_CONTA = [
        // Entre o depósito da colônia e a conta no Mercado da Capital (§25.8). O recurso continua
        // existindo e continua sendo do mesmo dono — só mudou de prateleira.
        'deposito_mercado',
        'retirada_mercado',
        'escrow_mercado',
        'escrow_leilao',
        // Compra no Mercado Central: o Fert$ sai e o recurso entra no mesmo ato, e a contrapartida
        // já aparece como `venda_mercado` do outro lado. Contar os dois lados dobraria o volume.
        'compra_mercado',
        'compra_leilao',
        // Entre colônias: saída de uma é entrada da outra. Somar as duas pontas inventa economia.
        'transferencia',
        // Desfaz um lançamento que já foi contado. Contá-lo contaria o mesmo fato duas vezes.
        'estorno',
        /*
         * O único valor do jogo que nasce sem origem econômica (D-61) — correção do operador, com
         * motivo escrito. Fica de fora da produção de propósito: misturá-lo esconderia justamente o
         * que ele tem de especial. Quando o painel (A2.0.2) quiser mostrá-lo, mostra em separado,
         * que é a única forma honesta.
         */
        'ajuste_admin',
    ];

    /** Cria ou destrói valor de verdade. A direção sai do sinal do `amount`. */
    public const CONTA = [
        'producao',
        'subsidio_governo',
        'saldo_inicial',
        'kit_inicial',
        'kit_recursos',
        'saque_de_guerra',
        'recompensa_missao',
        'venda_mercado',
        'venda_leilao',
        'venda_veiculo',
        'transferencia_tesouro',
        'saque_federacao',
        'salario_conciliador',
        'bonus_conciliador',
        'salario_cargo_civico',
        'bonus_cargo_civico',
        'devolucao_deposito',
        'custo_construcao',
        'tributo',
        'compra_niobio',
        'fabricar_unidade',
        'fabricar_drone',
        'custo_obra_zona',
        'energia_viagem',
        'custo_ocupacao',
        'manutencao_veiculo',
        'estacionamento',
        'custo_upgrade_zona',
        'manutencao_territorial',
        'reparo_de_modulo',
        'compra_veiculo',
        'compra_peca_endurance',
        'compra_item_endurance',
        'frete_publico',
        // A2.3: destrói recurso de verdade — vira conhecimento, que não é estoque.
        'custo_pesquisa',
        // A2.7: destrói recurso de verdade — vira capacidade de carga.
        'upgrade_veiculo',

        // A2.10: sai da colônia e entra no fundo — é fluxo econômico de verdade.
        'contribuicao_fundo',
    ];

    public function contaNoFluxo(string $tipo): bool
    {
        if (in_array($tipo, self::NAO_CONTA, true)) {
            return false;
        }

        if (in_array($tipo, self::CONTA, true)) {
            return true;
        }

        /*
         * Sem default de propósito. Um tipo novo do ledger tem que quebrar aqui, alto e agora, e
         * não entrar mudo numa soma que ninguém vai reconferir.
         */
        throw new \RuntimeException(
            "Tipo de ledger sem decisão declarada: {$tipo}. ".
            'Declare em DirecaoDoLedger::CONTA ou ::NAO_CONTA — ver o docblock da classe.'
        );
    }

    /** Todo tipo do ledger está declarado? Usado pelo teste de especificação. */
    public function naoClassificados(): array
    {
        $conhecidos = array_merge(self::CONTA, self::NAO_CONTA);

        return array_values(array_diff(Ledger::TIPOS, $conhecidos));
    }
}
