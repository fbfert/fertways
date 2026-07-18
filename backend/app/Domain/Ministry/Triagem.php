<?php

namespace App\Domain\Ministry;

use App\Models\Colony;
use App\Models\Report;
use App\Models\TradeAgreement;
use App\Models\User;

/**
 * Passo 2 do §9.2: "Sistema analisa logs de envio entre os dois colonos. Caso simples vai para
 * conciliador jogador. Caso grave vai direto para a equipe."
 *
 * E o §9.3: "Sem conciliadores disponíveis: equipe do jogo assume automaticamente."
 *
 * A "equipe" é o operador do jogo, fora do jogo, e julga por artisan (D-44). Com quatro colônias no
 * servidor, era a única leitura que funciona.
 */
class Triagem
{
    /** Roda na abertura e a cada reatribuição por prazo vencido (§26.8). */
    public function handle(Report $denuncia, ?int $exceto = null): Report
    {
        // §9.2: grave não passa por conciliador jogador. Grave é o que a tabela do §26.8 pune com
        // −250 (D-50) — e a §26.4 já mandava "revisão manual" para conta vinculada.
        if ($denuncia->grave) {
            return $this->paraEquipe($denuncia);
        }

        $conciliador = $this->conciliadorDisponivel($denuncia, $exceto);

        if (! $conciliador) {
            return $this->paraEquipe($denuncia);
        }

        $denuncia->forceFill([
            'status' => 'atribuido',
            'conciliator_user_id' => $conciliador->id,
            'assigned_at' => now(),
            // §26.8: 48 horas para decidir, ou o caso é reatribuído a outro conciliador.
            'deadline_at' => now()->addHours(PunicaoSpecs::PRAZO_ANALISE_HORAS),
        ])->save();

        return $denuncia;
    }

    private function paraEquipe(Report $denuncia): Report
    {
        $denuncia->forceFill([
            'status' => 'na_equipe',
            'conciliator_user_id' => null,
            'assigned_at' => null,
            'deadline_at' => null,
        ])->save();

        return $denuncia;
    }

    /**
     * Um conciliador nomeado, não suspenso, que não seja parte no caso e não tenha impedimento.
     *
     * `$exceto` é o conciliador que deixou o prazo vencer: reatribuir ao mesmo seria repetir a
     * espera de 48 h com quem já não respondeu.
     */
    private function conciliadorDisponivel(Report $denuncia, ?int $exceto): ?User
    {
        $partes = [$denuncia->reporter_colony_id, $denuncia->accused_colony_id];

        $candidatos = User::whereNotNull('conciliador_desde')
            ->whereNull('conciliador_suspenso_em')
            ->when($exceto, fn ($q) => $q->whereKeyNot($exceto))
            ->orderBy('id')
            ->get();

        foreach ($candidatos as $c) {
            $colonia = $c->colony;

            // Julgar o próprio caso não é impedimento do §26.8; é absurdo anterior a ele.
            if ($colonia && in_array($colonia->id, $partes, true)) {
                continue;
            }

            if ($colonia && $this->impedido($colonia, $partes)) {
                continue;
            }

            return $c;
        }

        return null;
    }

    /**
     * §26.8, impedimento: "Conciliador não pode julgar casos envolvendo membros da própria
     * federação, ou jogadores com quem teve transação comercial nos últimos 30 dias."
     *
     * As duas metades mordem desde o D-115. A da federação: `$conciliador` e uma das partes na
     * mesma federação. A comercial: o único registro par-a-par de transação que o servidor guarda
     * é o Acordo de Troca — um despacho avulso lança no ledger da origem, sem o destino, e
     * portanto não prova relação entre dois colonos.
     *
     * @param  array<int>  $partes
     */
    private function impedido(Colony $conciliador, array $partes): bool
    {
        if ($conciliador->federation_id !== null
            && Colony::whereIn('id', $partes)->where('federation_id', $conciliador->federation_id)->exists()) {
            return true;
        }

        $desde = now()->subDays(PunicaoSpecs::IMPEDIMENTO_DIAS);

        return TradeAgreement::where('created_at', '>=', $desde)
            ->where(fn ($q) => $q->where('colony_a_id', $conciliador->id)->orWhere('colony_b_id', $conciliador->id))
            ->where(fn ($q) => $q->whereIn('colony_a_id', $partes)->orWhereIn('colony_b_id', $partes))
            ->exists();
    }
}
