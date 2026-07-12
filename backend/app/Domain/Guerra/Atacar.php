<?php

namespace App\Domain\Guerra;

use App\Domain\Logistics\MapaFertways;
use App\Exceptions\DomainRuleException;
use App\Models\Colony;
use App\Models\Combat;
use App\Models\NeutralZone;
use App\Models\Unit;
use Illuminate\Support\Facades\DB;

/**
 * Despacha um ataque contra uma zona neutra (GDD §27, §28.10; docs/decisoes.md D-66).
 *
 * Os **quatro** tipos partem daqui e da mesma marcha. O que muda é o que cada um leva e o que a
 * rodada faz quando chega — ver `ResolverCombates`.
 *
 *   invasao    Sentinelas. Zera a defesa e TOMA a zona, saqueando 50% do não protegido (§27.8).
 *   cerco      Sentinelas. Não entra: fecha a zona. 48 h para o dono romper ou entregar 30%.
 *   sabotagem  Um Infiltrador. Desliga uma estrutura, se a Torre de Vigia não o vir (§28.10).
 *   apreensao  Um Predador. Leva um Módulo Operacional, contra o Abrigo de Robôs (§28.10).
 */
class Atacar
{
    /** Slots por minuto de uma unidade civil — o Furgão do §21.2. */
    private const VELOCIDADE_CIVIL = 4.0;

    public function __construct(private Forcas $forcas) {}

    /**
     * @param  list<int>  $unitIds  as unidades que marcham; têm de estar em casa, na colônia.
     * @param  string|null  $alvo  sabotagem e apreensão miram UMA estrutura da zona.
     */
    public function handle(
        Colony $colony,
        NeutralZone $zona,
        string $tipo,
        array $unitIds,
        ?string $alvo = null,
    ): Combat {
        return DB::transaction(function () use ($colony, $zona, $tipo, $unitIds, $alvo) {
            $zona = NeutralZone::whereKey($zona->id)->lockForUpdate()->firstOrFail();

            $this->conferirAlvo($colony, $zona);
            $this->conferirCooldown($colony, $zona);

            $unidades = $this->reservarUnidades($colony, $tipo, $unitIds);

            $this->conferirAlvoDeEstrutura($zona, $tipo, $alvo);

            $agora = now();
            $chega = $agora->copy()->addSeconds($this->marchaEmSegundos($colony, $zona));

            $combate = Combat::create([
                'zone_id' => $zona->id,
                'attacker_colony_id' => $colony->id,
                'defender_colony_id' => $zona->owner_colony_id,
                'tipo' => $tipo,
                'status' => 'marchando',
                'rodada' => 0,
                'chega_at' => $chega,
                // A primeira rodada é a da chegada. Antes disso não há o que resolver.
                'proxima_rodada_at' => $chega,
                'alvo' => $alvo,
                /*
                 * O +20% de defensor offline (§27.7) é um SNAPSHOT tirado AGORA, no despacho — e
                 * não quando o ataque chega. O GDD é explícito quanto ao exploit: "o sistema
                 * registra que o defensor estava online quando a notificação foi enviada,
                 * impedindo o exploit de desconectar propositalmente". Quem está à mesa no
                 * momento em que a marcha começa não ganha o bônus por sair da mesa depois.
                 */
                'resultado' => ['defensor_offline' => $this->forcas->defensorOffline($zona)],
            ]);

            Unit::whereIn('id', $unidades->pluck('id'))->update([
                'combat_id' => $combate->id,
                'status' => 'marchando',
                'arrives_at' => $chega,
            ]);

            return $combate;
        });
    }

    /** Só se ataca zona ocupada por OUTRO, e fora da proteção de novato (8 dias, §28.4). */
    private function conferirAlvo(Colony $colony, NeutralZone $zona): void
    {
        if ($zona->owner_colony_id === null) {
            throw new DomainRuleException(
                'zona_livre',
                'Esta zona não tem dono — ocupe-a, não a ataque.',
            );
        }

        if ($zona->owner_colony_id === $colony->id) {
            throw new DomainRuleException('zona_propria', 'Você não pode atacar a própria zona.');
        }

        if ($zona->protected_until !== null && $zona->protected_until->isFuture()) {
            throw new DomainRuleException(
                'zona_protegida',
                'A zona está sob proteção de novato até '.$zona->protected_until->format('d/m H:i').'.',
            );
        }
    }

    /**
     * O cooldown do §27.10: o **mesmo** jogador não reataca a **mesma** zona por 48 h, tenha
     * vencido ou perdido. "Isso impede spam de ataques até vencer por desgaste."
     *
     * Outros jogadores podem atacar a mesma zona nesse período — o GDD é explícito, e não travamos.
     */
    private function conferirCooldown(Colony $colony, NeutralZone $zona): void
    {
        $recente = Combat::where('zone_id', $zona->id)
            ->where('attacker_colony_id', $colony->id)
            ->where('created_at', '>=', now()->subHours(Combat::COOLDOWN_HORAS))
            ->exists();

        if ($recente) {
            throw new DomainRuleException(
                'em_cooldown',
                'Você atacou esta zona nas últimas '.Combat::COOLDOWN_HORAS.' h. Espere para atacar de novo.',
            );
        }
    }

    /**
     * Reserva as unidades. Têm de estar EM CASA, na colônia, e ser do tipo que o ataque exige.
     *
     * @param  list<int>  $unitIds
     * @return \Illuminate\Support\Collection<int,Unit>
     */
    private function reservarUnidades(Colony $colony, string $tipo, array $unitIds)
    {
        $exigido = match ($tipo) {
            'invasao', 'cerco' => 'sentinela',
            'sabotagem' => 'infiltrador',
            'apreensao' => 'predador',
            default => throw new DomainRuleException('tipo_invalido', "Ataque desconhecido: {$tipo}."),
        };

        $unidades = Unit::whereIn('id', $unitIds)
            ->where('colony_id', $colony->id)
            ->where('status', 'casa')
            ->where('type', $exigido)
            ->where('hp_bps', '>', 0)
            ->lockForUpdate()
            ->get();

        if ($unidades->count() !== count($unitIds) || $unidades->isEmpty()) {
            throw new DomainRuleException(
                'unidades_indisponiveis',
                "Selecione {$exigido}(s) desta colônia que estejam no pátio e não destruídos.",
            );
        }

        // Sabotagem e apreensão são ações de infiltração, não de exército: uma unidade, uma tentativa.
        if (in_array($tipo, ['sabotagem', 'apreensao'], true) && $unidades->count() !== 1) {
            throw new DomainRuleException(
                'uma_unidade',
                'Sabotagem e apreensão vão com uma unidade só — são infiltração, não batalha.',
            );
        }

        return $unidades;
    }

    /**
     * Sabotagem e apreensão miram UMA estrutura da zona — o "Módulo Operacional" que a v3.2 nomeia
     * e nunca define (D-66: é uma estrutura da zona, que para de operar até resgate ou reparo).
     *
     * Não se mira o que não existe, nem o que já está desligado.
     */
    private function conferirAlvoDeEstrutura(NeutralZone $zona, string $tipo, ?string $alvo): void
    {
        if (! in_array($tipo, ['sabotagem', 'apreensao'], true)) {
            return;
        }

        $estruturas = [
            'deposito' => $zona->deposit_level,
            'muralha' => $zona->wall_level,
            'torre_de_vigia' => $zona->watchtower_level,
            'bastiao' => $zona->bastion_level,
            'abrigo_de_robos' => $zona->shelter_level,
            'posto_de_comando' => $zona->command_post_level,
        ];

        if ($alvo === null || ! array_key_exists($alvo, $estruturas)) {
            throw new DomainRuleException(
                'alvo_invalido',
                'Escolha a estrutura-alvo: '.implode(', ', array_keys($estruturas)).'.',
            );
        }

        if ($estruturas[$alvo] < 1) {
            throw new DomainRuleException('alvo_inexistente', "A zona não tem {$alvo}.");
        }

        if (in_array($alvo, $zona->modules_offline ?? [], true)) {
            throw new DomainRuleException('alvo_ja_desligado', "O {$alvo} já está fora de operação.");
        }
    }

    /**
     * A marcha de combate, em segundos.
     *
     * §27.4: "unidades de ataque levam **1,3× mais tempo** que unidades civis para percorrer a mesma
     * distância — marcha de combate é mais pesada que logística civil". A âncora civil é o Furgão,
     * 4 slots/min (§21.2). A distância é a do mapa, arredondada como o frete a arredonda (§25.6) —
     * a mesma conta, para que "a distância real no mapa determina o tempo de marcha" (§27.4) queira
     * dizer a distância que o jogador já vê na tela.
     */
    private function marchaEmSegundos(Colony $origem, NeutralZone $zona): int
    {
        $slots = MapaFertways::distancia($origem->x, $origem->y, $zona->x, $zona->y);

        $minutos = $slots / self::VELOCIDADE_CIVIL * Combat::MARCHA;

        return max(60, (int) round($minutos * 60));
    }
}
