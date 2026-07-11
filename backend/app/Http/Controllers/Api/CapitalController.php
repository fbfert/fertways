<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\News;
use App\Models\PriceIntervention;
use App\Models\ResourceType;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * A Capital — as instituições do governo (§02, §2.1). Governo é operado pela equipe, então estas
 * rotas são **só leitura**: o colono vê o Tesouro, o painel de Finanças e o mural de Notícias, mas
 * os atos do governo (declarar intervenção, publicar comunicado) são artisan (D-44, mesmo molde do
 * Ministério). Ver docs/decisoes.md.
 */
class CapitalController extends Controller
{
    /**
     * Central de Tributos / Tesouro Público (slot 2).
     *
     * "3% do volume enviado vai para o Tesouro" (§8.3). O tributo é retido na entrega e na venda; o
     * Tesouro **acumula e exibe**, não gasta (D-50: o GDD não modela caixa público). Como todo
     * tributo já é registrado em `tax_events`, o saldo é uma agregação dela — retroativo e sem tocar
     * no caminho do tributo.
     */
    public function treasury(): JsonResponse
    {
        // Recursos retidos no transporte (unidades), por tipo.
        $recursos = DB::table('tax_events')
            ->join('resource_types', 'tax_events.resource_type', '=', 'resource_types.code')
            ->where('kind', 'transporte_entrega')
            ->groupBy('tax_events.resource_type', 'resource_types.nome', 'resource_types.tax_class')
            ->orderByDesc(DB::raw('SUM(tax_amount)'))
            ->get([
                'tax_events.resource_type as code',
                'resource_types.nome',
                'resource_types.tax_class',
                DB::raw('SUM(tax_amount) as total'),
            ])
            ->map(fn ($r) => [
                'code' => $r->code,
                'nome' => $r->nome,
                'tax_class' => $r->tax_class,
                'total' => (int) $r->total,
            ]);

        // Fert$ retido na venda de mercado (micro-Fert$).
        $fertMicro = (int) DB::table('tax_events')->where('kind', 'mercado_venda')->sum('tax_amount');

        $recentes = DB::table('tax_events')
            ->leftJoin('colonies', 'tax_events.colony_id', '=', 'colonies.id')
            ->orderByDesc('tax_events.id')
            ->limit(12)
            ->get([
                'tax_events.kind',
                'tax_events.resource_type',
                'tax_events.tax_amount',
                'tax_events.created_at',
                'colonies.name as colonia',
            ])
            ->map(fn ($e) => [
                'kind' => $e->kind,
                'resource_type' => $e->resource_type,
                'tax_amount' => (int) $e->tax_amount,
                'colonia' => $e->colonia,
                'created_at' => $e->created_at,
            ]);

        return response()->json([
            'fert_micro' => $fertMicro,
            'recursos' => $recursos,
            // As alíquotas do §8.3, para o painel de taxas do slot 2.
            'aliquotas' => [
                ['tax_class' => 'primario', 'rotulo' => 'Primários', 'bps' => 300],
                ['tax_class' => 'secundario', 'rotulo' => 'Secundários', 'bps' => 200],
                ['tax_class' => 'raro', 'rotulo' => 'Raros', 'bps' => 100],
            ],
            'recentes' => $recentes,
        ]);
    }

    /**
     * Secretaria de Finanças e Tesouro (slot 4): preços de referência (§06), intervenções vigentes
     * e indicadores mensuráveis. Sem PIB (fórmula lacunar) e sem faixa automática (D-35).
     */
    public function finance(): JsonResponse
    {
        $precos = ResourceType::orderBy('tax_class')->orderBy('nome')
            ->get(['code', 'nome', 'tax_class', 'tax_bps', 'preco_base_micro', 'preco_base_derivado'])
            ->map(fn (ResourceType $r) => [
                'code' => $r->code,
                'nome' => $r->nome,
                'tax_class' => $r->tax_class,
                'tax_bps' => $r->tax_bps,
                'preco_base_micro' => $r->preco_base_micro,
                'derivado' => (bool) $r->preco_base_derivado,
            ]);

        $intervencoes = PriceIntervention::query()->vigentes()
            ->join('resource_types', 'price_interventions.resource_type', '=', 'resource_types.code')
            ->orderBy('resource_types.nome')
            ->get([
                'price_interventions.id',
                'price_interventions.resource_type',
                'resource_types.nome',
                'price_interventions.floor_micro',
                'price_interventions.ceil_micro',
                'price_interventions.reason',
                'price_interventions.expires_at',
            ])
            ->map(fn ($i) => [
                'id' => $i->id,
                'resource_type' => $i->resource_type,
                'nome' => $i->nome,
                'floor_micro' => $i->floor_micro !== null ? (int) $i->floor_micro : null,
                'ceil_micro' => $i->ceil_micro !== null ? (int) $i->ceil_micro : null,
                'reason' => $i->reason,
                'expires_at' => $i->expires_at,
            ]);

        $tesouroFert = (int) DB::table('tax_events')->where('kind', 'mercado_venda')->sum('tax_amount');

        return response()->json([
            'precos' => $precos,
            'intervencoes' => $intervencoes,
            'indicadores' => [
                // Fert$ nas mãos dos colonos (não conta o retido, que saiu de circulação).
                'fert_em_circulacao_micro' => (int) DB::table('colonies')->sum('fert_micro'),
                'tesouro_fert_micro' => $tesouroFert,
                'colonias' => (int) DB::table('colonies')->count(),
            ],
        ]);
    }

    /**
     * Central de Pesquisas e Notícias (slot 3): o mural + o estado honesto do Gagarin (inativo até
     * 50 jogadores ou 45 dias, §12.1).
     */
    public function news(): JsonResponse
    {
        $noticias = News::orderByDesc('published_at')->orderByDesc('id')->limit(30)->get()
            ->map(fn (News $n) => [
                'id' => $n->id,
                'title' => $n->title,
                'body' => $n->body,
                'kind' => $n->kind,
                'author' => $n->author,
                'published_at' => $n->published_at,
            ]);

        $jogadores = (int) DB::table('users')->count();

        return response()->json([
            'noticias' => $noticias,
            'gagarin' => [
                'ativo' => false,
                'jogadores' => $jogadores,
                'limiar_jogadores' => 50,
                'regra' => 'O Telescópio Gagarin ativa com 50 jogadores cadastrados ou 45 dias de servidor.',
            ],
        ]);
    }
}
