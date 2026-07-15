<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Ledger append-only (GDD, seção 0, "Arquitetura de dados": "ledger append-only").
 *
 * Sem updated_at e sem deleted_at no schema. Aqui bloqueamos update e delete no nível do
 * Model, para que a invariante não dependa de disciplina de quem escreve o código.
 * Estorno é um lançamento novo de sinal contrário, tipo `estorno`.
 */
class Ledger extends Model
{
    protected $table = 'ledger';

    public $timestamps = false;

    protected $fillable = ['colony_id', 'type', 'amount', 'resource_type', 'ref', 'created_at'];

    protected $casts = ['amount' => 'integer', 'created_at' => 'datetime'];

    public const TIPOS = [
        'producao',
        'custo_construcao',
        'subsidio_governo',   // §24.7: 100% das cinco essenciais até o nível 3
        'tributo',
        'venda_mercado',
        'compra_mercado',
        'transferencia',
        'saldo_inicial',      // 50 Fert$ de onboarding
        'kit_inicial',        // raros concedidos na fundação — decisão de design, D-17
        /*
         * §27.8 e §28.10 (D-66): o butim de uma invasão (50% do exposto) ou de um cerco vencido
         * (30%). É a única entrada de recurso que não tem contrapartida econômica nenhuma — não se
         * produziu, não se comprou, não se recebeu do governo. Alguém perdeu exatamente o que
         * entrou aqui. Por isso fica no ledger: é a única forma de medir quanto a guerra move.
         */
        'saque_de_guerra',
        'compra_niobio',      // §D-17 "contratos do governo": sem isto a Sentinela é inalcançável
        'fabricar_unidade',   // Sentinela, Infiltrador, Predador, Robô — feitos no Quartel (§27.1)
        'fabricar_drone',     // o Drone de Exploração — feito na OFICINA (§21.4, D-74)
        'frete_publico',      // o Fert$ do serviço logístico público (§07, D-76) — vai ao Tesouro
        'recompensa_missao',  // Fert$/recursos de missão (§06, D-78) — emissão publicada, como o salário
        /*
         * §17.4 (D-67): o material que sai da colônia num veículo rumo ao CANTEIRO de uma zona.
         * As obras da zona exigem entrega física — e é este lançamento que prova de onde veio o
         * Metal Bruto que virou muralha a 40 slots de casa.
         */
        'custo_obra_zona',
        'energia_viagem',     // §21.1: consumo do veículo por distância percorrida
        'deposito_mercado',   // §25.8: carga entregue entra na conta do colono no Mercado
        'retirada_mercado',   // §25.8: saldo reservado no Mercado para um veículo vir buscar
        'escrow_mercado',     // §07: recurso ou Fert$ reservado ao abrir uma ordem no livro
        'salario_conciliador', // §26.7: 50 F$/dia, emitidos pelo Governo (D-50)
        'bonus_conciliador',   // §26.7: +3 F$ por decisão que sobrevive à apelação
        'custo_ocupacao',      // §07: Posto de Comando + Robôs Mineradores para ocupar zona (D-52)
        'transferencia_tesouro', // Ministério do Tesouro: distribuição do governo ao colono (D-57)
        'kit_recursos',        // kit fixo de recursos por colônia, emissão do governo (D-57)
        'devolucao_deposito',  // carga que não coube no teto do depósito e voltou no veículo (D-58)
        'compra_veiculo',      // §16: Caminhão comprado do Ministério, ou um usado de outro colono (D-60)
        'venda_veiculo',       // §16.4: o vendedor recebe, quando o usado chega ao comprador (D-60)
        'manutencao_veiculo',  // §16.4: recursos gastos na Central de Transportes para reparar (D-60)
        'estacionamento',      // §2.1, slot 6: a hora do Pátio Logístico da Capital (D-65)
        'custo_upgrade_zona',   // §07: Metal Bruto/Fert$/Robôs para subir o nível de uma zona (D-84)
        'manutencao_territorial', // §27.12: custo diário por nível de zona ocupada (D-84)
        // Correção de estado feita pelo operador (D-61). É a ÚNICA coisa no jogo que cria valor sem
        // origem econômica — e por isso ela é obrigada a passar por aqui, com motivo escrito. O
        // `amount` é o delta, com sinal: uma correção também pode TIRAR o que um bug deu de graça.
        'ajuste_admin',
        'estorno',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $l) {
            if (! in_array($l->type, self::TIPOS, true)) {
                throw new RuntimeException("Tipo de lançamento inválido: {$l->type}");
            }
        });

        static::updating(fn () => throw new RuntimeException('ledger é append-only: use um lançamento de estorno'));
        static::deleting(fn () => throw new RuntimeException('ledger é append-only: não se apaga lançamento'));
    }
}
