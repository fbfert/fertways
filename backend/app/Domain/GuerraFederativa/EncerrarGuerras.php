<?php

namespace App\Domain\GuerraFederativa;

use App\Domain\News\PublicarNoticia;
use App\Models\FederationWar;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * As guerras que venceram o prazo terminam (A2.10, decisão 5: sete dias).
 *
 * ## ⚠️ O prazo NÃO se estende por atividade
 *
 * Renovar por combate faria a guerra durar enquanto houvesse um insone de cada lado — e guerra sem
 * fim deixa de ser evento para virar o clima. O relógio corre do momento da declaração, e ponto.
 *
 * ## E o encerramento é o que faz o cooldown existir
 *
 * O cooldown do par (GDD §10) conta a partir de `termina_em`. Sem alguém marcar a guerra como
 * encerrada, ela ficaria `ativa` para sempre, o par jamais poderia declarar de novo, e o que era
 * proteção contra assédio viraria bloqueio permanente. É o mesmo defeito que a pesquisa teve por
 * meses (D-190): quem inicia sem quem conclui.
 */
class EncerrarGuerras
{
    public function __construct(
        private readonly PublicarNoticia $noticias,
        private readonly RatingFederativo $rating,
    ) {}

    public function handle(CarbonInterface $agora): int
    {
        $vencidas = FederationWar::with(['declarante', 'alvo'])
            ->where('status', 'ativa')
            ->where('termina_em', '<=', $agora)
            ->get();

        foreach ($vencidas as $guerra) {
            DB::transaction(function () use ($guerra, $agora) {
                $guerra->update([
                    'status' => 'encerrada',
                    'encerrada_em' => $agora,
                    'motivo_fim' => 'prazo',
                ]);

                /*
                 * ⚠️ O rating por PRAZO sai do saldo da guerra (D-207), e não de um empate
                 * automático: sete dias de batalhas em que um lado tomou três zonas do outro não
                 * são empate, e tratá-los assim faria a única guerra "de verdade" — a que ninguém
                 * encerra negociando — ser a única que não conta.
                 */
                $this->rating->aplicar($guerra, $this->rating->resultadoPorSaldo($guerra));

                $this->noticias->publicar(
                    "Fim da guerra: {$guerra->declarante?->name} e {$guerra->alvo?->name}",
                    'A campanha chegou ao fim do prazo. As duas federações voltam ao estado neutro.',
                    'Ministério das Relações',
                );
            });
        }

        return $vencidas->count();
    }
}
