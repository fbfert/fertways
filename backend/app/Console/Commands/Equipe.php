<?php

namespace App\Console\Commands;

use App\Domain\Ministry\Apelacao;
use App\Domain\Ministry\DecidirCaso;
use App\Domain\Ministry\PunicaoSpecs;
use App\Models\Report;
use Illuminate\Console\Command;

/**
 * A "equipe do jogo" do §9.2 e do §9.3, que julga casos graves, casos órfãos de conciliador e
 * apelações.
 *
 * Ela é o **operador**, fora do jogo (D-44): com quatro colônias no servidor, era a única leitura de
 * "equipe" que funciona. Por isso um comando de artisan, e não uma rota — expor isto na API daria a
 * um colono o poder de suspender conciliadores.
 *
 *   artisan fertways:equipe --fila
 *   artisan fertways:equipe 12 --procedente
 *   artisan fertways:equipe 12 --improcedente
 *   artisan fertways:equipe 12 --manter      # apelação: a decisão do conciliador fica de pé
 *   artisan fertways:equipe 12 --reverter    # apelação: estorna a punição e conta a reversão
 */
class Equipe extends Command
{
    protected $signature = 'fertways:equipe
        {report? : id da denúncia}
        {--fila : lista o que espera a equipe}
        {--procedente}
        {--improcedente}
        {--manter}
        {--reverter}';

    protected $description = 'A equipe do jogo julga casos graves e apelações do Ministério';

    public function handle(DecidirCaso $decidir, Apelacao $apelacao): int
    {
        if ($this->option('fila')) {
            return $this->fila();
        }

        $denuncia = Report::find($this->argument('report'));

        if (! $denuncia) {
            $this->error('Denúncia não encontrada. Use --fila para ver o que espera a equipe.');

            return self::FAILURE;
        }

        $spec = PunicaoSpecs::violacao($denuncia->violation);

        try {
            match (true) {
                (bool) $this->option('procedente') => $this->julgado($decidir->pelaEquipe($denuncia, true), $spec),
                (bool) $this->option('improcedente') => $this->julgado($decidir->pelaEquipe($denuncia, false), $spec),
                (bool) $this->option('manter') => $this->mantida($apelacao->manter($denuncia)),
                (bool) $this->option('reverter') => $this->revertida($apelacao->reverter($denuncia)),
                default => $this->mostrar($denuncia, $spec),
            };
        } catch (\App\Exceptions\DomainRuleException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function fila(): int
    {
        $casos = Report::whereIn('status', ['na_equipe', 'apelado'])->orderBy('id')->get();

        if ($casos->isEmpty()) {
            $this->info('Nada espera a equipe.');

            return self::SUCCESS;
        }

        $this->table(
            ['id', 'status', 'violação', 'grave', 'denunciante', 'denunciado'],
            $casos->map(fn (Report $r) => [
                $r->id,
                $r->status,
                $r->violation,
                $r->grave ? 'sim' : '—',
                $r->reporter?->name ?? $r->reporter_colony_id,
                $r->accused?->name ?? $r->accused_colony_id,
            ])->all(),
        );

        return self::SUCCESS;
    }

    private function mostrar(Report $r, array $spec): void
    {
        $this->line("denúncia {$r->id}: {$r->violation} ({$spec['fonte']})");
        $this->line("  status {$r->status} · decisão ".($r->decision ?? '—'));
        $this->line("  evidência: {$r->evidence_type}, acordo ".($r->trade_agreement_id ?? '—'));
        $this->line('  se procedente: '.implode(' + ', $spec['punicoes'])." em {$spec['indice']} ({$spec['pontos']})");
        $this->newLine();
        $this->line($r->texto);
    }

    private function julgado(Report $r, array $spec): void
    {
        $this->info("denúncia {$r->id}: {$r->decision}.");

        if ($r->decision === 'procedente') {
            $this->line('  aplicado: '.implode(' + ', $spec['punicoes'])." em {$spec['indice']} ({$spec['pontos']} pontos)");
        }

        $this->line("  janela de apelação até {$r->appeal_until}.");
    }

    private function mantida(Report $r): void
    {
        $this->info("denúncia {$r->id}: decisão mantida. O conciliador recebe o bônus no próximo tick.");
    }

    private function revertida(Report $r): void
    {
        $this->info("denúncia {$r->id}: decisão revertida. Punição estornada, sem bônus.");

        $c = $r->conciliator;

        if ($c) {
            $this->line("  {$c->nickname}: {$c->reversoes}/".PunicaoSpecs::LIMITE_REVERSOES.' reversões'
                .($c->conciliador_suspenso_em ? ' — SUSPENSO' : ''));
        }
    }
}
