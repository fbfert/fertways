<?php

namespace App\Domain\Pesquisa;

use App\Domain\Endurance\EfeitosDaEndurance;
use App\Models\Colony;
use Illuminate\Support\Facades\DB;

/**
 * O que as tecnologias concluídas fazem pela colônia (A2.3).
 *
 * ## Mesmo vocabulário do `EfeitosDaEndurance`, de propósito
 *
 * `producao_bonus`, `desconto_tributo`, `velocidade_veiculo`, `capacidade_veiculo`, `drone_raio`,
 * `drone_bateria` — as mesmas chaves, em pontos-base, com **os mesmos tetos agregados**.
 *
 * Criar um vocabulário paralelo seria o erro fácil e caro: duas fontes de bônus para a mesma coisa,
 * com regras diferentes, e o teto de uma sem conhecer a outra. Um colono com peça da Endurance e
 * tecnologia pesquisada estouraria qualquer limite que o desenho pretendesse impor, e o sintoma
 * apareceria como "produção estranha" meses depois.
 *
 * ## Só conta o que está CONCLUÍDO
 *
 * Pesquisa em andamento não dá efeito nenhum. Parece óbvio e não é: seria fácil somar tudo o que a
 * colônia tem em `colony_technologies` e entregar bônus por pesquisa começada — o que tornaria
 * ótimo iniciar tudo e nunca terminar nada.
 *
 * ## E o efeito é o do NÍVEL ATUAL
 *
 * Uma tecnologia de nível 3 dá o efeito do nível 3, não a soma de 1+2+3. Mesma regra do requisito de
 * operador na A2.2: somar a escada faz o número explodir com o progresso.
 */
class EfeitosDaPesquisa
{
    /**
     * Soma, em bps, de um tipo de efeito — respeitando o teto que a Endurance já define.
     *
     * @param  list<string>  $alvos  quais alvos aceitar (o tipo do prédio, do veículo, ou `global`)
     */
    public function somaBps(Colony $colonia, string $tipoEfeito, array $alvos): int
    {
        $linhas = DB::table('colony_technologies')
            ->join('technologies', 'technologies.id', '=', 'colony_technologies.technology_id')
            ->where('colony_technologies.colony_id', $colonia->id)
            ->where('colony_technologies.status', 'concluida')
            ->where('technologies.ativa', true)
            ->get(['technologies.efeitos_json', 'colony_technologies.nivel']);

        $soma = 0;

        foreach ($linhas as $l) {
            foreach (json_decode($l->efeitos_json ?? '[]', true) ?: [] as $efeito) {
                if (($efeito['tipo'] ?? null) !== $tipoEfeito) {
                    continue;
                }

                $alvo = $efeito['alvo'] ?? EfeitosDaEndurance::ALVO_GLOBAL;

                if (! in_array($alvo, $alvos, true) && $alvo !== EfeitosDaEndurance::ALVO_GLOBAL) {
                    continue;
                }

                /*
                 * `valor_bps` é POR NÍVEL, e multiplica pelo nível atual. É o que permite uma
                 * tecnologia de vários níveis ter progressão sem cadastrar uma linha por nível — e
                 * mantém a regra de que o efeito é o do nível atual, não a soma da escada.
                 */
                $soma += (int) ($efeito['valor_bps'] ?? 0) * max(1, (int) $l->nivel);
            }
        }

        /*
         * O teto é o mesmo da Endurance, e é aplicado aqui **por fonte**. ⚠️ Isso significa que a
         * soma das duas fontes ainda pode passar do teto individual — quem quiser um teto conjunto
         * precisa somá-las antes de limitar, no consumidor. Está anotado porque é o tipo de coisa
         * que se descobre tarde: cada fonte respeita o limite e o total não.
         */
        // Teto unificado: resolve tanto o vocabulário da Endurance quanto o próprio da pesquisa.
        return min($soma, Efeitos::tetoBps($tipoEfeito));
    }

    /**
     * O bônus de produção de TODAS as tecnologias concluídas, agrupado por alvo (A2.3).
     *
     * ⚠️ **Sem teto aqui, de propósito** — e isto responde ao aviso deixado em `somaBps()`: quem
     * consome soma esta fonte com a da Endurance e aplica o teto **uma vez sobre o total**. Cada
     * fonte se limitar sozinha permitiria que duas de 30% dessem 60%, e o teto deixaria de limitar
     * o que existe para limitar.
     *
     * Uma consulta em lote, e não uma por construção erguida: o tick chama isto por colônia a cada
     * minuto, e é o mesmo espírito anti-N+1 de `EfeitosDaEndurance::bonusDeProducaoPorAlvo()`.
     *
     * `efeitos_json` é JSON e não uma tabela de efeitos como a da Endurance, então a soma acontece
     * em PHP — o número de tecnologias concluídas por colônia é pequeno por construção (o catálogo
     * tem oito), e trocar isso por SQL de JSON custaria legibilidade sem ganhar nada.
     *
     * @return array<string,int> `building_type` (ou `'global'`) => soma de bps, SEM teto
     */
    public function bonusDeProducaoPorAlvo(Colony $colonia): array
    {
        $linhas = DB::table('colony_technologies')
            ->join('technologies', 'technologies.id', '=', 'colony_technologies.technology_id')
            ->where('colony_technologies.colony_id', $colonia->id)
            ->where('colony_technologies.status', 'concluida')
            ->get(['technologies.efeitos_json', 'colony_technologies.nivel']);

        $porAlvo = [];

        foreach ($linhas as $l) {
            foreach (json_decode($l->efeitos_json ?? '[]', true) ?: [] as $efeito) {
                if (($efeito['tipo'] ?? null) !== EfeitosDaEndurance::PRODUCAO_BONUS) {
                    continue;
                }

                $alvo = (string) ($efeito['alvo'] ?? EfeitosDaEndurance::ALVO_GLOBAL);

                // Por NÍVEL, como em `somaBps()`: o efeito é o do nível atual, não a soma da escada.
                $porAlvo[$alvo] = ($porAlvo[$alvo] ?? 0)
                    + (int) ($efeito['valor_bps'] ?? 0) * max(1, (int) $l->nivel);
            }
        }

        return $porAlvo;
    }

    public function bonusDeProducao(Colony $colonia, string $buildingType): int
    {
        return $this->somaBps($colonia, EfeitosDaEndurance::PRODUCAO_BONUS, [$buildingType]);
    }

    public function descontoDeTributo(Colony $colonia): int
    {
        return $this->somaBps($colonia, EfeitosDaEndurance::DESCONTO_TRIBUTO, [EfeitosDaEndurance::ALVO_GLOBAL]);
    }

    /**
     * Quanto a trilha de Ciência encurta as pesquisas seguintes (A2.3).
     *
     * É o efeito natural daquela trilha: ela não produz recurso, produz **conhecimento mais rápido**.
     * Antes disso ela dava bônus de produção ao Laboratório, que não produz nada — inerte por
     * construção, e foi o simulador que mostrou.
     */
    public function descontoDeDuracao(Colony $colonia): int
    {
        return $this->somaBps($colonia, Efeitos::DURACAO_PESQUISA, [EfeitosDaEndurance::ALVO_GLOBAL]);
    }
}
