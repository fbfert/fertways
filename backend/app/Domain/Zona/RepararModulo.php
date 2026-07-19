<?php

namespace App\Domain\Zona;

use App\Domain\Building\BuildingSpecs;
use App\Exceptions\DomainRuleException;
use App\Models\Colony;
use App\Models\Ledger;
use App\Models\NeutralZone;
use App\Models\WarSetting;
use Illuminate\Support\Facades\DB;

/**
 * Repara uma estrutura sabotada, ou resgata antecipadamente uma apreendida (§28.10; D-118).
 *
 * As duas portas do "Módulo Operacional" (D-66) — "que pode ser rastreado, recuperado e reparado"
 * — convergem aqui:
 *
 *  - **Sabotagem (Infiltrador)**: sem prazo automático — o §28.10 só promete as 24h para a
 *    Apreensão. Sem `RepararModulo`, a estrutura fica degradada para sempre.
 *  - **Apreensão (Predador)**: já repara sozinha em 24h (`ExpirarApreensoes`, no tick), de graça.
 *    Isto só serve para reaver ANTES do prazo, pagando.
 *
 * O custo é o mesmo dos dois casos: uma fração (`WarSetting::reparo_bps_do_custo`, 10% por padrão)
 * do custo de CONSTRUÇÃO da estrutura no nível atual — não é número novo, é o mesmo gancho que a
 * manutenção de veículos do Ministério dos Transportes já usa (D-60).
 */
class RepararModulo
{
    public function __construct(private readonly BuildingSpecs $specs) {}

    public function handle(Colony $colony, NeutralZone $zona, string $estrutura): void
    {
        if (! array_key_exists($estrutura, Estruturas::COLUNA)) {
            throw new DomainRuleException('estrutura_invalida', "Estrutura desconhecida: {$estrutura}.");
        }

        DB::transaction(function () use ($colony, $zona, $estrutura) {
            $colony = Colony::whereKey($colony->id)->lockForUpdate()->firstOrFail();
            $zona = NeutralZone::whereKey($zona->id)->lockForUpdate()->firstOrFail();

            if ($zona->owner_colony_id !== $colony->id) {
                throw new DomainRuleException('zona_nao_e_sua', 'Esta zona neutra não é sua.');
            }

            $apreendida = in_array($estrutura, $zona->modules_offline ?? [], true);
            $sabotada = array_key_exists($estrutura, $zona->structures_saboted ?? []);

            if (! $apreendida && ! $sabotada) {
                $nome = Estruturas::de($estrutura)['nome'];

                throw new DomainRuleException('nada_a_reparar', "{$nome} está operando normalmente.");
            }

            $nivel = (int) $zona->{Estruturas::COLUNA[$estrutura]};

            $this->cobrar($colony, $zona, $estrutura, $this->custo($estrutura, $nivel));

            if ($apreendida) {
                $offline = array_values(array_diff($zona->modules_offline ?? [], [$estrutura]));
                $expira = $zona->modules_offline_expira_em ?? [];
                unset($expira[$estrutura]);

                $zona->update([
                    'modules_offline' => $offline === [] ? null : $offline,
                    'modules_offline_expira_em' => $expira === [] ? null : $expira,
                ]);
            } else {
                $sabotadas = $zona->structures_saboted ?? [];
                unset($sabotadas[$estrutura]);

                $zona->update(['structures_saboted' => $sabotadas === [] ? null : $sabotadas]);
            }
        });
    }

    /**
     * Quanto custa reparar `$estrutura` no nível `$nivel`, em recursos — pública para a API mostrar
     * o preço ANTES do colono confirmar.
     *
     * @return array<string,int>
     */
    public function custo(string $estrutura, int $nivel): array
    {
        $base = $this->specs->para($estrutura, $nivel)['custo'];
        $bps = WarSetting::singleton()->reparo_bps_do_custo;

        $custo = [];

        foreach ($base as $recurso => $qtd) {
            // Arredonda para CIMA, como a manutenção de veículos (D-60): serviço nenhum sai de
            // graça por truncamento.
            $custo[$recurso] = (int) ceil($qtd * $bps / 10_000);
        }

        return $custo;
    }

    /** @param  array<string,int>  $custo */
    private function cobrar(Colony $colony, NeutralZone $zona, string $estrutura, array $custo): void
    {
        foreach ($custo as $recurso => $qtd) {
            if ($qtd <= 0) {
                continue;
            }

            // `where amount >= qtd` no UPDATE: o estoque nunca fica negativo, nem em corrida.
            $baixou = $colony->resources()
                ->where('resource_type', $recurso)
                ->where('amount', '>=', $qtd)
                ->decrement('amount', $qtd);

            if ($baixou === 0) {
                throw new DomainRuleException(
                    'recursos_insuficientes',
                    'A sua colônia não tem os recursos do reparo.',
                );
            }

            Ledger::create([
                'colony_id' => $colony->id,
                'type' => 'reparo_de_modulo',
                'amount' => -$qtd,
                'resource_type' => $recurso,
                'ref' => "zona:{$zona->id}:{$estrutura}",
                'created_at' => now(),
            ]);
        }
    }
}
