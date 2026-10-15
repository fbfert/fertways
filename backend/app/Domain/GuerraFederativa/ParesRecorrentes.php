<?php

namespace App\Domain\GuerraFederativa;

use App\Models\Admin;
use App\Models\FederationWar;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * O relatório de pares recorrentes e a revisão humana dele (decisão 11, D-261).
 *
 * O GDD_GUERRA_FEDERATIVA §15 descreve o abuso — duas federações de contas próprias, guerra encenada,
 * capitulação de propósito, território lavado — e recomenda *"não tentar impedir por regra, e sim
 * tornar caro e visível"*. As travas caras já estão no jogo (o teto do bloco na capitulação, o
 * cooldown do par, o mural público, o Elo de soma zero). Este é o "visível": **o jogo aponta o par;
 * o operador decide**.
 *
 * ## ⚠️ Nada aqui pune
 *
 * *"Punir irmão que joga junto é pior do que deixar passar um trapaceiro."* O veredito fica
 * registrado e não muda estado de jogo nenhum. Se o operador concluir que é fraude, ele age com as
 * ferramentas que já existem e já auditam (suspender, corrigir estado) — e a revisão é o motivo
 * escrito.
 */
class ParesRecorrentes
{
    public const VEREDITOS = ['legitimo', 'suspeito'];

    /** Duas guerras entre as mesmas duas já é padrão: o cooldown do par existe para que seja raro. */
    public const RECORRENTE_A_PARTIR_DE = 2;

    /**
     * Os pares, do mais suspeito para o menos: mais guerras, depois mais capitulações.
     *
     * @return Collection<int, array{federacoes: array{0:int,1:int}, nomes: array{0:string,1:string},
     *     guerras: int, capitulacoes: int, zonas_cedidas: int, ultima: ?string, recorrente: bool,
     *     revisao: ?array{veredito:string, nota:string, admin:?string, em:string}}>
     */
    public function listar(int $limite = 30): Collection
    {
        $guerras = FederationWar::query()
            ->get(['id', 'declarante_id', 'alvo_id', 'motivo_fim', 'comeca_em']);

        $zonasPorGuerra = DB::table('federation_war_proposals')
            ->where('status', 'aceita')->where('preco_tipo', 'zona')
            ->pluck('war_id')->countBy();

        $nomes = DB::table('federations')->pluck('name', 'id');

        return $guerras
            ->groupBy(fn ($g) => $this->chave((int) $g->declarante_id, (int) $g->alvo_id))
            ->map(function (Collection $doPar, string $chave) use ($zonasPorGuerra, $nomes) {
                [$a, $b] = array_map('intval', explode('-', $chave));

                return [
                    'federacoes' => [$a, $b],
                    'nomes' => [(string) ($nomes[$a] ?? "#{$a}"), (string) ($nomes[$b] ?? "#{$b}")],
                    'guerras' => $doPar->count(),
                    'capitulacoes' => $doPar->where('motivo_fim', 'capitulacao')->count(),
                    'zonas_cedidas' => (int) $doPar->sum(fn ($g) => $zonasPorGuerra[$g->id] ?? 0),
                    'ultima' => $doPar->max('comeca_em')?->toIso8601String(),
                    'recorrente' => $doPar->count() >= self::RECORRENTE_A_PARTIR_DE,
                    'revisao' => $this->ultimaRevisao($a, $b),
                ];
            })
            ->sortByDesc(fn ($p) => [$p['guerras'], $p['capitulacoes']])
            ->values()
            ->take($limite);
    }

    public function revisar(?Admin $admin, int $federacaoA, int $federacaoB, string $veredito, string $nota): void
    {
        [$a, $b] = $this->ordenar($federacaoA, $federacaoB);

        DB::table('federation_war_pair_reviews')->insert([
            'federacao_a_id' => $a,
            'federacao_b_id' => $b,
            'veredito' => $veredito,
            'nota' => $nota,
            'admin_id' => $admin?->id,
            'created_at' => now(),
        ]);
    }

    /** @return array{0:int,1:int} */
    public function ordenar(int $x, int $y): array
    {
        return $x < $y ? [$x, $y] : [$y, $x];
    }

    private function chave(int $x, int $y): string
    {
        return implode('-', $this->ordenar($x, $y));
    }

    private function ultimaRevisao(int $a, int $b): ?array
    {
        $r = DB::table('federation_war_pair_reviews as r')
            ->leftJoin('admins', 'admins.id', '=', 'r.admin_id')
            ->where('r.federacao_a_id', $a)->where('r.federacao_b_id', $b)
            ->orderByDesc('r.id')
            ->first(['r.veredito', 'r.nota', 'r.created_at', 'admins.email']);

        return $r ? [
            'veredito' => $r->veredito,
            'nota' => $r->nota,
            'admin' => $r->email,
            'em' => (string) $r->created_at,
        ] : null;
    }
}
