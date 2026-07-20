<?php

namespace App\Console\Commands;

use App\Domain\Cargos\CargosCivicosSpecs;
use App\Domain\Cargos\ConfirmarSinalizacao;
use App\Domain\Cargos\GerirCargoCivico;
use App\Models\CivicFlag;
use App\Models\CivicPost;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Nomeia, suspende, reintegra e demite os Cargos Públicos do §14.2 (D-130) — os 3 que não são o
 * Conciliador (esse continua em `fertways:conciliador`). Confirma sinalizações do Fiscal de
 * Mercado e do Auxiliar de Tesouro, que é quando o bônus paga.
 *
 * **Por que artisan e não uma rota.** Mesmo motivo do Conciliador: o §14.2 exige um índice de
 * reputação "alto" por cargo, e o GDD nunca publica o número. Enquanto não houver esse número, o
 * cargo é ligado à mão pelo operador (D-44, D-130).
 *
 *   artisan fertways:cargo-civico fb reporter --nomear
 *   artisan fertways:cargo-civico fb fiscal_de_mercado --suspender
 *   artisan fertways:cargo-civico fb auxiliar_de_tesouro --demitir
 *   artisan fertways:cargo-civico --listar
 *   artisan fertways:cargo-civico --listar-sinalizacoes
 *   artisan fertways:cargo-civico --confirmar-sinalizacao=3
 */
class CargoCivico extends Command
{
    protected $signature = 'fertways:cargo-civico
        {nickname? : o colono}
        {kind? : reporter | fiscal_de_mercado | auxiliar_de_tesouro}
        {--nomear}
        {--demitir}
        {--suspender}
        {--reintegrar}
        {--listar}
        {--listar-sinalizacoes}
        {--confirmar-sinalizacao= : id da sinalização}';

    protected $description = 'Nomeia e administra os Cargos Públicos do §14.2 (D-130)';

    public function handle(): int
    {
        if ($this->option('listar')) {
            return $this->listar();
        }

        if ($this->option('listar-sinalizacoes')) {
            return $this->listarSinalizacoes();
        }

        if ($this->option('confirmar-sinalizacao') !== null) {
            return $this->confirmar((int) $this->option('confirmar-sinalizacao'));
        }

        $nickname = $this->argument('nickname');
        $kind = $this->argument('kind');
        $colono = $nickname ? User::where('nickname', $nickname)->first() : null;

        if (! $colono) {
            $this->error("Colono não encontrado: {$nickname}");

            return self::FAILURE;
        }

        if (! in_array($kind, CargosCivicosSpecs::KINDS, true)) {
            $this->error('Cargo inválido. Use: '.implode(', ', CargosCivicosSpecs::KINDS));

            return self::FAILURE;
        }

        return match (true) {
            (bool) $this->option('nomear') => $this->nomear($colono, $kind),
            (bool) $this->option('demitir') => $this->demitir($colono, $kind),
            (bool) $this->option('suspender') => $this->suspender($colono, $kind),
            (bool) $this->option('reintegrar') => $this->reintegrar($colono, $kind),
            default => $this->mostrar($colono, $kind),
        };
    }

    private function nomear(User $colono, string $kind): int
    {
        if (! app(GerirCargoCivico::class)->nomear($colono, $kind)) {
            $this->warn("{$colono->nickname} já ocupa {$this->nome($kind)}.");

            return self::SUCCESS;
        }

        $this->info("{$colono->nickname} é {$this->nome($kind)}. 50 Fert$/dia (§14.2/§26.7).");

        return self::SUCCESS;
    }

    private function demitir(User $colono, string $kind): int
    {
        app(GerirCargoCivico::class)->demitir($colono, $kind);
        $this->info("{$colono->nickname} não ocupa mais {$this->nome($kind)}.");

        return self::SUCCESS;
    }

    private function suspender(User $colono, string $kind): int
    {
        app(GerirCargoCivico::class)->suspender($colono, $kind);
        $this->info("{$colono->nickname} está suspenso de {$this->nome($kind)}.");

        return self::SUCCESS;
    }

    private function reintegrar(User $colono, string $kind): int
    {
        app(GerirCargoCivico::class)->reintegrar($colono, $kind);
        $this->info("{$colono->nickname} volta a {$this->nome($kind)}.");

        return self::SUCCESS;
    }

    private function mostrar(User $colono, string $kind): int
    {
        $cargo = CivicPost::where('user_id', $colono->id)->where('kind', $kind)->first();

        $this->line("{$colono->nickname} · {$this->nome($kind)}: ".($cargo ? 'ocupa' : 'não ocupa'));

        if ($cargo) {
            $this->line('  desde: '.$cargo->desde);
            $this->line('  suspenso: '.($cargo->suspenso_em ?? 'não'));
        }

        return self::SUCCESS;
    }

    private function listar(): int
    {
        $cargos = CivicPost::with('user:id,nickname')->orderBy('kind')->orderBy('id')->get();

        if ($cargos->isEmpty()) {
            $this->warn('Nenhum cargo cívico ocupado.');

            return self::SUCCESS;
        }

        $this->table(
            ['colono', 'cargo', 'desde', 'suspenso'],
            $cargos->map(fn (CivicPost $c) => [
                $c->user?->nickname ?? "user #{$c->user_id}",
                $this->nome($c->kind),
                $c->desde,
                $c->suspenso_em ?? '—',
            ])->all(),
        );

        return self::SUCCESS;
    }

    private function listarSinalizacoes(): int
    {
        $flags = CivicFlag::with('user:id,nickname')
            ->whereNull('confirmado_em')
            ->orderBy('id')
            ->get();

        if ($flags->isEmpty()) {
            $this->warn('Nenhuma sinalização pendente.');

            return self::SUCCESS;
        }

        $this->table(
            ['id', 'colono', 'cargo', 'motivo', 'em'],
            $flags->map(fn (CivicFlag $f) => [
                $f->id,
                $f->user?->nickname ?? "user #{$f->user_id}",
                $this->nome($f->kind),
                mb_substr($f->motivo, 0, 60),
                $f->created_at,
            ])->all(),
        );

        return self::SUCCESS;
    }

    private function confirmar(int $id): int
    {
        $flag = app(ConfirmarSinalizacao::class)->handle($id);
        $this->info("Sinalização #{$flag->id} confirmada. Bônus pago, sujeito ao teto semanal (§14.2).");

        return self::SUCCESS;
    }

    private function nome(string $kind): string
    {
        return CargosCivicosSpecs::NOMES[$kind] ?? $kind;
    }
}
