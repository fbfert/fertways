<?php

namespace App\Domain\Admin;

use App\Domain\Logistics\MapaFertways;
use App\Domain\Logistics\ZonasNeutras;
use App\Exceptions\DomainRuleException;
use App\Models\FoundingCell;

/**
 * Liga ou desliga uma célula de periferia na lista de fundação (D-147). **Só o dono.**
 *
 * Ao contrário de `RealocarColonia`, esta ação não mexe em nenhuma colônia já existente — só
 * decide onde um jogador NOVO poderá escolher fundar daqui pra frente. É reversível com um
 * segundo clique na mesma célula, e é por isso que não exige motivo nem palavra de confirmação: a
 * fricção do D-61 é para o que é difícil de desfazer, e isto não é.
 *
 * **Só a periferia entra na lista.** O disco de founders (D-51, 48 células, 20 reservadas por
 * fórmula) continua com a regra automática de sempre — esta ferramenta rejeita até uma tentativa
 * de marcar uma célula do disco, para a lista nunca divergir da regra que `podeFundar()` já aplica
 * a ele. Capital, anel livre e zona neutra são travas estruturais: nem chegam a ser avaliadas
 * como "periferia", então nunca entram na lista de nenhum jeito.
 */
class AlternarCelulaDeFundacao
{
    public function __construct(
        private readonly Auditoria $auditoria,
    ) {}

    /** @return bool o novo estado — true se a célula ficou liberada, false se foi trancada */
    public function handle(int $x, int $y): bool
    {
        if (! MapaFertways::dentroDoMapa($x, $y)) {
            throw new DomainRuleException('fora_do_mapa', 'Esta célula não existe na grade.');
        }

        if (MapaFertways::ehCapital($x, $y)) {
            throw new DomainRuleException('celula_da_capital', 'A Capital nunca é fundável.');
        }

        if (ZonasNeutras::ehZonaNeutra($x, $y)) {
            throw new DomainRuleException('celula_de_zona_neutra', 'Zona neutra nunca é fundável.');
        }

        if (MapaFertways::faixaDe($x, $y) !== 'periferia') {
            throw new DomainRuleException(
                'nao_e_periferia',
                'Só a periferia entra nesta lista — o disco de founders segue a regra de sempre.',
            );
        }

        $existente = FoundingCell::where('x', $x)->where('y', $y)->first();

        if ($existente) {
            $existente->delete();
            $this->auditoria->registrar(
                'fundacao.trancar',
                "Fechou a célula ({$x}, {$y}) para fundação.",
                null,
                ['x' => $x, 'y' => $y],
                null,
            );

            return false;
        }

        FoundingCell::create(['x' => $x, 'y' => $y]);
        $this->auditoria->registrar(
            'fundacao.liberar',
            "Liberou a célula ({$x}, {$y}) para fundação.",
            null,
            null,
            ['x' => $x, 'y' => $y],
        );

        return true;
    }
}
