<?php

namespace App\Domain\Telemetria;

use App\Domain\Marco\Curva;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Onde os jogadores param (D-264).
 *
 * O §16 do GDD registrou o achado que mudou a pergunta: o último login humano era de 17/07, e os portões
 * do território foram abertos para uma sala vazia. **Nenhuma regra conserta a ausência de jogador** —
 * o que vem antes de qualquer sistema novo é saber quem entra, até onde chega, e onde desiste.
 *
 * ## Tudo derivado, nenhum evento novo
 *
 * A regra da casa para telemetria: o que já está gravado responde. A "última atividade" de um
 * jogador é o mais recente entre quatro sinais que existem há tempo — o último uso do token (a API),
 * o último `login` da telemetria, o último ato de XP da colônia (D-243 usa o mesmo critério para
 * "quem joga") e o último "Desde sua última visita" visto. Nenhum dos quatro sozinho basta: o token
 * não existia para quem jogava antes do Sanctum, a telemetria começou na A2.0, e quem só abre a
 * colônia para olhar não gera XP.
 *
 * ## Só humanos
 *
 * Os bots rodam contra a produção (D-238) e são a maioria das colônias; contá-los aqui mediria o
 * programa deles. A marca é o domínio, como no resto da telemetria.
 */
class Retencao
{
    public const DOMINIO_DOS_BOTS = '@bots.fertways.local';

    public const DOMINIO_DO_SISTEMA = '@fertways.sistema';

    /** Sumido há este tanto é abandono; menos é só ausência. */
    public const DIAS_PARA_ABANDONO = 14;

    /** @return array<string,mixed> */
    public function relatorio(): array
    {
        $agora = now();
        $jogadores = $this->jogadores();

        $funil = [
            'cadastrou' => $jogadores->count(),
            'fundou' => $jogadores->whereNotNull('colonia_id')->count(),
            'agiu' => $jogadores->where('atos', '>', 0)->count(),
            'voltou_d1' => $jogadores->filter(fn ($j) => $j['vida_horas'] >= 24)->count(),
            'ativo_d7' => $jogadores->filter(fn ($j) => $j['vida_horas'] >= 24 * 7)->count(),
            'ativo_d30' => $jogadores->filter(fn ($j) => $j['vida_horas'] >= 24 * 30)->count(),
            'ativo_agora' => $jogadores->filter(fn ($j) => $j['ultima']->gte($agora->copy()->subDays(7)))->count(),
        ];

        $sumidos = $jogadores->filter(
            fn ($j) => $j['ultima']->lt($agora->copy()->subDays(self::DIAS_PARA_ABANDONO)),
        );

        $vidas = $sumidos->pluck('vida_horas')->sort()->values();

        return [
            'funil' => $funil,
            'abandonos' => [
                'total' => $sumidos->count(),
                'por_degrau' => [
                    'nunca_fundou' => $sumidos->whereNull('colonia_id')->count(),
                    'nao_voltou_no_dia_seguinte' => $sumidos->whereNotNull('colonia_id')->filter(fn ($j) => $j['vida_horas'] < 24)->count(),
                    'parou_na_primeira_semana' => $sumidos->whereNotNull('colonia_id')->filter(fn ($j) => $j['vida_horas'] >= 24 && $j['vida_horas'] < 24 * 7)->count(),
                    'parou_antes_de_30_dias' => $sumidos->whereNotNull('colonia_id')->filter(fn ($j) => $j['vida_horas'] >= 24 * 7 && $j['vida_horas'] < 24 * 30)->count(),
                    'parou_depois_de_30_dias' => $sumidos->whereNotNull('colonia_id')->filter(fn ($j) => $j['vida_horas'] >= 24 * 30)->count(),
                ],
                // O último ato de quem foi embora: é o melhor palpite de "o que ele estava tentando".
                'ultimo_ato' => $sumidos->pluck('ultimo_ato')->filter()->countBy()->sortDesc()->all(),
                'por_marco' => $sumidos->whereNotNull('colonia_id')->pluck('marco')->countBy()->sortKeys()->all(),
                'mediana_dias_de_vida' => $vidas->isEmpty() ? null
                    : round($vidas[intdiv($vidas->count(), 2)] / 24, 1),
            ],
            'por_mes_de_cadastro' => $jogadores
                ->groupBy(fn ($j) => $j['cadastro']->format('Y-m'))
                ->sortKeys()
                ->map(fn (Collection $doMes) => [
                    'cadastrou' => $doMes->count(),
                    'fundou' => $doMes->whereNotNull('colonia_id')->count(),
                    'ativo_agora' => $doMes->filter(fn ($j) => $j['ultima']->gte($agora->copy()->subDays(7)))->count(),
                ])
                ->all(),
            'ultima_atividade_humana' => $jogadores->max('ultima')?->toIso8601String(),
        ];
    }

    /**
     * Um registro por humano: cadastro, colônia, atos, último ato e a última atividade.
     *
     * @return Collection<int, array{cadastro: Carbon, colonia_id: ?int, atos: int, ultimo_ato: ?string,
     *     marco: int, ultima: Carbon, vida_horas: float}>
     */
    private function jogadores(): Collection
    {
        $usuarios = DB::table('users')
            ->where('email', 'not like', '%'.self::DOMINIO_DOS_BOTS)
            // As contas de sistema do rádio (Capital, Missões, Federação — `ContaSistema`) não jogam.
            ->where('email', 'not like', '%'.self::DOMINIO_DO_SISTEMA)
            // Nem as da equipe (D-267): até o primeiro teste, todo humano do servidor era o dono do jogo.
            ->where('equipe', false)
            ->get(['id', 'created_at', 'resumo_visto_em']);

        $colonias = DB::table('colonies')->whereIn('user_id', $usuarios->pluck('id'))
            ->get(['id', 'user_id', 'xp', 'created_at'])->keyBy('user_id');

        $atos = DB::table('xp_entries')
            ->whereIn('colony_id', $colonias->pluck('id'))
            ->selectRaw('colony_id, COUNT(*) AS n, MAX(created_at) AS ultimo')
            ->groupBy('colony_id')->get()->keyBy('colony_id');

        $ultimaAcao = DB::table('xp_entries as x')
            ->joinSub(
                DB::table('xp_entries')->selectRaw('colony_id, MAX(id) AS id')->groupBy('colony_id'),
                'u', 'u.id', '=', 'x.id',
            )
            ->pluck('x.acao', 'x.colony_id');

        $tokens = DB::table('personal_access_tokens')
            ->where('tokenable_type', 'App\\Models\\User')
            ->selectRaw('tokenable_id, MAX(last_used_at) AS ultimo')
            ->groupBy('tokenable_id')->pluck('ultimo', 'tokenable_id');

        $logins = DB::table('telemetry_events')
            ->where('type', 'login')->where('origin', 'humano')->whereNotNull('user_id')
            ->selectRaw('user_id, MAX(created_at) AS ultimo')
            ->groupBy('user_id')->pluck('ultimo', 'user_id');

        return $usuarios->map(function ($u) use ($colonias, $atos, $ultimaAcao, $tokens, $logins) {
            $c = $colonias->get($u->id);
            $a = $c ? $atos->get($c->id) : null;
            $cadastro = Carbon::parse($u->created_at);

            $ultima = collect([
                $u->created_at, $c?->created_at, $a?->ultimo, $tokens[$u->id] ?? null,
                $logins[$u->id] ?? null, $u->resumo_visto_em,
            ])->filter()->map(fn ($d) => Carbon::parse($d))->max();

            return [
                'cadastro' => $cadastro,
                'colonia_id' => $c?->id,
                'atos' => (int) ($a->n ?? 0),
                'ultimo_ato' => $c ? ($ultimaAcao[$c->id] ?? null) : null,
                'marco' => $c ? Curva::marco((int) $c->xp) : 0,
                'ultima' => $ultima,
                'vida_horas' => $cadastro->diffInMinutes($ultima) / 60,
            ];
        })->values();
    }
}
