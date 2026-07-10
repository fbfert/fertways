<?php

namespace App\Domain\Ministry;

use App\Domain\Trade\AcordoSpecs;
use App\Models\Colony;

/**
 * A tabela fixa de punições que o §26.8 exige — e que o GDD nunca publicou.
 *
 * O §26.8 manda usar uma "tabela fixa de punições por tipo de violação confirmada", que "elimina
 * decisão totalmente subjetiva", e remete ao §9.4. O §9.4 publica os cinco **nomes** das punições e
 * nada mais: "Pontos deduzidos da reputação geral. **Configurável**", silêncio "por X horas/dias",
 * restrição comercial "por X dias".
 *
 * Seguir o §26.8 foi **construir** a tabela. Ela não existia para ser seguida. Ver docs/decisoes.md
 * D-49: os tipos de violação vêm cada um de um parágrafo; as gravidades são arbitragem do usuário.
 * **Não mude um número aqui sem perguntar.**
 *
 * A "reputação geral" do §9.4 é texto morto (D-48): toda redução nomeia o índice do §26.2 que
 * atinge, e o §26.9 proíbe compensar entre índices.
 */
final class PunicaoSpecs
{
    /** As punições nomeadas pelo §9.4. `bloqueio_leiloes` não está aqui: é estado, não sentença. */
    public const ADVERTENCIA = 'advertencia';

    public const REDUCAO = 'reducao';

    public const SILENCIO = 'silencio';

    public const RESTRICAO_COMERCIAL = 'restricao_comercial';

    /** D-49: as três gravidades, ancoradas no −50 que um calote de Acordo custa (D-43). */
    public const LEVE = 25;

    public const GRAVE = 100;

    public const GRAVISSIMA = 250;

    /** D-49. O §9.4 diz "X horas/dias" e "X dias", e nunca preenche o X. */
    public const SILENCIO_HORAS = 24;

    /**
     * 7 dias, e o 7 não é arbitrário: o §14.3 exige "não ter tido restrição comercial nos últimos
     * 7 dias" para se candidatar a cargo. É a janela que o próprio GDD trata como a memória de uma
     * restrição.
     */
    public const RESTRICAO_DIAS = 7;

    /**
     * §9.4: "Reputação negativa bloqueia acesso a leilões da Endurance" — mas a escala do §26.2 vai
     * de 0 a 1000 e não tem negativo. Persona Non Grata é Confiança Comercial abaixo do mesmo
     * limiar que já fecha o Mercado Central (D-43, D-49). Um número, um conceito.
     *
     * Inerte: leilões não existem. `AcessoAoMercado` já usa o mesmo limiar para a doca.
     */
    public const PERSONA_NON_GRATA = AcordoSpecs::LIMIAR_MERCADO;

    /** §26.8: "Conciliador tem 48 horas para decidir um caso atribuído, ou ele é reatribuído". */
    public const PRAZO_ANALISE_HORAS = 48;

    /**
     * D-50: a janela de apelação espelha o único prazo que o §26.8 publica. Quem julga tem 48 h,
     * quem apela tem 48 h. O bônus do §26.7 só cai quando ela fecha sem reversão.
     */
    public const JANELA_APELACAO_HORAS = 48;

    /** §26.8, impedimento: "transação comercial nos últimos 30 dias" com qualquer das partes. */
    public const IMPEDIMENTO_DIAS = 30;

    /** §26.7: salário fixo diário, "independente do volume de casos". */
    public const SALARIO_DIARIO_MICRO = 50 * Colony::MICRO_POR_FERT;

    /** §26.7: "+3 Fert$ apenas se a decisão NÃO for revertida em apelação pela equipe". */
    public const BONUS_MICRO = 3 * Colony::MICRO_POR_FERT;

    /** D-44: o §26.7 diz "limite configurável de reversões" e não publica o número. */
    public const LIMITE_REVERSOES = 5;

    /**
     * §26.8, evidência mínima obrigatória. `print_de_chat` é gravável e inerte: não há chat nem
     * upload. Denúncia sem evidência é rejeitada automaticamente na triagem.
     */
    public const EVIDENCIAS = ['acordo_expirado', 'log_transacao', 'print_de_chat'];

    /**
     * O mapa do D-49. Cada `violacao` vem de um parágrafo; a gravidade é a arbitragem.
     *
     * - `indice`: qual dos quatro índices do §26.2 a redução atinge. O §26.2 já diz qual, pela
     *   coluna "o que mede" — não há escolha aqui.
     * - `punicoes`: as sentenças do §9.4 que a violação dispara, todas de uma vez.
     * - `grave`: §9.2, "caso grave vai direto para a equipe". Grave é o que custa −250 (D-50).
     * - `inerte`: depende de sistema que não existe. Grava e não morde. Ver D-44.
     *
     * O calote de Acordo de Troca **não** está aqui: ele custa −50 automaticamente, sem juízo nem
     * denúncia (D-43, `Trade\Reputacao`). O que se denuncia é o calote **reincidente**.
     *
     * @var array<string, array{indice: string, pontos: int, punicoes: array<string>, grave: bool, inerte: bool, fonte: string}>
     */
    public const VIOLACOES = [
        'avaliacao_injusta' => [
            'indice' => 'confianca_comercial',
            'pontos' => 0,
            'punicoes' => [self::ADVERTENCIA],
            'grave' => false,
            'inerte' => true, // avaliações de 0 a 5 estrelas não existem
            'fonte' => '§8.4',
        ],
        'fraude_de_avaliacao' => [
            'indice' => 'confianca_comercial',
            'pontos' => -self::GRAVISSIMA,
            'punicoes' => [self::REDUCAO],
            'grave' => true,
            'inerte' => false,
            'fonte' => '§26.4',
        ],
        'sonegacao' => [
            'indice' => 'status_civico',
            'pontos' => -self::GRAVISSIMA,
            'punicoes' => [self::REDUCAO],
            'grave' => true,
            'inerte' => false,
            'fonte' => '§9.5',
        ],
        'abuso_em_chat' => [
            'indice' => 'conduta_social',
            'pontos' => -self::LEVE,
            'punicoes' => [self::SILENCIO, self::REDUCAO],
            'grave' => false,
            'inerte' => true, // o silêncio precisa de chat para morder
            'fonte' => '§26.2',
        ],
        'reincidencia_em_chat' => [
            'indice' => 'conduta_social',
            'pontos' => -self::GRAVE,
            'punicoes' => [self::SILENCIO, self::REDUCAO],
            'grave' => false,
            'inerte' => true,
            'fonte' => '§26.2',
        ],
        'ataque_a_aliado' => [
            'indice' => 'honra_militar_diplomatica',
            'pontos' => -self::GRAVE,
            'punicoes' => [self::REDUCAO],
            'grave' => false,
            'inerte' => true, // alianças registradas não existem
            'fonte' => '§9.5',
        ],
        'quebra_de_tratado' => [
            'indice' => 'honra_militar_diplomatica',
            'pontos' => -self::GRAVE,
            'punicoes' => [self::REDUCAO],
            'grave' => false,
            'inerte' => true, // tratados não existem
            'fonte' => '§26.2',
        ],
        'calote_reincidente' => [
            'indice' => 'confianca_comercial',
            'pontos' => -self::GRAVE,
            'punicoes' => [self::RESTRICAO_COMERCIAL, self::REDUCAO],
            'grave' => false,
            'inerte' => false,
            'fonte' => '§26.5 + §9.4',
        ],
    ];

    /** @return array{indice: string, pontos: int, punicoes: array<string>, grave: bool, inerte: bool, fonte: string} */
    public static function violacao(string $chave): array
    {
        return self::VIOLACOES[$chave];
    }

    public static function existe(string $chave): bool
    {
        return array_key_exists($chave, self::VIOLACOES);
    }

    /** Quanto tempo a punição morde. NULL quando ela é instantânea (redução, advertência). */
    public static function duracaoHoras(string $punicao): ?int
    {
        return match ($punicao) {
            self::SILENCIO => self::SILENCIO_HORAS,
            self::RESTRICAO_COMERCIAL => self::RESTRICAO_DIAS * 24,
            default => null,
        };
    }
}
