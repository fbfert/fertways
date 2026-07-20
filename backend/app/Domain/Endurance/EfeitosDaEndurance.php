<?php

namespace App\Domain\Endurance;

use App\Models\Colony;
use Illuminate\Support\Facades\DB;

/**
 * Os efeitos das peças da Endurance (D-135) — substitui `DescontoDeEndurance` (D-132), que só
 * tinha um efeito. Agora um item pode ter vários efeitos empilhados, e o vocabulário de
 * `tipo_efeito` é FECHADO: cada valor já está ligado num ponto do motor do jogo (não é texto
 * livre — o admin combina efeitos existentes pelo CRUD, não inventa mecânica nova).
 *
 * Cada tipo de efeito soma `valor_bps * quantidade possuída`, entre TODOS os itens da colônia que
 * o têm, com um teto agregado por tipo (arbitrado, sem base no GDD — documentado em D-135, mesma
 * filosofia do teto de 30% do D-132/D-133 para não deixar a economia sair do controle).
 */
final class EfeitosDaEndurance
{
    public const DESCONTO_TRIBUTO = 'desconto_tributo';

    public const PRODUCAO_BONUS = 'producao_bonus';

    public const VELOCIDADE_VEICULO = 'velocidade_veiculo';

    public const CAPACIDADE_VEICULO = 'capacidade_veiculo';

    public const DRONE_RAIO = 'drone_raio';

    public const DRONE_BATERIA = 'drone_bateria';

    /** Os 6 valores válidos de `tipo_efeito` — a única coisa que o painel deixa escolher. */
    public const TIPOS = [
        self::DESCONTO_TRIBUTO, self::PRODUCAO_BONUS, self::VELOCIDADE_VEICULO,
        self::CAPACIDADE_VEICULO, self::DRONE_RAIO, self::DRONE_BATERIA,
    ];

    /** Alvos coringa que cada tipo aceita além do alvo específico — 'global'/'todos' + a coisa em si. */
    public const ALVO_GLOBAL = 'global';

    public const ALVO_TODOS_OS_VEICULOS = 'todos';

    /** Tipos que exigem `alvo` preenchido — os outros ignoram (drone e tributo, sem alvo). */
    public const EXIGE_ALVO = [self::PRODUCAO_BONUS, self::VELOCIDADE_VEICULO, self::CAPACIDADE_VEICULO];

    /**
     * O teto agregado por tipo — quantas peças a colônia empilhar, nunca passa disto. Arbitrado
     * (D-135): 30% de tributo é o mesmo teto que o D-132/D-133 já usava; os demais são novos.
     */
    private const TETO_BPS = [
        self::DESCONTO_TRIBUTO => 3000,
        self::PRODUCAO_BONUS => 5000,
        self::VELOCIDADE_VEICULO => 5000,
        self::CAPACIDADE_VEICULO => 5000,
        self::DRONE_RAIO => 10_000,
        self::DRONE_BATERIA => 10_000,
    ];

    public function descontoDeTributo(Colony $colonia): int
    {
        return $this->somaBps($colonia, self::DESCONTO_TRIBUTO);
    }

    /** `$buildingType` é o `building_type` (ex.: `mina_local`, `industria_siderurgica`). */
    public function bonusDeProducao(Colony $colonia, string $buildingType): int
    {
        return $this->somaBps($colonia, self::PRODUCAO_BONUS, [$buildingType, self::ALVO_GLOBAL]);
    }

    /**
     * Todos os bônus de produção da colônia, batched numa query só — para o `ColonyTick`, que
     * soma taxa de VÁRIAS construções no mesmo delta (mesmo espírito anti-N+1 de
     * `manutencaoPorTipo()`, que já evita uma query por construção erguida).
     *
     * @return array<string,int> `building_type` (ou `'global'`) => soma de bps, SEM teto ainda —
     *                            quem chama soma o específico + o `'global'` e aplica
     *                            `min(EfeitosDaEndurance::tetoBps(PRODUCAO_BONUS), ...)`.
     */
    public function bonusDeProducaoPorAlvo(Colony $colonia): array
    {
        return DB::table('colony_endurance_items as p')
            ->join('endurance_item_effects as e', 'e.endurance_item_id', '=', 'p.endurance_item_id')
            ->where('p.colony_id', $colonia->id)
            ->where('e.tipo_efeito', self::PRODUCAO_BONUS)
            ->groupBy('e.alvo')
            ->selectRaw('e.alvo, SUM(e.valor_bps * p.quantidade) AS total')
            ->pluck('total', 'alvo')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    public static function tetoBps(string $tipoEfeito): int
    {
        return self::TETO_BPS[$tipoEfeito];
    }

    public function bonusDeVelocidade(Colony $colonia, string $tipoVeiculo): int
    {
        return $this->somaBps($colonia, self::VELOCIDADE_VEICULO, [$tipoVeiculo, self::ALVO_TODOS_OS_VEICULOS]);
    }

    public function bonusDeCapacidade(Colony $colonia, string $tipoVeiculo): int
    {
        return $this->somaBps($colonia, self::CAPACIDADE_VEICULO, [$tipoVeiculo, self::ALVO_TODOS_OS_VEICULOS]);
    }

    public function bonusDeDroneRaio(Colony $colonia): int
    {
        return $this->somaBps($colonia, self::DRONE_RAIO);
    }

    public function bonusDeDroneBateria(Colony $colonia): int
    {
        return $this->somaBps($colonia, self::DRONE_BATERIA);
    }

    /** @param array<string>|null $alvosAceitos null = não filtra por alvo (tipos sem alvo) */
    private function somaBps(Colony $colonia, string $tipoEfeito, ?array $alvosAceitos = null): int
    {
        $query = DB::table('colony_endurance_items as p')
            ->join('endurance_item_effects as e', 'e.endurance_item_id', '=', 'p.endurance_item_id')
            ->where('p.colony_id', $colonia->id)
            ->where('e.tipo_efeito', $tipoEfeito);

        if ($alvosAceitos !== null) {
            $query->whereIn('e.alvo', $alvosAceitos);
        }

        $soma = (int) $query->selectRaw('SUM(e.valor_bps * p.quantidade) AS total')->value('total');

        return min(self::TETO_BPS[$tipoEfeito], max(0, $soma));
    }

    /** Reduz um valor cheio pelo desconto (mesmo formato do D-120/D-132). */
    public static function aplicarDesconto(int $cheio, int $descontoBps): int
    {
        if ($descontoBps <= 0) {
            return $cheio;
        }

        return intdiv($cheio * (10_000 - $descontoBps), 10_000);
    }

    /** Aumenta um valor cheio pelo bônus. */
    public static function aplicarBonus(int $cheio, int $bonusBps): int
    {
        if ($bonusBps <= 0) {
            return $cheio;
        }

        return intdiv($cheio * (10_000 + $bonusBps), 10_000);
    }
}
