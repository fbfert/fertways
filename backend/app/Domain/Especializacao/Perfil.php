<?php

namespace App\Domain\Especializacao;

use App\Domain\Production\Siderurgica;
use App\Models\Colony;
use Illuminate\Support\Facades\DB;

/**
 * O perfil da colônia (A2.4) — **calculado, nunca declarado**.
 *
 * ## Por que não existe "escolher ser agrícola"
 *
 * O GDD ALPHA 2 §8.1 é categórico: **especialização é a trilha de pesquisa**, mais o que se
 * construiu. Uma segunda camada declarada por cima faria dois sistemas disputarem o mesmo papel, e
 * traria junto o problema do "posso trocar?" — respec, custo de troca, e o abuso de mudar de perfil
 * na véspera de cada evento.
 *
 * **Contrapartida obrigatória, e é o que esta classe existe para cumprir:** como a especialização
 * não é declarada, ela precisa ser **exibida**. O jogo calcula o perfil e mostra ao colono o que ele
 * ganha e do que passa a depender. Leitura derivada, nunca campo preenchido.
 *
 * ## A auditoria que a fase pedia
 *
 * "Auditar as especializações já existentes" tem uma resposta curta: **elas são as cinco construções
 * repetíveis**. `Building::REPETIVEIS` — Mina, Oficina, Refinaria, Destilaria e Siderúrgica — e o
 * comentário lá já dizia, desde o D-59, que "repetir é estratégia econômica (especializar a colônia
 * em metal, em química) e não truque". O mecanismo existia e nunca foi lido nem mostrado.
 *
 * ## De onde sai a dependência
 *
 * Das conversões que o `Funcoes::CATALOGO` **já declara**, não de invenção: a Refinaria converte
 * Metal Bruto, Água, Biomassa e Energia; a Destilaria converte 2 Biomassa + 3 Energia; a Siderúrgica
 * converte Metal Bruto; a Oficina converte minerais eletrônicos.
 *
 * ⚠️ **E o monopólio dos minerais eletrônicos não é total** — eu afirmei que era, e estava errado. O
 * §4.3 diz que "jogadores não extraem esses minerais", mas a **Indústria Siderúrgica (D-82)
 * contraria o texto de propósito**: ela produz cinco deles (alumínio, cobre, estanho, ouro,
 * tungstênio) a partir de Metal Bruto. É arbitragem consciente do usuário, da mesma família do
 * tributo (D-32). Quem depende de silício continua dependendo do Governo; quem depende dos outros
 * cinco tem uma saída, e ela custa slots.
 */
class Perfil
{
    /**
     * O que cada prédio consome para produzir. O que ele PRODUZ sai de `building_specs`, que é
     * gerado do GDD — só o consumo precisa estar aqui, porque o catálogo o descreve em prosa.
     *
     * @var array<string,list<string>>
     */
    private const CONSOME = [
        'refinaria_quimica' => ['metal_bruto', 'agua', 'biomassa', 'energia'],
        'destilaria' => ['biomassa', 'energia'],
        'industria_siderurgica' => ['metal_bruto', 'energia'],
        /*
         * A Oficina depende de minerais eletrônicos. Deles, **silício** é o único que nenhum prédio
         * do jogo produz — a Siderúrgica faz os outros quatro que a Oficina pede. Então esta é a
         * dependência verdadeiramente estrutural, e é UMA, não oito.
         */
        'oficina' => ['estanho', 'cobre', 'silicio', 'aluminio', 'agua', 'energia'],
    ];

    /** Energia é debitada por TODA construção erguida, produza ela o que produzir. */
    private const CONSUMO_UNIVERSAL = 'energia';

    /**
     * @return array{producao: array<string,float>, vocacao: ?string, forca_pct: int,
     *               depende_de: list<string>, trilhas: list<string>, repetidas: array<string,int>}
     */
    public function de(Colony $colonia): array
    {
        $colonia->loadMissing('buildings');

        $specs = DB::table('building_specs')
            ->get(['building_type', 'level', 'producao_hora_json'])
            ->keyBy(fn ($s) => $s->building_type.'|'.$s->level);

        $precos = DB::table('resource_types')->pluck('preco_base_micro', 'code');

        $producao = [];
        $consomeSet = [self::CONSUMO_UNIVERSAL => true];
        $repetidas = [];

        foreach ($colonia->buildings as $b) {
            $repetidas[$b->type] = ($repetidas[$b->type] ?? 0) + 1;

            foreach (self::CONSOME[$b->type] ?? [] as $insumo) {
                $consomeSet[$insumo] = true;
            }

            $spec = $specs->get($b->type.'|'.$b->level);
            $json = json_decode($spec->producao_hora_json ?? '{}', true) ?: [];

            foreach ($this->saidaReal($b->type, $json) as $recurso => $porHora) {
                $producao[$recurso] = ($producao[$recurso] ?? 0) + $porHora;
            }
        }

        arsort($producao);

        return [
            'producao' => $producao,
            'vocacao' => $this->vocacao($producao, $precos),
            'forca_pct' => $this->forcaPct($producao, $precos),
            /*
             * Depende do que consome e NÃO produz. É a leitura que o §8.1 exige mostrar junto do
             * perfil: "o que ele ganha e do que passa a depender".
             */
            'depende_de' => array_values(array_diff(
                array_keys($consomeSet),
                array_keys(array_filter($producao, fn ($q) => $q > 0)),
            )),
            'trilhas' => $this->trilhas($colonia),
            'repetidas' => array_filter($repetidas, fn ($n) => $n > 1),
        ];
    }

    /**
     * ⚠️ O que o prédio realmente produz — que **nem sempre é o que o `producao_hora_json` diz**.
     *
     * A Indústria Siderúrgica é a exceção, e ela me enganou uma vez: o JSON dela traz
     * `{"metal_bruto": 51}`, e isso é **o que ela PROCESSA por hora**, não o que produz. O
     * comentário no `ColonyTick` diz isso com todas as letras — *"o JSON reaproveita a chave
     * `metal_bruto` da Mina, mas aqui é o que ela PROCESSA"* —, e eu li como produção ao justificar
     * o D-171.
     *
     * O que ela produz de verdade está em `Siderurgica::SAIDAS`: a cada 1000 de Metal Bruto, 350
     * Ligas Metálicas **e cinco minerais eletrônicos**. Isso importa muito mais do que parece: são
     * minerais que, fora dela, só o Governo extrai (§4.3).
     *
     * As demais convertem, mas o JSON delas já é saída: Destilaria diz biocombustível, Refinaria diz
     * compostos, Oficina diz componentes. Só esta precisa de tradução.
     *
     * @param  array<string,float>  $json
     * @return array<string,float>
     */
    private function saidaReal(string $tipo, array $json): array
    {
        if ($tipo !== 'industria_siderurgica') {
            return $json;
        }

        $processado = (float) ($json[Siderurgica::INSUMO] ?? 0);
        $saidas = [];

        foreach (Siderurgica::SAIDAS as $recurso => $porBase) {
            $saidas[$recurso] = $porBase * $processado / Siderurgica::BASE;
        }

        return $saidas;
    }

    /**
     * A vocação é o recurso de maior VALOR produzido, não o de maior quantidade.
     *
     * Quantidade enganaria: o Reator faz 506 energia/h e a Mina 51 metal/h, mas o metal vale dez
     * vezes mais por unidade. Uma colônia não é "energética" por produzir muitos números.
     */
    private function vocacao(array $producao, $precos): ?string
    {
        $valores = $this->valores($producao, $precos);

        if ($valores === []) {
            return null;
        }

        return array_key_first($valores);
    }

    /**
     * Quanto o principal domina o resto, em pontos percentuais do valor total produzido.
     *
     * É o que separa "especialista" de "generalista" sem inventar um limiar de perfil: quem tem 80%
     * do valor num recurso só é outra coisa de quem tem 30% em quatro. O limiar de leitura fica
     * para a tela, que é quem fala com o colono.
     */
    private function forcaPct(array $producao, $precos): int
    {
        $valores = $this->valores($producao, $precos);
        $total = array_sum($valores);

        if ($total <= 0) {
            return 0;
        }

        return (int) round(100 * reset($valores) / $total);
    }

    /** @return array<string,float> valor/hora por recurso, do maior para o menor */
    private function valores(array $producao, $precos): array
    {
        $valores = [];

        foreach ($producao as $recurso => $porHora) {
            $v = $porHora * (float) ($precos[$recurso] ?? 0);

            if ($v > 0) {
                $valores[$recurso] = $v;
            }
        }

        arsort($valores);

        return $valores;
    }

    /** As trilhas de pesquisa concluídas — a outra metade da especialização (§8.1). */
    private function trilhas(Colony $colonia): array
    {
        return DB::table('colony_technologies')
            ->join('technologies', 'technologies.id', '=', 'colony_technologies.technology_id')
            ->where('colony_technologies.colony_id', $colonia->id)
            ->where('colony_technologies.status', 'concluida')
            ->distinct()->orderBy('technologies.trilha')
            ->pluck('technologies.trilha')->all();
    }
}
