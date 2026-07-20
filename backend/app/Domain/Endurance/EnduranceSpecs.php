<?php

namespace App\Domain\Endurance;

use App\Models\Colony;

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

    /** @var array<string,array{camada:string, marco_minimo:int, preco_micro:int, desconto_tributo_bps:int, unica:bool, rotulo:string}> */
    private const CAMADAS_SPEC = [
        self::COMUM => ['marco' => 10, 'preco' => 20, 'desconto' => 100, 'rotulo' => 'Fragmento'],
        self::REPUTACAO_1 => ['marco' => 35, 'preco' => 60, 'desconto' => 250, 'rotulo' => 'Relíquia'],
        self::REPUTACAO_2 => ['marco' => 75, 'preco' => 150, 'desconto' => 500, 'rotulo' => 'Artefato Raro'],
        self::UNICA => ['marco' => 100, 'preco' => 500, 'desconto' => 1000, 'rotulo' => 'Peça Única'],
    ];

    /** O teto agregado (D-132): mesmo colono não passa disto de desconto, por mais peças que tenha. */
    public const TETO_DESCONTO_BPS = 3000;

    /** Todo o catálogo, achatado — `peca_key` = `"{secao}:{camada}"`. */
    public static function catalogo(): array
    {
        $out = [];

        foreach (self::SECOES as $secaoChave => $secaoNome) {
            foreach (self::CAMADAS as $camada) {
                $spec = self::CAMADAS_SPEC[$camada];
                $chave = "{$secaoChave}:{$camada}";

                $out[$chave] = [
                    'chave' => $chave,
                    'secao' => $secaoChave,
                    'secao_nome' => $secaoNome,
                    'camada' => $camada,
                    'nome' => "{$secaoNome} — {$spec['rotulo']}",
                    'marco_minimo' => $spec['marco'],
                    'preco_micro' => $spec['preco'] * Colony::MICRO_POR_FERT,
                    'desconto_tributo_bps' => $spec['desconto'],
                    'unica' => $camada === self::UNICA,
                ];
            }
        }

        return $out;
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
