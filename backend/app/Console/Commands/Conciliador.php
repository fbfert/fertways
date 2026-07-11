<?php

namespace App\Console\Commands;

use App\Domain\Ministry\GerirConciliador;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Nomeia, suspende, reintegra e demite conciliadores (GDD §9.3, §26.6).
 *
 * **Por que artisan e não uma rota.** O §26.6 exige "Conduta Social alta + Status Cívico alto" para
 * o cargo, e Conduta Social só se move por chat (§26.2), que não existe. Enquanto não houver
 * substrato para a elegibilidade, o cargo é ligado à mão pelo operador (D-44). No dia em que o chat
 * existir, este comando vira o painel do §14.4 e a elegibilidade passa a ser conferida aqui.
 *
 * Ser conciliador **é** ser Neutro Registrado: "Neutro Registrado é exclusivo do Conciliador"
 * (tabela de precedência da seção 0). Não há segundo status a conceder.
 *
 *   artisan fertways:conciliador fb --nomear
 *   artisan fertways:conciliador fb --reintegrar   # zera o contador de reversões do §26.7
 *   artisan fertways:conciliador fb --demitir
 *   artisan fertways:conciliador --listar
 */
class Conciliador extends Command
{
    protected $signature = 'fertways:conciliador
        {nickname? : o colono}
        {--nomear}
        {--demitir}
        {--reintegrar : levanta a suspensão e zera as reversões}
        {--listar}';

    protected $description = 'Nomeia e administra conciliadores do Ministério das Reputações';

    public function handle(): int
    {
        if ($this->option('listar')) {
            return $this->listar();
        }

        $nickname = $this->argument('nickname');
        $colono = $nickname ? User::where('nickname', $nickname)->first() : null;

        if (! $colono) {
            $this->error("Colono não encontrado: {$nickname}");

            return self::FAILURE;
        }

        return match (true) {
            (bool) $this->option('nomear') => $this->nomear($colono),
            (bool) $this->option('demitir') => $this->demitir($colono),
            (bool) $this->option('reintegrar') => $this->reintegrar($colono),
            default => $this->mostrar($colono),
        };
    }

    private function nomear(User $colono): int
    {
        if (! app(GerirConciliador::class)->nomear($colono)) {
            $this->warn("{$colono->nickname} já é conciliador desde {$colono->conciliador_desde}.");

            return self::SUCCESS;
        }

        $this->info("{$colono->nickname} é conciliador. Neutro Registrado, 50 Fert$/dia (§26.7).");

        return self::SUCCESS;
    }

    private function demitir(User $colono): int
    {
        app(GerirConciliador::class)->demitir($colono);

        $this->info("{$colono->nickname} não é mais conciliador. Reversões acumuladas: {$colono->reversoes}.");

        return self::SUCCESS;
    }

    private function reintegrar(User $colono): int
    {
        app(GerirConciliador::class)->reintegrar($colono);

        $this->info("{$colono->nickname} volta ao cargo, com o contador de reversões zerado.");

        return self::SUCCESS;
    }

    private function mostrar(User $colono): int
    {
        $this->line("{$colono->nickname}: ".($colono->conciliador_desde ? 'conciliador' : 'não é conciliador'));
        $this->line("  suspenso: ".($colono->conciliador_suspenso_em ?? 'não'));
        $this->line("  reversões: {$colono->reversoes}");
        $this->line("  Confiança Comercial {$colono->confianca_comercial} · Conduta Social {$colono->conduta_social}");
        $this->line("  Status Cívico {$colono->status_civico} · Honra Mil./Dipl. {$colono->honra_militar_diplomatica}");

        return self::SUCCESS;
    }

    private function listar(): int
    {
        $conciliadores = User::whereNotNull('conciliador_desde')->orderBy('id')->get();

        if ($conciliadores->isEmpty()) {
            $this->warn('Nenhum conciliador. Todo caso sobe à equipe (§9.3).');

            return self::SUCCESS;
        }

        $this->table(
            ['colono', 'desde', 'reversões', 'suspenso'],
            $conciliadores->map(fn (User $u) => [
                $u->nickname,
                $u->conciliador_desde,
                "{$u->reversoes}/".\App\Domain\Ministry\PunicaoSpecs::LIMITE_REVERSOES,
                $u->conciliador_suspenso_em ?? '—',
            ])->all(),
        );

        return self::SUCCESS;
    }
}
