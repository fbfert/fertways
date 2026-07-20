<?php

namespace App\Domain\Endurance;

/**
 * A Loja de Peças da Endurance (D-132) — 8 seções do casco × as 4 camadas do §05.
 *
 * O GDD (v35, §05) liga peças históricas da Endurance à curva do Marco, e nomeia as 4 camadas, mas
 * **nunca publica** o que uma peça faz, quanto custa, nem a que seção pertence — as 8 seções são
 * arte (destrocos-da-endurance), não texto do documento:
 *
 *   marco 10  (Pioneiro)          peças comuns
 *   marco 35  (Construtor)        peças de reputação nível 1
 *   marco 75  (Guardião)          peças de reputação nível 2
 *   marco 100 (Lenda de Fertways) leilões de peças únicas
 *
 * Perguntei ao usuário três coisas antes de desenhar (2026-07-20): o que a peça faz (bônus real,
 * não só cosmético), como se adquire (Fert$, liberado pelo Marco) e o escopo (8 seções × 4 camadas
 * = 32 itens, não 8). As respostas definiram a forma; os NÚMEROS abaixo são arbitragem desta
 * sessão — sem base no GDD — documentada em docs/decisoes.md D-132. Mude aqui, não escondido.
 *
 * O bônus é desconto de tributo, não bônus de produção: reaproveita o MESMO ponto de extensão do
 * desconto de aliados (D-120, `intdiv($bpsCheio * (10_000 - $desconto), 10_000)`) em vez de abrir
 * uma segunda frente na fórmula de produção do `ColonyTick`, que atravessa 26 recursos.
 *
 * "Peça única" (marco 100) não é um leilão de verdade — o §9.4 chama de "leilões da Endurance", mas
 * o D-129 (Leilões) só vende `resource_type` fungível, não item de catálogo. Fica com escassez real
 * (só uma colônia no servidor pode comprar cada única) dentro da MESMA loja; integrar com D-129 é
 * extensão futura, não construída agora.
 *
 * **D-133**: o catálogo saiu do código e virou tabela (`endurance_piece_specs`) — o usuário pediu
 * um CRUD no painel para editar preço, marco e o efeito de cada peça. Esta classe continua sendo a
 * única porta de leitura (`catalogo()`/`existe()`/`peca()`), então `ComprarPeca`,
 * `DescontoDeEndurance` e `EnduranceController` não mudaram uma linha — só a fonte, por baixo,
 * trocou de constante para banco.
 */
final class EnduranceSpecs
{
    public const COMUM = 'comum';

    public const REPUTACAO_1 = 'reputacao_1';

    public const REPUTACAO_2 = 'reputacao_2';

    public const UNICA = 'unica';

    public const CAMADAS = [self::COMUM, self::REPUTACAO_1, self::REPUTACAO_2, self::UNICA];

    /** As 8 seções do casco — mesmas chaves de `Vinculaveis::SECOES_DA_ENDURANCE`, sem o prefixo. */
    public const SECOES = [
        'anel_habitacional' => 'Anel Habitacional',
        'baia_criogenica' => 'Baía Criogênica',
        'comando' => 'Comando',
        'matriz_comunicacao' => 'Matriz de Comunicação',
        'modulo_medico' => 'Módulo Médico',
        'nucleo_propulsao' => 'Núcleo de Propulsão',
        'secao_acoplagem' => 'Seção de Acoplagem',
        'silo_suprimentos' => 'Silo de Suprimentos',
    ];

    /** O teto agregado (D-132): mesmo colono não passa disto de desconto, por mais peças que tenha. */
    public const TETO_DESCONTO_BPS = 3000;

    /** Todo o catálogo, achatado — `peca_key` = `"{secao}:{camada}"`. Lido do banco (D-133). */
    public static function catalogo(): array
    {
        return \App\Models\EndurancePieceSpec::all()
            ->mapWithKeys(fn ($p) => [$p->peca_key => [
                'chave' => $p->peca_key,
                'secao' => $p->secao,
                'secao_nome' => $p->secao_nome,
                'camada' => $p->camada,
                'nome' => $p->nome,
                'marco_minimo' => $p->marco_minimo,
                'preco_micro' => $p->preco_micro,
                'desconto_tributo_bps' => $p->desconto_tributo_bps,
                'unica' => $p->unica,
            ]])
            ->all();
    }

    public static function existe(string $pecaKey): bool
    {
        return array_key_exists($pecaKey, self::catalogo());
    }

    public static function peca(string $pecaKey): array
    {
        return self::catalogo()[$pecaKey];
    }
}
