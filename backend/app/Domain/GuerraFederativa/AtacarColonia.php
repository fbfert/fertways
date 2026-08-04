<?php

namespace App\Domain\GuerraFederativa;

use App\Domain\Guerra\Forcas;
use App\Exceptions\DomainRuleException;
use App\Models\Colony;
use App\Models\Combat;
use App\Models\Unit;
use Illuminate\Support\Facades\DB;

/**
 * Atacar a colônia de uma federação inimiga (A2.10, decisões 13 e 15).
 *
 * ## ⚠️ Só existe dentro de guerra declarada
 *
 * O §01 do GDD declara o slot principal **inviolável**, e a decisão 13 o revoga **apenas em guerra
 * federativa declarada**. Fora dela a colônia continua intocável, e é por isso que a conferência de
 * guerra vem antes de tudo: sem ela, esta classe seria a revogação do §01 em toda circunstância.
 *
 * ## Reusa o cerco da zona (decisão 15)
 *
 * Marcha, três rodadas, forças e reforços são os mesmos — já existem, já foram balanceados, e o
 * jogador já sabe jogá-los. O que muda é o **alvo** e o **desfecho**: em vez de trocar o dono de uma
 * zona, saqueia-se o excedente do Depósito.
 *
 * `combats.zone_id` nulo é o que marca "o alvo é colônia". `defender_colony_id` já existia.
 *
 * ## E exige Quartel (decisão 15)
 *
 * O Quartel hoje habilita atacar zona e nada mais — `'efeito' => 'nenhum'` no catálogo. Passa a ser
 * também o que autoriza marchar sobre uma colônia, o que torna isso um investimento declarado em vez
 * de um impulso.
 */
class AtacarColonia
{
    public function __construct(
        private readonly Forcas $forcas,
        private readonly EmGuerra $emGuerra,
    ) {}

    /** @param list<int> $unitIds */
    public function handle(Colony $atacante, Colony $alvo, array $unitIds): Combat
    {
        return DB::transaction(function () use ($atacante, $alvo, $unitIds) {
            $atacante = Colony::whereKey($atacante->id)->lockForUpdate()->firstOrFail();
            $alvo = Colony::whereKey($alvo->id)->lockForUpdate()->firstOrFail();

            if ($atacante->id === $alvo->id) {
                throw new DomainRuleException('alvo_invalido', 'Você não ataca a própria colônia.');
            }

            $this->conferirGuerra($atacante, $alvo);
            $this->conferirQuartel($atacante);
            $this->conferirCercoEmCurso($atacante, $alvo);

            $unidades = $this->reservar($atacante, $unitIds);

            $agora = now();
            $chega = $agora->copy()->addSeconds($this->marcha($atacante, $alvo));

            $combate = Combat::create([
                // ⚠️ Nulo: é isto que diz "o alvo é a colônia", e não a zona.
                'zone_id' => null,
                'attacker_colony_id' => $atacante->id,
                'defender_colony_id' => $alvo->id,
                'tipo' => 'invasao',
                'status' => 'marchando',
                'rodada' => 0,
                'chega_at' => $chega,
                'proxima_rodada_at' => $chega,
                'alvo' => null,
                /*
                 * O snapshot de defensor offline é tirado AGORA, no despacho, como no ataque de zona
                 * (§27.7): quem está à mesa quando a marcha começa não perde o bônus por sair dela
                 * depois. O GDD é explícito quanto a esse exploit.
                 */
                'resultado' => ['defensor_offline' => $this->defensorOffline($alvo)],
            ]);

            Unit::whereIn('id', $unidades->pluck('id'))->update([
                'combat_id' => $combate->id,
                'status' => 'marchando',
                'arrives_at' => $chega,
            ]);

            return $combate;
        });
    }

    /**
     * ⚠️ A conferência que impede esta classe de revogar o §01 fora da guerra.
     *
     * Duas colônias sem federação, ou de federações que não estão em guerra, não têm o que fazer aqui.
     */
    private function conferirGuerra(Colony $atacante, Colony $alvo): void
    {
        if (! $atacante->federation_id || ! $alvo->federation_id) {
            throw new DomainRuleException(
                'sem_guerra',
                'Colônia só é alvo dentro de guerra entre federações. Uma das duas não tem federação.',
            );
        }

        if ($atacante->federation_id === $alvo->federation_id) {
            throw new DomainRuleException('mesma_federacao', 'Vocês são da mesma federação.');
        }

        if (! $this->emGuerra->entreFederacoes($atacante->federation_id, $alvo->federation_id)) {
            throw new DomainRuleException(
                'sem_guerra',
                'As suas federações não estão em guerra. Fora dela, a colônia é inviolável (§01).',
            );
        }
    }

    private function conferirQuartel(Colony $atacante): void
    {
        $nivel = (int) $atacante->buildings()->where('type', 'quartel')->value('level');

        if ($nivel < 1) {
            throw new DomainRuleException(
                'sem_quartel',
                'Marchar sobre uma colônia exige Quartel erguido.',
            );
        }
    }

    /** Um cerco por par de cada vez: dois simultâneos dobrariam o saque sobre o mesmo estoque. */
    private function conferirCercoEmCurso(Colony $atacante, Colony $alvo): void
    {
        $existe = Combat::whereNull('zone_id')
            ->where('defender_colony_id', $alvo->id)
            ->whereIn('status', ['marchando', 'cercando'])
            ->exists();

        if ($existe) {
            throw new DomainRuleException('ja_cercada', 'Esta colônia já está sob cerco.');
        }
    }

    /** @param list<int> $unitIds */
    private function reservar(Colony $atacante, array $unitIds)
    {
        $unidades = Unit::whereIn('id', $unitIds)
            ->where('colony_id', $atacante->id)
            ->where('status', 'ociosa')
            ->whereNull('combat_id')
            ->lockForUpdate()
            ->get();

        if ($unidades->isEmpty() || $unidades->count() !== count($unitIds)) {
            throw new DomainRuleException(
                'unidades_indisponiveis',
                'Alguma das unidades não está ociosa ou não é sua.',
            );
        }

        return $unidades;
    }

    /**
     * O tempo de marcha, pela distância entre as duas colônias.
     *
     * Mesma ideia da marcha até a zona: distância importa, e é pilar declarado do jogo. Um ataque
     * instantâneo tiraria do defensor a janela de reagir, que num jogo assíncrono é o que separa
     * guerra de emboscada.
     */
    private function marcha(Colony $atacante, Colony $alvo): int
    {
        $slots = abs($atacante->x - $alvo->x) + abs($atacante->y - $alvo->y);

        return max(600, $slots * 120);
    }

    /**
     * §27.7: o defensor que não está à mesa dá bônus ao atacante.
     *
     * ⚠️ A mesma expressão que `Forcas::defensorOffline()` usa para a zona — token pessoal com uso
     * nos últimos 15 minutos. Escrever uma segunda definição de "online" faria as duas divergirem no
     * primeiro ajuste, e o jogador veria o mesmo estado ser lido de dois jeitos.
     */
    private function defensorOffline(Colony $alvo): bool
    {
        if ($alvo->user_id === null) {
            return false;
        }

        return ! DB::table('personal_access_tokens')
            ->where('tokenable_type', 'App\\Models\\User')
            ->where('tokenable_id', $alvo->user_id)
            ->where('last_used_at', '>=', now()->subMinutes(15))
            ->exists();
    }
}
