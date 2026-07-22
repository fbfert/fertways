<?php

namespace App\Domain\Guerra;

use App\Models\Combat;
use App\Models\NeutralZone;
use App\Models\Unit;
use App\Models\WarSetting;
use Illuminate\Support\Facades\DB;

/**
 * A Força Ofensiva e a Força Defensiva do §27.3 (docs/decisoes.md D-66).
 *
 *   Força Ofensiva  = Σ (ataque de cada Sentinela enviada)
 *   Força Defensiva = Σ (defesa de cada unidade na zona) × Bônus de Construção
 *
 * As duas somas são de unidades VIVAS e contam o ferimento: uma Sentinela a 50% de HP vale metade.
 * É o que faz reforço ferido valer menos que reforço inteiro (§27.6).
 */
class Forcas
{
    private const CHEIO = 10000;

    public function config(): WarSetting
    {
        return WarSetting::singleton();
    }

    /**
     * A força ofensiva de um combate: as unidades que ele mobilizou e que já chegaram.
     *
     * Quem ainda marcha não conta — é essa a razão de o §27.5 dizer que reforços entram "a partir
     * da rodada seguinte": eles só somam quando chegam.
     */
    public function ofensiva(Combat $combate): int
    {
        return Unit::where('combat_id', $combate->id)
            ->where('status', 'em_combate')
            ->where('hp_bps', '>', 0)
            ->get()
            ->sum(fn (Unit $u) => $u->ataque());
    }

    /**
     * A força defensiva de uma zona: as unidades guarnecendo, vezes o bônus das construções.
     *
     * O `$defensorOffline` é o +20% do §27.7 — e ele é um SNAPSHOT tirado quando o ataque chega,
     * não uma leitura ao vivo. O GDD é explícito quanto ao exploit: "quem ficou offline depois de
     * saber do ataque não ganha o bônus". Ler ao vivo premiaria exatamente quem desconecta de
     * propósito.
     */
    public function defensiva(NeutralZone $zona, bool $defensorOffline = false): int
    {
        $base = Unit::where('zone_id', $zona->id)
            ->whereIn('status', ['na_zona', 'em_combate'])
            ->where('hp_bps', '>', 0)
            ->get()
            ->sum(fn (Unit $u) => $u->defesa());

        /*
         * Manutenção territorial em atraso (§27.12, D-84): "as unidades começam a perder Pontos de
         * Defesa progressivamente". Incide sobre a base, ANTES do bônus de construção — a muralha
         * não fica mais fraca por falta de pagamento, os robôs é que lutam pior.
         */
        $penalidade = self::CHEIO - $zona->penalidadeManutencaoBps();
        $base = intdiv($base * $penalidade, self::CHEIO);

        $bonus = self::CHEIO + $this->bonusDeConstrucao($zona);

        if ($defensorOffline) {
            $bonus += Combat::OFFLINE_BPS;
        }

        return intdiv($base * $bonus, self::CHEIO);
    }

    /**
     * O bônus de construção do §27.3, em basis points, ADITIVO entre as três.
     *
     * ⚠️ **O bônus escala com o NÍVEL, e o §27.3 não diz isso.** O documento escreve só
     * "+X% / +Y% / +Z% (valores configuráveis)". Mas as três construções têm cinco níveis (a curva
     * 1,65× do catálogo), e um bônus fixo tornaria os níveis 2 a 5 decorativos — pagaria-se 8.894
     * Metal Bruto por um Bastião nível 5 que defende igual ao nível 1.
     *
     * Escala LINEAR pelo nível, então: no nível 1 valem os +20/+30/+50 que o D-66 arbitrou (as três
     * juntas dobram a defesa, que foi o que se aprovou), e daí para cima crescem proporcionalmente.
     * É derivação, não número novo — os números continuam sendo os do operador, no painel.
     *
     * O Abrigo de Robôs **não dá bônus**: ele é onde os sobreviventes se recolhem (§27.6) e o que o
     * Predador tem de vencer (§28.10). O §27.3 não o lista, e não o inventamos.
     *
     * Cada termo é escalado pela `fracaoEfetiva()` da estrutura (D-118): uma Muralha apreendida
     * (Predador) some do cálculo; uma sabotada pelo Infiltrador contribui na proporção que sobrou.
     */
    public function bonusDeConstrucao(NeutralZone $zona): int
    {
        $c = $this->config();

        return intdiv($c->muralha_bonus_bps * $zona->nivelDe('muralha_de_perimetro') * $zona->fracaoEfetiva('muralha_de_perimetro'), self::CHEIO)
            + intdiv($c->torre_bonus_bps * $zona->nivelDe('torre_de_vigia') * $zona->fracaoEfetiva('torre_de_vigia'), self::CHEIO)
            + intdiv($c->bastiao_bonus_bps * $zona->nivelDe('bastiao') * $zona->fracaoEfetiva('bastiao'), self::CHEIO);
    }

    /**
     * O defensor estava offline quando o ataque chegou?
     *
     * "Genuinamente ausente" (§27.7). O sinal honesto que temos é o `last_used_at` dos tokens do
     * Sanctum: se nenhum token do dono da zona foi usado nos últimos 15 minutos, ele não está à
     * mesa. Não é perfeito — uma aba aberta e parada não usa token —, mas é o único sinal real de
     * atividade que o servidor tem, e erra para o lado certo: na dúvida, o defensor NÃO ganha o
     * bônus, e o exploit de desconectar de propósito não paga.
     */
    public function defensorOffline(NeutralZone $zona): bool
    {
        if ($zona->owner_colony_id === null) {
            return false;
        }

        $userId = DB::table('colonies')->where('id', $zona->owner_colony_id)->value('user_id');

        if ($userId === null) {
            return false;
        }

        $ativoRecente = DB::table('personal_access_tokens')
            ->where('tokenable_type', 'App\Models\User')
            ->where('tokenable_id', $userId)
            ->where('last_used_at', '>=', now()->subMinutes(15))
            ->exists();

        return ! $ativoRecente;
    }
}
