<?php

namespace App\Domain\Cargos;

use App\Domain\Ministry\PunicaoSpecs;
use App\Models\Colony;

/**
 * Cargos Públicos, §14.2 (D-130) — os 4 que sobravam depois do Conciliador (§9, D-49/D-50).
 *
 * O §14.2 só existe nas revisões arquivadas do GDD v35, e duas se contradizem sobre elegibilidade
 * (ver docblock da migration `cargos_civicos`). Seguimos a v32, a mesma leitura que o D-50 já deu
 * ao Conciliador: "Neutro Registrado é exclusivo do Conciliador; demais contratos têm limites".
 *
 * **Nenhum dos dois números do §14.2 é publicado** ("salário fixo/dia", "bônus por X", sem valor em
 * nenhuma revisão). Em vez de inventar um número solto, reusamos os do Conciliador (§26.7) — é o
 * único valor que o GDD publica para cargo cívico, e a v32 descreve os 5 cargos como do mesmo porte
 * ("remuneração moderada", "nenhum cargo concede Fert$ suficiente para distorcer a economia").
 *
 * O Atendente do Espaçoporto (5º cargo do §14.2) NÃO está aqui — ver D-130: 100% dependente do
 * Espaçoporto, que não existe. Não há nada para nomear alguém "atender".
 */
final class CargosCivicosSpecs
{
    public const REPORTER = 'reporter';

    public const FISCAL_DE_MERCADO = 'fiscal_de_mercado';

    public const AUXILIAR_DE_TESOURO = 'auxiliar_de_tesouro';

    public const KINDS = [self::REPORTER, self::FISCAL_DE_MERCADO, self::AUXILIAR_DE_TESOURO];

    public const NOMES = [
        self::REPORTER => 'Repórter',
        self::FISCAL_DE_MERCADO => 'Fiscal de Mercado',
        self::AUXILIAR_DE_TESOURO => 'Auxiliar de Tesouro',
    ];

    /**
     * §14.2 (v32): o índice de reputação que cada cargo exige "alto" — sem número, então sem gate
     * automático (mesmo caminho do Conciliador: "enquanto não houver substrato para a elegibilidade,
     * o cargo é ligado à mão pelo operador", D-44). Documentado aqui só para o painel/artisan
     * mostrarem, não para bloquear nomeação.
     */
    public const INDICE_ESPERADO = [
        self::REPORTER => 'conduta_social',
        self::FISCAL_DE_MERCADO => 'confianca_comercial',
        self::AUXILIAR_DE_TESOURO => 'status_civico',
    ];

    /** §26.7, reusado: único salário de cargo cívico que o GDD publica. */
    public const SALARIO_DIARIO_MICRO = PunicaoSpecs::SALARIO_DIARIO_MICRO;

    /** §26.7, reusado: único bônus de cargo cívico que o GDD publica. */
    public const BONUS_MICRO = PunicaoSpecs::BONUS_MICRO;

    /**
     * v32: "remuneração moderada, com teto semanal" — sem número. Arbitrado um pouco acima do
     * salário-base de 7 dias (350 Fert$), com espaço para uma matéria ou sinalização confirmada
     * a mais na semana, sem deixar o bônus empilhar sem limite.
     */
    public const TETO_SEMANAL_MICRO = 400 * Colony::MICRO_POR_FERT;
}
