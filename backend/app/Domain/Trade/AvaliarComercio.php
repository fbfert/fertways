<?php

namespace App\Domain\Trade;

use App\Exceptions\DomainRuleException;
use App\Models\AvaliacaoDeComercio;
use App\Models\Colony;
use App\Models\TradeAgreement;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * A avaliação de 0 a 5 estrelas de um Acordo de Troca (GDD §8.4; D-287). As regras e os números
 * estão em `AvaliacaoSpecs`.
 *
 * Só a Confiança Comercial se move aqui, como no `Reputacao` do Acordo: o §26.9 veda compensação
 * entre índices.
 */
class AvaliarComercio
{
    public function __construct(private VinculoDeContas $vinculo) {}

    public function handle(User $autor, TradeAgreement $acordo, int $estrelas, ?string $comentario = null): AvaliacaoDeComercio
    {
        if ($estrelas < 0 || $estrelas > 5) {
            throw new DomainRuleException('estrelas_invalidas', 'A nota vai de 0 a 5 estrelas.');
        }

        $comentario = $comentario === null ? null : trim($comentario);
        if ($comentario !== null && mb_strlen($comentario) > AvaliacaoSpecs::COMENTARIO_MAX) {
            throw new DomainRuleException('comentario_longo', 'O comentário vai até '.AvaliacaoSpecs::COMENTARIO_MAX.' caracteres.');
        }

        $avaliadoId = $this->avaliadoDe($autor, $acordo);
        $this->exigirAvaliavel($acordo);

        try {
            return DB::transaction(function () use ($autor, $acordo, $estrelas, $comentario, $avaliadoId) {
                // A conta do avaliado travada: duas avaliações simultâneas dele não somam errado, e a
                // regra das 24 h é conferida sob o mesmo lock.
                $avaliado = User::whereKey($avaliadoId)->lockForUpdate()->firstOrFail();

                $jaContou = AvaliacaoDeComercio::where('autor_user_id', $autor->id)
                    ->where('avaliado_user_id', $avaliadoId)
                    ->where('conta', true)
                    ->whereNull('anulada_em')
                    ->where('created_at', '>', now()->subHours(AvaliacaoSpecs::FREQUENCIA_HORAS))
                    ->exists();

                $av = AvaliacaoDeComercio::create([
                    'trade_agreement_id' => $acordo->id,
                    'autor_user_id' => $autor->id,
                    'avaliado_user_id' => $avaliadoId,
                    'estrelas' => $estrelas,
                    'comentario' => $comentario === '' ? null : $comentario,
                    'conta' => ! $jaContou,
                    'suspeita' => $this->vinculo->possivelmenteVinculadas($autor->id, $avaliadoId),
                ]);

                if ($av->conta && ! $av->suspeita) {
                    $this->aplicar($av, $avaliado);
                }

                return $av;
            });
        } catch (UniqueConstraintViolationException) {
            throw new DomainRuleException('ja_avaliou', 'Você já avaliou este acordo.');
        }
    }

    /** O operador revisou a suspeita e a considerou legítima: o efeito congelado passa a valer. */
    public function liberar(AvaliacaoDeComercio $av): void
    {
        DB::transaction(function () use ($av) {
            $av = AvaliacaoDeComercio::whereKey($av->id)->lockForUpdate()->firstOrFail();

            if (! $av->suspeita || $av->anulada_em !== null) {
                return;
            }

            $av->suspeita = false;
            $av->save();

            if ($av->conta) {
                $this->aplicar($av, User::whereKey($av->avaliado_user_id)->lockForUpdate()->firstOrFail());
            }
        });
    }

    /** Anula a avaliação e devolve ao índice exatamente o que ela moveu. Nunca apaga: é prova. */
    public function anular(AvaliacaoDeComercio $av, string $motivo): void
    {
        DB::transaction(function () use ($av, $motivo) {
            $av = AvaliacaoDeComercio::whereKey($av->id)->lockForUpdate()->firstOrFail();

            if ($av->anulada_em !== null) {
                return;
            }

            if ($av->delta_aplicado !== 0) {
                $avaliado = User::whereKey($av->avaliado_user_id)->lockForUpdate()->firstOrFail();
                $avaliado->confianca_comercial = AcordoSpecs::limitar($avaliado->confianca_comercial - $av->delta_aplicado);
                $avaliado->save();
            }

            $av->forceFill(['anulada_em' => now(), 'anulada_motivo' => mb_substr($motivo, 0, 120)])->save();
        });
    }

    /**
     * §26.4: "contas confirmadas como vinculadas têm todo o histórico de avaliação mútua zerado
     * retroativamente". Nos dois sentidos; avaliações de terceiros ficam.
     */
    public function confirmarVinculo(User $a, User $b): int
    {
        $mutuas = AvaliacaoDeComercio::whereNull('anulada_em')
            ->where(fn ($q) => $q
                ->where(fn ($w) => $w->where('autor_user_id', $a->id)->where('avaliado_user_id', $b->id))
                ->orWhere(fn ($w) => $w->where('autor_user_id', $b->id)->where('avaliado_user_id', $a->id)))
            ->get();

        foreach ($mutuas as $av) {
            $this->anular($av, 'vinculo_confirmado');
        }

        return $mutuas->count();
    }

    /** O que este colono pode fazer com este acordo agora: avaliar, ou já avaliou com N estrelas. */
    public function situacao(User $autor, TradeAgreement $acordo): array
    {
        $minha = AvaliacaoDeComercio::where('trade_agreement_id', $acordo->id)
            ->where('autor_user_id', $autor->id)->value('estrelas');

        try {
            $this->avaliadoDe($autor, $acordo);
            $this->exigirAvaliavel($acordo);
            $pode = $minha === null;
        } catch (DomainRuleException) {
            $pode = false;
        }

        return ['pode_avaliar' => $pode, 'minha_avaliacao' => $minha === null ? null : (int) $minha];
    }

    /**
     * O resumo público do perfil (§8.4: "4,7 estrelas · 23 avaliações recebidas").
     *
     * @return array{media: float|null, total: int, ultimas: list<array<string, mixed>>}
     */
    public function resumo(User $avaliado, int $quantas = 5): array
    {
        $base = AvaliacaoDeComercio::validas()->where('avaliado_user_id', $avaliado->id);

        $total = (clone $base)->count();
        $media = $total > 0 ? round((float) (clone $base)->avg('estrelas'), 1) : null;

        $ultimas = (clone $base)->with('autor:id,nickname')->orderByDesc('id')->limit($quantas)->get()
            ->map(fn (AvaliacaoDeComercio $av) => [
                'estrelas' => $av->estrelas,
                'comentario' => $av->comentario,
                'autor' => $av->autor?->nickname,
                'em' => $av->created_at,
            ])->values()->all();

        return ['media' => $media, 'total' => $total, 'ultimas' => $ultimas];
    }

    private function aplicar(AvaliacaoDeComercio $av, User $avaliado): void
    {
        $antes = (int) $avaliado->confianca_comercial;
        $avaliado->confianca_comercial = AcordoSpecs::limitar($antes + AvaliacaoSpecs::DELTA[$av->estrelas]);
        $avaliado->save();

        $av->delta_aplicado = $avaliado->confianca_comercial - $antes;
        $av->save();
    }

    private function avaliadoDe(User $autor, TradeAgreement $acordo): int
    {
        $donos = Colony::whereIn('id', array_filter([$acordo->colony_a_id, $acordo->colony_b_id]))
            ->pluck('user_id', 'id');

        $aDono = (int) ($donos[$acordo->colony_a_id] ?? 0);
        $bDono = (int) ($donos[$acordo->colony_b_id] ?? 0);

        if ($autor->id === $aDono && $bDono) {
            return $bDono;
        }

        if ($autor->id === $bDono && $aDono) {
            return $aDono;
        }

        throw new DomainRuleException('nao_e_parte', 'Só quem fez parte do acordo o avalia.');
    }

    private function exigirAvaliavel(TradeAgreement $acordo): void
    {
        if (! in_array($acordo->status, AvaliacaoSpecs::FECHADOS, true)) {
            throw new DomainRuleException('acordo_em_aberto', 'O acordo ainda não terminou: avalie depois da experiência.');
        }

        if ((int) $acordo->value_micro < AcordoSpecs::PISO_REPUTACAO_MICRO) {
            throw new DomainRuleException(
                'abaixo_do_piso',
                'Acordos abaixo de '.(AcordoSpecs::PISO_REPUTACAO_MICRO / Colony::MICRO_POR_FERT).' F$ não geram avaliação (§26.3).',
            );
        }

        $fechado = $acordo->executed_at ?? $acordo->updated_at;

        if ($fechado && $fechado->copy()->addDays(AvaliacaoSpecs::JANELA_DIAS)->isPast()) {
            throw new DomainRuleException('janela_fechada', 'O prazo para avaliar este acordo ('.AvaliacaoSpecs::JANELA_DIAS.' dias) passou.');
        }
    }
}
