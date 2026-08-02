<?php

namespace App\Domain\Zona;

use App\Domain\Populacao\Parametros;
use App\Domain\Populacao\Populacao;
use App\Exceptions\DomainRuleException;
use App\Models\Colony;
use App\Models\NeutralZone;
use Illuminate\Support\Facades\DB;

/**
 * Quem opera cada zona neutra (A2.6).
 *
 * ## O princípio da fase, que é do roadmap
 *
 * *"Poucos humanos operam muitos robôs. Zonas não devem exigir populações enormes."* Uma zona nível 1
 * pede 2 colonos; a nível 10, dezesseis. O trabalho pesado é dos robôs — o colono supervisiona.
 *
 * ## ⚠️ Degrada, não se perde (§6.6)
 *
 * Zona abaixo da equipe exigida **não é perdida, nem devolvida, nem punida com destruição**: ela
 * produz menos, e volta ao normal assim que a equipe for reposta. É a mesma escolha que o `Ciclo` faz
 * para escassez de insumo na colônia, e a mesma que o teto habitacional faz com a população acima do
 * limite (D-178): **trava o ganho, nunca tira o que existe**.
 *
 * Num jogo persistente sem reset, perder território por ter passado o fim de semana fora não é
 * dificuldade, é hostilidade.
 *
 * ## Alocar é instantâneo, e isso é decisão declarada
 *
 * Não há colono em trânsito — ver o docblock da migration `2026_08_01_500000_operadores_de_zona`.
 */
class Operadores
{
    public function __construct(
        private readonly Parametros $parametros,
        private readonly Populacao $populacao,
    ) {}

    /**
     * Quantos operadores esta zona exige — pelo nível dela, **menos o que o Abrigo de Robôs poupa**.
     *
     * ## ⚠️ O Abrigo finalmente faz o que o nome diz
     *
     * O GDD o descreve como *"onde as unidades ficam estacionadas e se recuperam entre turnos de
     * extração"*, e até aqui ele só servia de defesa contra o Predador (§28.10) — o próprio catálogo
     * de estruturas admitia que a função de recuperação *"o GDD promete e nunca cronometra"*.
     *
     * Cada nível do Abrigo dispensa **um** operador humano, porque é isso que ele significa no
     * princípio declarado da fase: *"poucos humanos operam muitos robôs"*. Robô abrigado é robô que
     * volta a trabalhar sozinho; o colono que o acompanharia fica livre para outra coisa.
     *
     * ⚠️ **Piso de 1 enquanto a zona exigir alguém.** Zerar o requisito faria uma zona operar
     * sozinha para sempre, e território sem gente nenhuma tira do jogo a decisão que esta fase
     * inteira existe para criar.
     */
    public function exigidos(NeutralZone $zona): int
    {
        $base = $this->parametros->operadoresDeZona((int) $zona->level);

        if ($base <= 0) {
            return 0;
        }

        // `fracaoEfetiva` desconta módulo sabotado/desligado — o Abrigo poupa na medida em que opera.
        $abrigo = intdiv(
            $zona->nivelDe('abrigo_de_robos') * $zona->fracaoEfetiva('abrigo_de_robos'),
            10_000,
        );

        return max(1, $base - $abrigo);
    }

    /**
     * A eficiência da zona, em pontos-base — 10.000 com a equipe completa.
     *
     * ⚠️ Interpola entre o piso e o cheio, **como a escassez da colônia faz**. Meia equipe, meio
     * caminho entre o piso e 100%. E nunca abaixo do piso: uma zona com um único operador ainda
     * rende alguma coisa, porque §6.6 diz degradar, não parar.
     *
     * Sem a chave-mestra da população, devolve 10.000: enquanto a mecânica não está no ar, nenhuma
     * zona pode ser penalizada por uma equipe que o jogo ainda não pede.
     */
    public function eficienciaBps(NeutralZone $zona): int
    {
        if (! $this->parametros->ativo()) {
            return 10_000;
        }

        $exigidos = $this->exigidos($zona);

        if ($exigidos <= 0) {
            return 10_000;
        }

        $tem = (int) $zona->operadores;

        if ($tem >= $exigidos) {
            return 10_000;
        }

        $piso = (int) $this->parametros->todos()->escassez_eficiencia_bps;
        $razao = $tem / $exigidos;

        return (int) round($piso + $razao * (10_000 - $piso));
    }

    /**
     * Quantos colonos da colônia não estão comprometidos com nada.
     *
     * ⚠️ Pode ser NEGATIVO, e o chamador precisa lidar com isso: uma colônia que perdeu população
     * (ou subiu um prédio) fica devendo operadores ao que já tem erguido. O modelo degrada; não
     * inventa gente nem confisca zona.
     */
    public function disponivel(Colony $colonia): int
    {
        return (int) $colonia->populacao
            - $this->populacao->alocadaEmConstrucoes($colonia)
            - $this->populacao->alocadaEmZonas($colonia);
    }

    /**
     * Manda colonos da colônia para a zona.
     *
     * O limite é a equipe exigida: mandar gente além do que a zona usa seria desperdício silencioso —
     * o colono sairia do bolo da colônia e não produziria nada em lugar nenhum.
     */
    public function alocar(Colony $colonia, NeutralZone $zona, int $quantos): NeutralZone
    {
        return DB::transaction(function () use ($colonia, $zona, $quantos) {
            $zona = NeutralZone::whereKey($zona->id)->lockForUpdate()->firstOrFail();
            $colonia = Colony::whereKey($colonia->id)->lockForUpdate()->firstOrFail();

            $this->conferirDono($colonia, $zona);

            if ($quantos < 1) {
                throw new DomainRuleException('quantidade_invalida', 'Diga quantos colonos mandar.');
            }

            $cabem = $this->exigidos($zona) - (int) $zona->operadores;

            if ($cabem <= 0) {
                throw new DomainRuleException(
                    'equipe_completa',
                    'Esta zona já está com a equipe completa. Colono a mais não produz nada aqui.',
                );
            }

            if ($quantos > $cabem) {
                throw new DomainRuleException(
                    'equipe_completa',
                    "Cabem só mais {$cabem} operador(es) nesta zona.",
                );
            }

            $livres = $this->disponivel($colonia);

            if ($livres < $quantos) {
                throw new DomainRuleException(
                    'sem_populacao',
                    'A sua colônia não tem colonos livres: '
                        .max(0, $livres).' disponível(is) para '.$quantos.' pedido(s).',
                );
            }

            $zona->increment('operadores', $quantos);

            return $zona->fresh();
        });
    }

    /**
     * Traz colonos de volta à colônia — o "retorno" das entregas da fase.
     *
     * ⚠️ Sem trava de mínimo: devolver TODOS é permitido, e a zona apenas degrada até o piso. Impedir
     * o retorno prenderia o jogador numa zona que ele já não quer manter, e o §6.6 escolheu degradar
     * em vez de aprisionar.
     */
    public function devolver(Colony $colonia, NeutralZone $zona, int $quantos): NeutralZone
    {
        return DB::transaction(function () use ($colonia, $zona, $quantos) {
            $zona = NeutralZone::whereKey($zona->id)->lockForUpdate()->firstOrFail();

            $this->conferirDono($colonia, $zona);

            if ($quantos < 1) {
                throw new DomainRuleException('quantidade_invalida', 'Diga quantos colonos trazer de volta.');
            }

            if ((int) $zona->operadores < $quantos) {
                throw new DomainRuleException(
                    'sem_operadores',
                    'A zona não tem tantos operadores: '.(int) $zona->operadores.' lá.',
                );
            }

            $zona->decrement('operadores', $quantos);

            return $zona->fresh();
        });
    }

    private function conferirDono(Colony $colonia, NeutralZone $zona): void
    {
        if ((int) $zona->owner_colony_id !== (int) $colonia->id) {
            throw new DomainRuleException('zona_de_outro', 'Esta zona não é sua.');
        }
    }
}
