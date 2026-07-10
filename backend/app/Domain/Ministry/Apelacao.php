<?php

namespace App\Domain\Ministry;

use App\Exceptions\DomainRuleException;
use App\Models\Colony;
use App\Models\Report;
use Illuminate\Support\Facades\DB;

/**
 * §9.3: "Decisões podem ser apeladas para a equipe do jogo em casos contestados."
 * §26.7: a reversão em apelação custa o bônus do conciliador e soma ao contador de reversões.
 *
 * Quem apela é uma das duas partes — o condenado, quando a decisão foi procedente; o denunciante,
 * quando foi improcedente. Um terceiro não tem o que contestar.
 */
class Apelacao
{
    public function __construct(private AplicarPunicao $punicao) {}

    public function apelar(Colony $quemApela, Report $denuncia): Report
    {
        if (! $denuncia->envolve($quemApela->id)) {
            throw new DomainRuleException('caso_de_outros', 'Esta denúncia não é sua.');
        }

        if ($denuncia->status !== 'decidido') {
            throw new DomainRuleException('caso_nao_decidido', "Só se apela de uma decisão. O caso está {$denuncia->status}.");
        }

        if ($denuncia->appeal_until?->isPast()) {
            throw new DomainRuleException('janela_de_apelacao_fechada', 'As 48 horas para apelar venceram.');
        }

        $denuncia->forceFill(['status' => 'apelado'])->save();

        return $denuncia;
    }

    /**
     * A equipe julga a apelação. Manter devolve o caso a `decidido` com a janela **já fechada** — o
     * bônus do conciliador cai no próximo tick, e ninguém apela duas vezes do mesmo caso.
     */
    public function manter(Report $denuncia): Report
    {
        $this->exigirApelado($denuncia);

        $denuncia->forceFill(['status' => 'decidido', 'appeal_until' => now()])->save();

        return $denuncia;
    }

    /**
     * Reverter: a punição é estornada, o conciliador perde o bônus daquele caso e ganha uma
     * reversão. Acima do limite (5, D-44), ele é suspenso do cargo.
     *
     * Uma reversão de caso julgado **pela própria equipe** não pune conciliador nenhum: não havia
     * conciliador. É o que `conciliator_user_id` nulo significa aqui.
     */
    public function reverter(Report $denuncia): Report
    {
        $this->exigirApelado($denuncia);

        return DB::transaction(function () use ($denuncia) {
            $this->punicao->estornar($denuncia);

            $denuncia->forceFill([
                'status' => 'revertido',
                'appeal_until' => now(),
                // Nunca houve bônus a pagar: o §26.7 o condiciona à decisão não revertida.
                'bonus_paid' => true,
            ])->save();

            $conciliador = $denuncia->conciliator;

            if ($conciliador) {
                $conciliador->reversoes++;

                if ($conciliador->reversoes >= PunicaoSpecs::LIMITE_REVERSOES && $conciliador->conciliador_suspenso_em === null) {
                    $conciliador->conciliador_suspenso_em = now();
                }

                $conciliador->save();
            }

            return $denuncia->refresh();
        });
    }

    private function exigirApelado(Report $denuncia): void
    {
        if ($denuncia->status !== 'apelado') {
            throw new DomainRuleException('caso_nao_apelado', "O caso está {$denuncia->status}, não apelado.");
        }
    }
}
