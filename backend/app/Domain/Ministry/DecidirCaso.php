<?php

namespace App\Domain\Ministry;

use App\Exceptions\DomainRuleException;
use App\Models\Report;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Passo 4 do §9.2: "Conciliador decide: improcedente, advertência, redução de reputação ou silêncio
 * temporário."
 *
 * Na tabela fixa do §26.8, essa lista de opções colapsa em duas: **procedente** ou **improcedente**.
 * Advertência, redução e silêncio não são escolhas do conciliador — são o que a tabela do D-49
 * manda aplicar para aquele tipo de violação. Ele julga o fato; a pena está escrita.
 */
class DecidirCaso
{
    public function __construct(private AplicarPunicao $punicao) {}

    /** Julgamento por conciliador jogador, num caso atribuído a ele e ainda dentro das 48 h. */
    public function porConciliador(User $conciliador, Report $denuncia, bool $procedente): Report
    {
        if ($denuncia->status !== 'atribuido' || $denuncia->conciliator_user_id !== $conciliador->id) {
            throw new DomainRuleException('caso_nao_e_seu', 'Este caso não está atribuído a você.');
        }

        if ($conciliador->conciliador_suspenso_em !== null) {
            throw new DomainRuleException('conciliador_suspenso', 'Você está suspenso do cargo.');
        }

        /*
         * Decidir depois das 48 h não é permitido, mesmo que o tick ainda não tenha reatribuído o
         * caso. Entre o vencimento e o varrimento há até um minuto de folga, e nele o conciliador
         * lento apagaria o próprio atraso.
         */
        if ($denuncia->deadline_at?->isPast()) {
            throw new DomainRuleException('prazo_de_analise_vencido', 'As 48 horas para decidir venceram.');
        }

        return $this->decidir($denuncia, $procedente);
    }

    /**
     * Julgamento pela equipe (§9.2, §9.3): casos graves, casos sem conciliador disponível, e
     * apelações. A equipe é o operador do jogo, por artisan (D-44) — não há usuário por trás.
     */
    public function pelaEquipe(Report $denuncia, bool $procedente): Report
    {
        if (! in_array($denuncia->status, ['na_equipe', 'apelado'], true)) {
            throw new DomainRuleException('caso_nao_esta_na_equipe', "O caso está {$denuncia->status}.");
        }

        return $this->decidir($denuncia, $procedente);
    }

    private function decidir(Report $denuncia, bool $procedente): Report
    {
        return DB::transaction(function () use ($denuncia, $procedente) {
            $agora = now();

            $denuncia->forceFill([
                'status' => 'decidido',
                'decision' => $procedente ? 'procedente' : 'improcedente',
                'decided_at' => $agora,
                // D-50: a janela de apelação espelha o prazo de análise do §26.8.
                'appeal_until' => $agora->copy()->addHours(PunicaoSpecs::JANELA_APELACAO_HORAS),
            ])->save();

            if ($procedente) {
                $this->punicao->handle($denuncia);
            }

            return $denuncia->refresh();
        });
    }
}
