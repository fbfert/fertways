<?php

namespace Tests;

use App\Domain\Colony\Slots;
use App\Models\Building;
use App\Models\Colony;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Põe uma construção erguida na colônia, no primeiro slot livre.
     *
     * Antes do D-59 os testes faziam `$colony->buildings->firstWhere('type', 'oficina')` e
     * confiavam em que a fundação criasse as 16 no nível 0. Agora **construção não erguida não
     * existe**: a linha nasce quando o colono escolhe o slot. Este helper é o equivalente de teste
     * desse gesto — ergue direto, sem passar pela fila nem pagar custo, que é o que a maioria dos
     * testes quer (eles estão testando outra coisa).
     *
     * Para as essenciais, que já nascem no miolo, devolve a que existe e só ajusta o nível.
     */
    protected function erguerPredio(Colony $colony, string $tipo, int $nivel = 1): Building
    {
        $existente = $colony->buildings()->where('type', $tipo)->first();

        if ($existente && ! in_array($tipo, Building::REPETIVEIS, true)) {
            $existente->update(['level' => $nivel]);

            return $existente->fresh();
        }

        $ocupados = $colony->buildings()->pluck('slot')->all();
        $slot = collect(Slots::livres())->first(fn (int $s) => ! in_array($s, $ocupados, true));

        return $colony->buildings()->create(['type' => $tipo, 'level' => $nivel, 'slot' => $slot]);
    }

    /**
     * A linha de `buildings` sobre a qual se pede um upgrade.
     *
     * Essencial: já existe, no nível 1, no miolo — devolve-se como está, e o próximo upgrade é o
     * nível 2. Progressão: ainda não existe (D-59), então cria-se no nível 0 num slot livre, que é
     * o estado em que o `ConstruirEmSlot` a entrega ao `EnqueueUpgrade`.
     */
    protected function predioDe(Colony $colony, string $tipo): Building
    {
        return $colony->buildings()->where('type', $tipo)->first()
            ?? $this->erguerPredio($colony, $tipo, 0);
    }

    /**
     * Derruba as cinco essenciais ao nível 0, sem tirá-las do miolo.
     *
     * Desde o D-59 toda colônia nasce produzindo — 100 oxigênio/h, 80 água/h, 60 biomassa/h, 88
     * de energia líquida. Isso é o desenho, e há teste para ele. Mas um teste que quer medir **uma**
     * construção (a Destilaria converte 2 biomassas em 1 biocombustível?) não pode ter a Fazenda
     * despejando biomassa no meio da conta. Aqui o miolo é desligado para que o que se mede seja o
     * que se quer medir. O tick ignora nível 0: nem produz, nem consome.
     */
    protected function zerarMiolo(Colony $colony): void
    {
        $colony->buildings()->whereIn('type', Building::ESSENCIAIS)->update(['level' => 0]);
    }
}
