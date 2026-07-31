<?php

namespace App\Domain\Telemetria;

use App\Models\Ledger;
use RuntimeException;

/**
 * Diz, para cada tipo de lançamento do ledger, se ele **entra** ou **sai** da colônia (A2.0.1.1).
 *
 * ## Por que esta classe precisa existir
 *
 * O ledger guarda `amount` sempre **positivo** — conferido no banco: nenhuma linha negativa em 191.
 * A direção não está no número, está no **tipo**. Quer dizer que agregar produção e consumo exige
 * uma arbitragem explícita, tipo a tipo, e que errar aqui não produz um erro visível: produz um
 * gráfico plausível e falso, que só se descobre errado quando alguém tomar uma decisão de
 * balanceamento em cima dele.
 *
 * ## O balde INDEFINIDO não é preguiça
 *
 * Oito tipos são genuinamente ambíguos, e o que eles têm em comum é não serem criação nem
 * destruição de valor — são **mudança de lugar**. O escrow tira do depósito e prende na ordem, sem
 * nada se produzir nem se consumir; a `transferencia` é saída de um lado e entrada do outro; o
 * `estorno` tem o sinal do lançamento que desfaz; o `ajuste_admin` é o único delta com sinal de
 * verdade do jogo.
 *
 * Contá-los como produção infla a economia com dinheiro que só andou de bolso. Contá-los como
 * consumo faz o mesmo ao contrário. Então eles **não entram na conta**, e o agregador relata
 * quantos ficaram de fora — para o buraco ser visível em vez de silencioso.
 *
 * ⚠️ **Isto é arbitragem, e está esperando confirmação do usuário.** A regra de ouro da casa é não
 * inventar; quando o GDD não decide, pergunta-se. Enquanto não houver decisão, ficar de fora é o
 * único erro que não mente.
 *
 * ## Nenhum tipo pode ficar sem classificação
 *
 * `classificar()` lança em tipo desconhecido, em vez de devolver um default. É deliberado: um
 * `default => neutro` faria todo tipo novo do ledger entrar mudo na telemetria, e o sintoma seria
 * um número que encolhe sem explicação meses depois. `TelemetriaSpecsTest` fecha o cerco pelo outro
 * lado, exigindo que todo tipo de `Ledger::TIPOS` esteja num dos três baldes.
 */
class DirecaoDoLedger
{
    /** Valor que ENTRA na colônia — foi produzido, recebido ou ganho. */
    public const ENTRADA = [
        'producao',
        'subsidio_governo',
        'saldo_inicial',
        'kit_inicial',
        'kit_recursos',
        'saque_de_guerra',
        'recompensa_missao',
        'venda_mercado',
        'venda_leilao',
        // Apesar do nome: o comentário do Ledger diz "recurso creditado ao arrematante". Quem
        // compra num leilão RECEBE o lote — o Fert$ dele já saiu antes, no `escrow_leilao`.
        'compra_leilao',
        'venda_veiculo',
        'transferencia_tesouro',
        'saque_federacao',
        'salario_conciliador',
        'bonus_conciliador',
        'salario_cargo_civico',
        'bonus_cargo_civico',
        // Carga que não coube no teto do depósito e voltou na carroceria (D-58): volta a ser da
        // colônia, e sem tributo — não foi entregue.
        'devolucao_deposito',
    ];

    /** Valor que SAI da colônia — foi gasto, pago ou perdido. */
    public const SAIDA = [
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
        // §07/D-76: o frete do serviço logístico público sai do colono e vai ao Tesouro.
        'frete_publico',
    ];

    /**
     * Mudança de lugar, não criação nem destruição. Fora da conta até haver arbitragem.
     *
     * @var list<string>
     */
    public const INDEFINIDO = [
        // Entre o depósito da colônia e a conta no Mercado da Capital. O recurso continua existindo
        // e continua sendo do mesmo dono — só mudou de prateleira (§25.8).
        'deposito_mercado',
        'retirada_mercado',
        'escrow_mercado',
        'escrow_leilao',
        // "compra" no Mercado Central: o Fert$ sai e o recurso entra, no mesmo ato. Uma linha só
        // não diz qual dos dois lados ela é.
        'compra_mercado',
        // Entre colônias: saída de uma é entrada da outra. O sinal depende do lado que se olha.
        'transferencia',
        // O único delta COM SINAL do jogo (D-61) — pode dar e pode tirar.
        'ajuste_admin',
        // Carrega o sinal do lançamento que desfaz, e não um sinal próprio.
        'estorno',
    ];

    /** @return 'entrada'|'saida'|'indefinido' */
    public function classificar(string $tipo): string
    {
        if (in_array($tipo, self::ENTRADA, true)) {
            return 'entrada';
        }

        if (in_array($tipo, self::SAIDA, true)) {
            return 'saida';
        }

        if (in_array($tipo, self::INDEFINIDO, true)) {
            return 'indefinido';
        }

        /*
         * Sem default de propósito. Um tipo novo do ledger tem que quebrar aqui, alto e agora, e não
         * entrar mudo numa soma que ninguém vai reconferir.
         */
        throw new RuntimeException(
            "Tipo de ledger sem direção declarada: {$tipo}. ".
            'Classifique em DirecaoDoLedger antes de agregar — ver o docblock da classe.'
        );
    }

    /** Todo tipo do ledger está classificado? Usado pelo teste de especificação. */
    public function naoClassificados(): array
    {
        $conhecidos = array_merge(self::ENTRADA, self::SAIDA, self::INDEFINIDO);

        return array_values(array_diff(Ledger::TIPOS, $conhecidos));
    }
}
