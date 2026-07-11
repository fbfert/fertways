<?php

namespace App\Console\Commands;

use App\Domain\Finance\DeclararIntervencao;
use App\Exceptions\DomainRuleException;
use App\Models\Colony;
use App\Models\PriceIntervention;
use App\Models\ResourceType;
use Illuminate\Console\Command;

/**
 * Secretaria de Finanças (slot 4): declara e administra intervenções de preço no Mercado (§06).
 *
 * **Por que artisan e não uma rota.** O §02 diz que o Governo é "operado pela equipe"; a Secretaria
 * "só altera teto/piso mediante registro público de motivo, período e impacto". Isso é ato de
 * governo, não de colono — mesmo molde do `fertways:conciliador` (D-44). Enquanto vigente, o Mercado
 * rejeita ordens fora da faixa (ver App\Domain\Market\ColocarOrdem). Ver D-35 e docs/decisoes.md.
 *
 * O teto e o piso entram em Fert$ (ex.: 0.05) e são guardados em micro-Fert$. Qualquer um pode ficar
 * de fora — dá para pôr só teto ou só piso.
 *
 *   artisan fertways:intervencao metal_bruto --teto=0.05 --motivo="pico especulativo" --dias=7
 *   artisan fertways:intervencao agua --piso=0.004 --motivo="proteger produtores" --dias=3
 *   artisan fertways:intervencao metal_bruto --revogar
 *   artisan fertways:intervencao --listar
 */
class Intervencao extends Command
{
    protected $signature = 'fertways:intervencao
        {recurso? : o code do recurso (ex.: metal_bruto)}
        {--teto= : teto de preço em Fert$}
        {--piso= : piso de preço em Fert$}
        {--motivo= : registro público do motivo}
        {--dias=7 : prazo de vigência, em dias}
        {--revogar : encerra a intervenção vigente do recurso}
        {--listar}';

    protected $description = 'Declara e administra intervenções de preço da Secretaria de Finanças';

    public function handle(): int
    {
        if ($this->option('listar')) {
            return $this->listar();
        }

        $recurso = $this->argument('recurso');

        if (! $recurso || ! ResourceType::whereKey($recurso)->exists()) {
            $this->error("Recurso desconhecido: {$recurso}");

            return self::FAILURE;
        }

        if ($this->option('revogar')) {
            return $this->revogar($recurso);
        }

        return $this->declarar($recurso);
    }

    private function declarar(string $recurso): int
    {
        $teto = $this->emMicro($this->option('teto'));
        $piso = $this->emMicro($this->option('piso'));
        $motivo = (string) $this->option('motivo');
        $dias = (int) $this->option('dias');

        try {
            $intervencao = app(DeclararIntervencao::class)->declarar($recurso, $teto, $piso, $motivo, $dias);
        } catch (DomainRuleException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Intervenção #{$intervencao->id} em {$recurso}: "
            .'piso '.$this->emFert($piso).', teto '.$this->emFert($teto)
            .", por {$dias} dia(s). Motivo: {$motivo}");

        return self::SUCCESS;
    }

    private function revogar(string $recurso): int
    {
        $n = app(DeclararIntervencao::class)->revogar($recurso);

        $this->info($n > 0
            ? "Revogadas {$n} intervenção(ões) vigente(s) de {$recurso}."
            : "Nenhuma intervenção vigente de {$recurso}.");

        return self::SUCCESS;
    }

    private function listar(): int
    {
        $vigentes = PriceIntervention::query()->vigentes()->orderBy('resource_type')->get();

        if ($vigentes->isEmpty()) {
            $this->line('Nenhuma intervenção vigente.');

            return self::SUCCESS;
        }

        $this->table(
            ['#', 'Recurso', 'Piso', 'Teto', 'Expira', 'Motivo'],
            $vigentes->map(fn (PriceIntervention $i) => [
                $i->id,
                $i->resource_type,
                $this->emFert($i->floor_micro),
                $this->emFert($i->ceil_micro),
                $i->expires_at->format('Y-m-d H:i'),
                $i->reason,
            ])->all(),
        );

        return self::SUCCESS;
    }

    /** Fert$ (string da linha de comando) → micro-Fert$ inteiro, ou null se ausente. */
    private function emMicro(?string $fert): ?int
    {
        if ($fert === null || $fert === '') {
            return null;
        }

        return (int) round(((float) $fert) * Colony::MICRO_POR_FERT);
    }

    private function emFert(?int $micro): string
    {
        return $micro === null ? '—' : number_format($micro / Colony::MICRO_POR_FERT, 4, ',', '.').' F$';
    }
}
