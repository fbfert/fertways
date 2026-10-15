<?php

namespace App\Domain\Espacoporto;

use App\Domain\Marco\Curva;
use App\Models\Colony;
use App\Models\ContratoDeRota;
use App\Models\PlanetaNpc;
use Illuminate\Support\Facades\DB;

/**
 * O Espaçoporto da Capital, área Sul (GDD v35 §03, §05, §16.2, §21.10; D-262): o estado, e as regras
 * que o contrato e a tela leem — uma conta só para as duas.
 *
 * ## Ligado é ter TODOS os números do operador
 *
 * O GDD não publica preço, aluguel, capacidade nem frota. Enquanto qualquer um deles estiver vazio, o
 * Espaçoporto está fechado — e a tela diz qual falta, porque "fechado" sem motivo parece defeito.
 */
class Espacoporto
{
    /** §05, marco 20 (Desbravador): "cargueiro interplanetário (mín. 10 dias)". */
    public const MARCO_DO_CARGUEIRO = 20;

    public const DIAS_DO_CARGUEIRO = 10;

    public function config(): object
    {
        return DB::table('espacoporto_settings')->find(1);
    }

    /** O motivo de estar fechado, ou nulo se está aberto ao aluguel. */
    public function motivoFechado(): ?string
    {
        $c = $this->config();

        return match (true) {
            ! $c || ! $c->ativo => 'O Ministério dos Transportes não abriu o Cargueiro para aluguel.',
            $c->aluguel_micro === null => 'O aluguel do Cargueiro ainda não foi definido.',
            ! $c->capacidade_unidades => 'A capacidade do Cargueiro ainda não foi definida.',
            ! $c->cargueiros => 'A frota de Cargueiros do Governo ainda não foi definida.',
            default => null,
        };
    }

    public function cargueirosLivres(): int
    {
        return max(0, (int) $this->config()->cargueiros
            - ContratoDeRota::where('status', ContratoDeRota::EM_VIAGEM)->count());
    }

    /** @return array{pode:bool, marco:int, marco_exigido:int, dias:int, dias_exigidos:int} */
    public function portao(Colony $colonia): array
    {
        $marco = Curva::marco((int) $colonia->xp);
        $dias = (int) floor($colonia->created_at->diffInDays(now()));

        return [
            'pode' => $marco >= self::MARCO_DO_CARGUEIRO && $dias >= self::DIAS_DO_CARGUEIRO,
            'marco' => $marco,
            'marco_exigido' => self::MARCO_DO_CARGUEIRO,
            'dias' => $dias,
            'dias_exigidos' => self::DIAS_DO_CARGUEIRO,
        ];
    }

    /** @return array<string,mixed> o que a tela do Espaçoporto mostra */
    public function estado(Colony $colonia): array
    {
        $c = $this->config();
        $motivo = $this->motivoFechado();

        return [
            'ligado' => $motivo === null,
            'motivo_fechado' => $motivo,
            'aluguel_micro' => $c?->aluguel_micro !== null ? (int) $c->aluguel_micro : null,
            'capacidade_unidades' => $c?->capacidade_unidades !== null ? (int) $c->capacidade_unidades : null,
            'cargueiros' => $c?->cargueiros !== null ? (int) $c->cargueiros : null,
            'cargueiros_livres' => $motivo === null ? $this->cargueirosLivres() : 0,
            'portao' => $this->portao($colonia),
            'planetas' => PlanetaNpc::with('precos')->orderBy('ordem')->get()->map(fn (PlanetaNpc $p) => [
                'chave' => $p->chave,
                'nome' => $p->nome,
                'distancia_minutos' => $p->distancia_minutos,
                'risco' => $p->risco,
                'exporta' => $p->exporta,
                'importa' => $p->importa,
                'aberto' => $p->aberto,
                // O que o planeta paga pelo que você leva, e o que cobra pelo que você traz.
                'compra' => $p->precos->whereNotNull('compra_micro')->pluck('compra_micro', 'resource_type'),
                'venda' => $p->precos->whereNotNull('venda_micro')->pluck('venda_micro', 'resource_type'),
            ])->values(),
            'contratos' => ContratoDeRota::with('planeta:id,nome')
                ->where('colony_id', $colonia->id)
                ->orderByDesc('id')->limit(10)->get()
                ->map(fn (ContratoDeRota $k) => [
                    'id' => $k->id,
                    'planeta' => $k->planeta?->nome,
                    'status' => $k->status,
                    'venda' => $k->venda,
                    'compra' => $k->compra,
                    'aluguel_micro' => $k->aluguel_micro,
                    'parte_em' => $k->parte_em->toIso8601String(),
                    'chega_em' => $k->chega_em->toIso8601String(),
                ]),
        ];
    }
}
