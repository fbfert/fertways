<?php

namespace App\Domain\GuerraFederativa;

use App\Domain\Colony\Silo;
use App\Domain\Guerra\Forcas;
use App\Domain\Telemetria\RegistrarEvento;
use App\Models\Colony;
use App\Models\Combat;
use App\Models\Ledger;
use App\Models\Unit;
use App\Models\WarSetting;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * O cerco de colônia, rodada a rodada (A2.10, decisões 13/14/15).
 *
 * ## ⚠️ Por que um resolvedor próprio, e não um ramo no `ResolverCombates`
 *
 * Aquele resolve três tipos de ataque em 700 linhas fortemente tipadas em `NeutralZone`: a defesa vem
 * de `Forcas::defensiva(NeutralZone)`, os defensores da zona, e o desfecho troca `owner_colony_id` e
 * saqueia de `deposit_amount`. Ramificá-lo em cada um desses pontos poria em risco o único sistema de
 * combate que o jogo já tem testado — e num jogo no ar.
 *
 * ⚠️ **O preço é duplicação da fórmula da rodada**, e ele está escrito de propósito: se o dano por
 * rodada mudar no `ResolverCombates`, tem de mudar aqui também. A alternativa — generalizar aquela
 * classe agora — era a mudança mais arriscada disponível, e esta fatia já revoga um parágrafo do GDD.
 *
 * ## A defesa da colônia são as unidades dela, e só
 *
 * A Torre de Defesa **não** entra na força defensiva: ela reduz o **espólio** (decisão 14). Pôr o
 * mesmo prédio nos dois lados da conta faria um investimento pagar duas vezes, e o balanceamento
 * ficaria impossível de ler.
 */
class ResolverCercoDeColonia
{
    private const CHEIO = 10_000;

    public function __construct(
        private readonly Forcas $forcas,
        private readonly Silo $silo,
    ) {}

    public function handle(?CarbonInterface $agora = null): int
    {
        $agora ??= now();

        $ids = Combat::whereNull('zone_id')
            ->whereIn('status', ['marchando', 'em_curso'])
            ->where('proxima_rodada_at', '<=', $agora)
            ->orderBy('proxima_rodada_at')
            ->pluck('id');

        $resolvidos = 0;

        foreach ($ids as $id) {
            if ($this->avancar((int) $id, $agora)) {
                $resolvidos++;
            }
        }

        return $resolvidos;
    }

    private function avancar(int $id, CarbonInterface $agora): bool
    {
        return DB::transaction(function () use ($id, $agora) {
            $combate = Combat::whereKey($id)->lockForUpdate()->first();

            if (! $combate || $combate->zone_id !== null) {
                return false;
            }

            $alvo = Colony::whereKey($combate->defender_colony_id)->lockForUpdate()->first();

            if (! $alvo) {
                $combate->update(['status' => 'expirado', 'proxima_rodada_at' => null]);

                return false;
            }

            $atacantes = $this->atacantes($combate);
            $defensores = $this->defensores($alvo);

            $fo = $this->forcas->ofensiva($combate);
            $fd = $defensores->sum(fn (Unit $u) => $u->defesa());

            /*
             * §27.7: o defensor que não estava à mesa quando a marcha começou dá bônus ao atacante.
             * O snapshot foi tirado no despacho, e não agora — desconectar depois não ajuda.
             */
            if (! ($combate->resultado['defensor_offline'] ?? false)) {
                // Sem o bônus, a defesa vale o que vale. Com ele, o atacante já entrou mais forte.
                $fd = (int) $fd;
            }

            $combate->status = 'em_curso';
            $combate->rodada = (int) $combate->rodada + 1;

            // Colônia sem uma unidade de pé: o cerco vence sem luta.
            if ($fd <= 0) {
                $this->saquear($combate, $alvo);

                return true;
            }

            if ($fo <= 0) {
                $this->repelido($combate);

                return true;
            }

            /*
             * ⚠️ A MESMA fórmula do `ResolverCombates` (§27.5): dano = (Fo/Ft) × 15% × F_alvo. Ver o
             * docblock da classe — se ela mudar lá, muda aqui.
             */
            $ft = $fo + $fd;
            $danoAoDefensor = intdiv($fo * Combat::DANO_BPS * $fd, $ft * self::CHEIO);
            $danoAoAtacante = intdiv($fd * Combat::DANO_BPS * $fo, $ft * self::CHEIO);

            $this->aplicarDano($defensores, $fd, $danoAoDefensor);
            $this->aplicarDano($atacantes, $fo, $danoAoAtacante);

            $atkRestante = $this->atacantes($combate)->sum(fn (Unit $u) => $u->ataque());
            $defRestante = $this->defensores($alvo)->sum(fn (Unit $u) => $u->defesa());

            if ($atkRestante <= 0) {
                $this->repelido($combate);

                return true;
            }

            if ($defRestante <= 0) {
                $this->saquear($combate, $alvo);

                return true;
            }

            $combate->proxima_rodada_at = $agora->copy()->addMinutes(10);
            $combate->save();

            return true;
        });
    }

    /**
     * O saque (decisões 13 e 14).
     *
     * ⚠️ **Só o excedente do Depósito**, e metade dele (§27.8). O protegido nunca é tocado — é o que
     * separa "a colônia é alvo" de "a colônia é destruída", e é a linha que a decisão 2 do D-193
     * traçou.
     *
     * A **Torre de Defesa** corta uma fatia do que seria levado, com teto: sem teto, uma Torre alta
     * zeraria o espólio e atacar viraria puro custo — a mecânica se desligaria sozinha.
     */
    private function saquear(Combat $combate, Colony $alvo): void
    {
        $config = WarSetting::singleton();

        $nivelTorre = (int) $alvo->buildings()->where('type', 'torre_de_defesa')->value('level');
        $reducao = min(
            (int) $config->torre_defesa_reducao_teto_bps,
            $nivelTorre * (int) $config->torre_defesa_reducao_bps_por_nivel,
        );

        $bps = intdiv(Combat::SAQUE_BPS * (self::CHEIO - $reducao), self::CHEIO);

        $atacante = Colony::whereKey($combate->attacker_colony_id)->lockForUpdate()->first();
        $levado = 0;
        $ref = "cerco_colonia:{$combate->id}";

        foreach ($alvo->resources()->lockForUpdate()->get() as $linha) {
            $exposto = $this->silo->exposto($alvo, $linha->resource_type, (int) $linha->amount);
            $qtd = intdiv($exposto * $bps, self::CHEIO);

            if ($qtd <= 0) {
                continue;
            }

            $linha->decrement('amount', $qtd);
            $atacante?->resources()->where('resource_type', $linha->resource_type)->increment('amount', $qtd);
            $levado += $qtd;

            /*
             * Duas pernas no ledger, como toda transferência: sai de um, entra no outro. E o sinal
             * decide a direção (D-164) — saque não é entrega comercial, então não há tributo (§25.2).
             */
            Ledger::create([
                'colony_id' => $alvo->id, 'type' => 'saque_sofrido', 'amount' => -$qtd,
                'resource_type' => $linha->resource_type, 'ref' => $ref, 'created_at' => now(),
            ]);
            Ledger::create([
                'colony_id' => $atacante->id, 'type' => 'saque_recebido', 'amount' => $qtd,
                'resource_type' => $linha->resource_type, 'ref' => $ref, 'created_at' => now(),
            ]);
        }

        $combate->status = 'vitoria_atacante';
        $combate->proxima_rodada_at = null;
        $combate->resultado = array_merge($combate->resultado ?? [], [
            'saque' => $levado,
            'reducao_torre_bps' => $reducao,
        ]);
        $combate->save();

        $this->liberar($combate);
        $this->registrar($combate, $alvo, $levado, $reducao);
    }

    /**
     * A telemetria que o D-193 pediu **junto** da fatia, e não depois.
     *
     * ⚠️ Ela carrega se o defensor estava ausente. É o número que dirá se a decisão de não proteger
     * quem some (decisão 12) está expulsando gente: se quem apanha ausente não volta, isso aparece
     * antes de virar êxodo — e o parâmetro de neutralidade ainda dá para mudar.
     */
    private function registrar(Combat $combate, Colony $alvo, int $levado, int $reducao): void
    {
        app(RegistrarEvento::class)->handle(
            'colonia_saqueada',
            $alvo->user,
            $alvo,
            [
                'combate' => $combate->id,
                'atacante' => $combate->attacker_colony_id,
                'levado' => $levado,
                'reducao_torre_bps' => $reducao,
                'defensor_offline' => (bool) ($combate->resultado['defensor_offline'] ?? false),
            ],
            origem: 'sistema',
            adiar: true,
        );
    }

    private function repelido(Combat $combate): void
    {
        $combate->status = 'defensor_venceu';
        $combate->proxima_rodada_at = null;
        $combate->save();

        $this->liberar($combate);
    }

    /** As unidades que sobreviveram voltam para casa; as mortas somem. */
    private function liberar(Combat $combate): void
    {
        Unit::where('combat_id', $combate->id)->where('hp_bps', '<=', 0)->delete();
        Unit::where('combat_id', $combate->id)->update([
            'combat_id' => null, 'status' => 'ociosa', 'arrives_at' => null,
        ]);
    }

    private function atacantes(Combat $combate): Collection
    {
        return Unit::where('combat_id', $combate->id)->where('hp_bps', '>', 0)->get();
    }

    /** Os defensores são as unidades ociosas da colônia — quem está em marcha não defende casa. */
    private function defensores(Colony $alvo): Collection
    {
        return Unit::where('colony_id', $alvo->id)
            ->whereNull('combat_id')
            ->where('hp_bps', '>', 0)
            ->get();
    }

    private function aplicarDano(Collection $unidades, int $forca, int $dano): void
    {
        if ($forca <= 0 || $dano <= 0) {
            return;
        }

        $fracaoBps = min(self::CHEIO, intdiv($dano * self::CHEIO, $forca));

        foreach ($unidades as $u) {
            $u->hp_bps = intdiv($u->hp_bps * (self::CHEIO - $fracaoBps), self::CHEIO);
            $u->save();
        }
    }
}
