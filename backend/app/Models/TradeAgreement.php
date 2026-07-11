<?php

namespace App\Models;

use App\Domain\Trade\ProporAcordo;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Acordo de Troca — o "aperto de mão digital" do GDD §26.5.
 *
 * **Não tem escrow.** Nada é reservado ao propor nem ao aceitar: quem prometeu pode simplesmente
 * não entregar, e é isso que o §26.5 quer — "o risco do calote continua real, mas agora há prova".
 * Ver docs/decisoes.md D-40.
 *
 * `terms_json` guarda o prometido por cada lado, com a PK da colônia como chave:
 *   {"3": {"minerio_ferro": 1000}, "7": {"agua": 2000}}
 *
 * `delivered_json` guarda o já entregue, **líquido de tributo** (D-41), na mesma forma.
 */
class TradeAgreement extends Model
{
    protected $fillable = [
        'colony_a_id', 'colony_b_id', 'proposer_colony_id',
        'terms_json', 'status', 'deadline_at', 'accepted_at',
        'delivered_json', 'value_micro', 'reputation_applied', 'executed_at',
    ];

    protected $casts = [
        'terms_json' => 'array',
        'delivered_json' => 'array',
        'deadline_at' => 'datetime',
        'accepted_at' => 'datetime',
        'executed_at' => 'datetime',
        'value_micro' => 'integer',
        'reputation_applied' => 'boolean',
    ];

    public function colonyA(): BelongsTo
    {
        return $this->belongsTo(Colony::class, 'colony_a_id');
    }

    public function colonyB(): BelongsTo
    {
        return $this->belongsTo(Colony::class, 'colony_b_id');
    }

    /** Só um acordo aceito e dentro do prazo pode receber entregas. */
    public function emVigor(): bool
    {
        return $this->status === 'aceito';
    }

    public function envolve(int $colonyId): bool
    {
        return $this->colony_a_id === $colonyId || $this->colony_b_id === $colonyId;
    }

    /**
     * A outra ponta do acordo, vista de `$colonyId`. `null` numa **oferta aberta** do mural, que
     * ainda não tem contraparte (D-58).
     */
    public function contraparte(int $colonyId): ?int
    {
        return $this->colony_a_id === $colonyId ? $this->colony_b_id : $this->colony_a_id;
    }

    /**
     * O que `$colonyId` prometeu. `null` lê o **lado ainda sem dono** de uma oferta aberta, que
     * mora na chave `0` até alguém aceitar.
     *
     * @return array<string,int>
     */
    public function prometido(?int $colonyId): array
    {
        return $this->terms_json[(string) ($colonyId ?? ProporAcordo::LADO_ABERTO)] ?? [];
    }

    /** @return array<string,int> o que `$colonyId` já entregou, líquido */
    public function entregue(?int $colonyId): array
    {
        return $this->delivered_json[(string) ($colonyId ?? ProporAcordo::LADO_ABERTO)] ?? [];
    }

    /** Um lado cumpriu quando cada recurso prometido chegou inteiro à contraparte. */
    public function cumpriu(int $colonyId): bool
    {
        $entregue = $this->entregue($colonyId);

        foreach ($this->prometido($colonyId) as $recurso => $qtd) {
            if (($entregue[$recurso] ?? 0) < $qtd) {
                return false;
            }
        }

        return true;
    }

    /** @return array<int> as colônias que não honraram o que prometeram */
    public function inadimplentes(): array
    {
        return array_values(array_filter(
            [$this->colony_a_id, $this->colony_b_id],
            fn (int $id) => ! $this->cumpriu($id),
        ));
    }
}
