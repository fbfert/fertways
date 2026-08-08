<?php

namespace App\Domain\Eventos;

use App\Models\Colony;
use App\Models\GameEvent;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Quanto os eventos ativos mexem numa taxa, num intervalo (A2.8).
 *
 * ## ⚠️ A média ponderada pelo tempo é EXATA, e não uma aproximação
 *
 * O roadmap exige que o modificador seja *"reconstruível no passado"*. O caminho óbvio seria fatiar o
 * delta do tick nas bordas de cada evento, como o `ColonyTick` já faz com as conclusões de obra. Não
 * é preciso, e a razão é aritmética:
 *
 * A produção de um intervalo é `taxa × tempo`. O modificador é **constante por trechos** no tempo.
 * Então a produção com evento é `Σ(taxa × mᵢ × tᵢ)`, que é idêntica a `taxa × T × (Σ mᵢtᵢ / T)` — a
 * média ponderada. Não há erro de arredondamento de modelo: **é a mesma conta**, escrita de outro
 * jeito, e sem multiplicar o número de consultas do caminho mais quente do jogo.
 *
 * (Isso vale porque a produção é **linear no tempo**. Se um dia entrar um modificador não linear, ele
 * precisa fatiar — e este comentário é o aviso.)
 *
 * ## O passado é sempre recalculável
 *
 * Nenhuma linha é apagada: cancelar grava `cancelado_em` e o evento continua valendo até ali. Por
 * isso `para()` aceita qualquer intervalo, inclusive um já ocorrido, e devolve o que **estava**
 * valendo — que é o que o "Desde sua última visita" precisa para explicar por que a produção caiu.
 *
 * ## Somam-se, e o piso é −100%
 *
 * Dois eventos de −30% dão −60%, não 0,49×. Somar é o que o operador consegue prever de cabeça, e
 * previsibilidade é o que torna o motor utilizável sem simulador. O piso existe para que a taxa nunca
 * fique negativa e a "produção" passe a consumir.
 */
class Modificadores
{
    public const PRODUCAO = 'producao';

    public const CONSUMO = 'consumo';

    /** O portão da guerra (A2.10 §17): `-10000` fecha, e ninguém declara enquanto durar. */
    public const GUERRA_DECLARACAO = 'guerra_declaracao';

    /** Quanto custa declarar e mobilizar, em bps sobre o custo normal. */
    public const GUERRA_CUSTO = 'guerra_custo';

    /**
     * O XP que o portão de território exige, em bps sobre o normal (D-232).
     *
     * `-9500` faz o marco 20 (Desbravador, 6.000 XP) cobrar 300 XP enquanto durar. **Não dá XP a
     * ninguém**: baixa a régua, e é por isso que ele não escreve no ledger nem mexe no `colonies.xp`
     * — passado o evento, todo mundo tem exatamente o XP que tinha, e o portão volta ao lugar.
     *
     * ⚠️ Vale **só para ocupar zona neutra**, e não para os outros portões do §05 (o Drone nível 2
     * está no marco 10 e continua lá). Um modificador que abrisse todos de uma vez seria mais fácil
     * de escrever e impossível de dosar.
     */
    public const OCUPACAO_MARCO = 'ocupacao_marco';

    /**
     * Quantos colonos livres ocupar uma zona exige, em bps sobre o normal (D-232).
     *
     * `-10000` isenta: a zona pode ser ocupada sem gente sobrando. Ela **nasce com a equipe assim
     * mesmo** — a colônia fica devendo operadores ao que tem de pé, que é a situação que o D-178
     * já tolera e o D-225 já sabe desenhar na tela. Degrada, não confisca (§6.6).
     */
    public const OCUPACAO_POPULACAO = 'ocupacao_populacao';

    /** A lista canônica. A coluna deixou de ser `enum` para a verdade morar num lugar só. */
    public const TODOS = [
        self::PRODUCAO, self::CONSUMO, self::GUERRA_DECLARACAO, self::GUERRA_CUSTO,
        self::OCUPACAO_MARCO, self::OCUPACAO_POPULACAO,
    ];

    /**
     * ⚠️ Os modificadores que se medem **por instante**, e nunca por média.
     *
     * Produção e consumo são taxas: correm no tempo, e a média ponderada é EXATA para elas. Guerra
     * não é taxa — *"há trégua agora?"* e *"quanto custa declarar agora?"* são perguntas de um
     * momento. Uma trégua que cobrisse metade do intervalo viraria "meio bloqueada", que não
     * significa coisa alguma.
     *
     * `para()` recusa estes; quem pergunta por eles usa `em()`.
     *
     * Os dois portões de ocupação entram aqui pela mesma razão (D-232): *"quanto XP o portão pede
     * AGORA?"* é pergunta de instante. Ocupar acontece num clique — não há intervalo sobre o qual
     * ponderar, e uma média diria que o portão está meio aberto, que não é um estado que exista.
     */
    public const PONTUAIS = [
        self::GUERRA_DECLARACAO, self::GUERRA_CUSTO,
        self::OCUPACAO_MARCO, self::OCUPACAO_POPULACAO,
    ];

    /**
     * O multiplicador em pontos-base para o intervalo — 10.000 é "sem efeito".
     *
     * @param  string|null  $recurso  null pergunta só pelos eventos globais
     */
    public function para(
        ?Colony $colonia,
        string $modificador,
        CarbonInterface $de,
        CarbonInterface $ate,
        ?string $recurso = null,
    ): int {
        /*
         * ⚠️ Recusa, e não converte em silêncio.
         *
         * Devolver a média de um portão daria um número plausível e errado — "meio em trégua" —, e
         * quem o lesse não teria como desconfiar. Explodir aqui custa um teste vermelho; devolver
         * 5.000 custaria uma guerra declarada durante uma trégua, meses depois, sem ninguém entender.
         */
        if (in_array($modificador, self::PONTUAIS, true)) {
            throw new \LogicException(
                "«{$modificador}» é pontual e não se mede por média: use `em()`. Ver o docblock.",
            );
        }

        $segundos = $ate->getTimestamp() - $de->getTimestamp();

        if ($segundos <= 0) {
            return 10_000;
        }

        $soma = 0;

        foreach ($this->vigentes($colonia, $modificador, $de, $ate, $recurso) as $evento) {
            $cobertos = $this->segundosCobertos($evento, $de, $ate);

            if ($cobertos <= 0) {
                continue;
            }

            // A ponderação: um evento que valeu metade do intervalo entra com metade do efeito.
            $soma += (int) round($evento->efeito_bps * $cobertos / $segundos);
        }

        // Piso em zero: −100% para a produção, e nunca uma taxa negativa que passasse a consumir.
        return max(0, 10_000 + $soma);
    }

    /**
     * O efeito **num instante** — 10.000 é "sem efeito", 0 é "bloqueado".
     *
     * É a leitura certa para ato pontual: declarar guerra, pagar mobilização. Não há ponderação
     * nenhuma porque não há intervalo — só vale o que está valendo naquele momento.
     *
     * ⚠️ Um evento **cancelado** deixa de valer no instante do cancelamento, e `vigenteEm()` já
     * cuida disso: o rollback lógico da A2.8 continua valendo aqui sem código novo.
     */
    public function em(?Colony $colonia, string $modificador, CarbonInterface $quando): int
    {
        $soma = 0;

        foreach ($this->vigentes($colonia, $modificador, $quando, $quando->copy()->addSecond()) as $evento) {
            if ($evento->vigenteEm($quando)) {
                $soma += (int) $evento->efeito_bps;
            }
        }

        return max(0, 10_000 + $soma);
    }

    /** Atalho legível: há trégua agora? */
    public function guerraBloqueada(?Colony $colonia, CarbonInterface $quando): bool
    {
        return $this->em($colonia, self::GUERRA_DECLARACAO, $quando) <= 0;
    }

    /**
     * Os eventos que tocaram este intervalo.
     *
     * @return Collection<int,GameEvent>
     */
    public function vigentes(
        ?Colony $colonia,
        string $modificador,
        CarbonInterface $de,
        CarbonInterface $ate,
        ?string $recurso = null,
    ) {
        return GameEvent::query()
            // `rascunho` não vale nada no mundo; `cancelado` vale até `cancelado_em`.
            ->whereIn('status', ['ativo', 'cancelado'])
            ->where('modificador', $modificador)
            ->where('comeca_em', '<', $ate)
            ->where('termina_em', '>', $de)
            ->where(fn ($q) => $q->whereNull('resource_type')->when(
                $recurso !== null,
                fn ($q) => $q->orWhere('resource_type', $recurso),
            ))
            ->where(fn ($q) => $q->where('escopo', 'mundo')->when(
                $colonia !== null,
                fn ($q) => $q->orWhere(fn ($q2) => $q2->where('escopo', 'colonia')
                    ->where('colony_id', $colonia->id)),
            ))
            ->get();
    }

    /**
     * Quantos segundos deste intervalo o evento realmente cobriu.
     *
     * ⚠️ O cancelamento entra aqui, e é o que faz o "rollback lógico" da §Segurança funcionar: o
     * evento para de valer em `cancelado_em` e **continua valendo antes disso**. Nenhum efeito
     * histórico é apagado — o passado do jogador não muda porque o operador mudou de ideia hoje.
     */
    private function segundosCobertos(GameEvent $evento, CarbonInterface $de, CarbonInterface $ate): int
    {
        $inicio = max($de->getTimestamp(), $evento->comeca_em->getTimestamp());
        $fim = min($ate->getTimestamp(), $evento->termina_em->getTimestamp());

        if ($evento->cancelado_em !== null) {
            $fim = min($fim, $evento->cancelado_em->getTimestamp());
        }

        return max(0, $fim - $inicio);
    }
}
