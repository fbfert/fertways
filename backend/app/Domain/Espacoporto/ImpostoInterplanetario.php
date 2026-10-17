<?php

namespace App\Domain\Espacoporto;

use App\Domain\Embaixada\Credenciais;
use App\Domain\Endurance\EfeitosDaEndurance;
use App\Domain\Eventos\Modificadores;
use App\Models\Colony;
use App\Models\ResourceType;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * O imposto da carga interplanetária (D-269).
 *
 * O D-262 deixou escrito: "sem imposto sobre a carga interplanetária — lacuna, não zero escolhido".
 * A recomendação que o usuário aprovou é **a mesma régua do Mercado Central**: a alíquota do recurso
 * (`resource_types.tax_bps`), sobre o valor, mexida pelos eventos de taxa e pelo desconto de tributo
 * das peças da Endurance — que são, quase todas, peças de alfândega. Nada de alíquota nova.
 *
 * As duas pernas pagam, e quem paga é sempre o colono:
 * - o que o planeta **paga** pela carga é tributado na volta, como a venda no Mercado (`espacoporto_venda`);
 * - o que o colono **encomenda** é tributado na partida, junto com o preço (`espacoporto_importacao`).
 *
 * Tudo vai ao Tesouro. Sem isto, o Espaçoporto seria o único comércio do jogo livre de tributo — e o
 * caminho mais barato para mover valor.
 */
class ImpostoInterplanetario
{
    public function __construct(
        private readonly Modificadores $eventos,
        private readonly EfeitosDaEndurance $endurance,
        private readonly Credenciais $credenciais,
    ) {}

    /**
     * A alíquota, em bps, que esta colônia paga sobre este recurso agora.
     *
     * D-289: com o planeta, a credencial comercial da Embaixada corta a alíquota pelo desconto dela,
     * depois dos eventos e da Endurance — é um desconto sobre o que sobrou, não uma soma de descontos.
     */
    public function bps(Colony $colonia, string $recurso, CarbonInterface $quando, ?int $planetaId = null): int
    {
        $bps = (int) (ResourceType::find($recurso)?->tax_bps ?? 0);
        $bps = $this->eventos->aplicarEm($colonia, Modificadores::TAXA, $bps, $quando, $recurso);

        $bps = EfeitosDaEndurance::aplicarDesconto($bps, $this->endurance->descontoDeTributo($colonia));

        if ($planetaId !== null && ($desconto = $this->credenciais->descontoBps($colonia, $planetaId, $quando)) > 0) {
            $bps -= intdiv($bps * min(10_000, $desconto), 10_000);
        }

        return $bps;
    }

    /**
     * Calcula, por recurso, o imposto sobre uma perna {recurso: {qtd, preco_micro}}.
     *
     * @return array<string, array{base:int, bps:int, imposto:int}>
     */
    public function sobre(Colony $colonia, array $perna, CarbonInterface $quando, ?int $planetaId = null): array
    {
        $linhas = [];
        foreach ($perna as $recurso => $p) {
            $base = (int) $p['qtd'] * (int) $p['preco_micro'];
            $bps = $this->bps($colonia, (string) $recurso, $quando, $planetaId);
            $linhas[$recurso] = ['base' => $base, 'bps' => $bps, 'imposto' => intdiv($base * $bps, 10_000)];
        }

        return $linhas;
    }

    /** Registra o fato tributável; a chave única impede tributar duas vezes. */
    public function registrar(string $kind, string $chave, Colony $colonia, string $recurso, array $linha, CarbonInterface $quando): void
    {
        DB::table('tax_events')->insertOrIgnore([
            'economic_event_key' => $chave,
            'kind' => $kind,
            'colony_id' => $colonia->id,
            'resource_type' => $recurso,
            // Como em `mercado_venda`: a base é o valor em micro-Fert$, não o volume.
            'base_amount' => $linha['base'],
            'tax_bps' => $linha['bps'],
            'tax_amount' => $linha['imposto'],
            'created_at' => $quando,
        ]);
    }
}
