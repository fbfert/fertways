<?php

namespace App\Domain\Espacoporto;

use App\Domain\Marco\Curva;
use App\Models\Colony;
use App\Models\ContratoDeRota;
use App\Models\PlanetaNpc;
use App\Models\Unit;
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

    /**
     * O portão: o do Cargueiro (§05), e — se vier um planeta — o dele também (D-269). O marco que
     * vale é o maior dos dois; a tecnologia, quando o planeta pede, é a mais.
     *
     * @return array{pode:bool, marco:int, marco_exigido:int, dias:int, dias_exigidos:int, tecnologia:?string, falta:?string}
     */
    public function portao(Colony $colonia, ?PlanetaNpc $planeta = null): array
    {
        $marco = Curva::marco((int) $colonia->xp);
        $dias = (int) floor($colonia->created_at->diffInDays(now()));
        $marcoExigido = max(self::MARCO_DO_CARGUEIRO, (int) ($planeta?->marco_exigido ?? 0));

        $tecnologia = null;
        $temTecnologia = true;
        if ($planeta?->tecnologia_exigida) {
            $tec = DB::table('technologies')->where('chave', $planeta->tecnologia_exigida)->first(['id', 'nome']);
            $tecnologia = $tec->nome ?? $planeta->tecnologia_exigida;
            $temTecnologia = $tec !== null && DB::table('colony_technologies')
                ->where('colony_id', $colonia->id)->where('technology_id', $tec->id)
                ->where('status', 'concluida')->exists();
        }

        $falta = match (true) {
            $marco < $marcoExigido => "Pede o marco {$marcoExigido} — você está no {$marco}.",
            $dias < self::DIAS_DO_CARGUEIRO => 'Pede '.self::DIAS_DO_CARGUEIRO." dias de colônia — você tem {$dias}.",
            ! $temTecnologia => "Pede a pesquisa {$tecnologia}, concluída no seu Laboratório.",
            default => null,
        };

        return [
            'pode' => $falta === null,
            'marco' => $marco,
            'marco_exigido' => $marcoExigido,
            'dias' => $dias,
            'dias_exigidos' => self::DIAS_DO_CARGUEIRO,
            'tecnologia' => $tecnologia,
            'falta' => $falta,
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
            // D-280: as regras do seguro e da escolta, e quem pode escoltar agora.
            'seguro_carregamento_bps' => (int) ($c->seguro_carregamento_bps ?? 0),
            'escolta_poder_pleno' => (int) ($c->escolta_poder_pleno ?? 0),
            'escolta_reducao_max_bps' => (int) ($c->escolta_reducao_max_bps ?? 0),
            'sentinelas_em_casa' => Unit::where('colony_id', $colonia->id)->where('type', 'sentinela')
                ->where('status', 'casa')->where('hp_bps', '>', 0)->count(),
            'planetas' => PlanetaNpc::with('precos')->orderBy('ordem')->get()->map(fn (PlanetaNpc $p) => [
                'chave' => $p->chave,
                'nome' => $p->nome,
                'distancia_minutos' => $p->distancia_minutos,
                'risco' => $p->risco,
                // D-269: o risco em número e o portão deste planeta, ditos antes do aluguel.
                'risco_chance_bps' => (int) $p->risco_chance_bps,
                'risco_perda_bps' => (int) $p->risco_perda_bps,
                'portao' => $this->portao($colonia, $p),
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
                    'sinistro_bps' => $k->sinistro_bps,
                    'imposto_importacao_micro' => (int) $k->imposto_importacao_micro,
                    'imposto_venda_micro' => $k->imposto_venda_micro,
                    'risco_chance_bps' => $k->risco_chance_bps,
                    'seguro_premio_micro' => (int) $k->seguro_premio_micro,
                    'seguro_cobertura_micro' => (int) $k->seguro_cobertura_micro,
                    'seguro_indenizacao_micro' => $k->seguro_indenizacao_micro,
                    'escolta_sentinelas' => count($k->escolta_unit_ids ?? []),
                    'parte_em' => $k->parte_em->toIso8601String(),
                    'chega_em' => $k->chega_em->toIso8601String(),
                ]),
        ];
    }
}
