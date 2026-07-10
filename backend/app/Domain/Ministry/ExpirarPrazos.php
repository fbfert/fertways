<?php

namespace App\Domain\Ministry;

use App\Models\Ledger;
use App\Models\Report;
use Illuminate\Support\Facades\DB;

/**
 * O que o Ministério faz sozinho, a cada tick.
 *
 * Fica fora do laço por colônia, como `ConcluirTrechos` e `ExpirarAcordos`: uma denúncia tem dois
 * donos, e nem o prazo de análise nem a janela de apelação têm relação com o `last_tick_at` de
 * nenhuma das duas colônias.
 */
class ExpirarPrazos
{
    public function __construct(private Triagem $triagem) {}

    /** @return array{reatribuidos: int, encerrados: int} */
    public function handle(): array
    {
        return [
            'reatribuidos' => $this->reatribuirVencidos(),
            'encerrados' => $this->fecharJanelasDeApelacao(),
        ];
    }

    /**
     * §26.8: "Conciliador tem 48 horas para decidir um caso atribuído, ou ele é automaticamente
     * reatribuído a outro conciliador disponível."
     *
     * Passamos o conciliador lento em `$exceto`: reatribuir ao mesmo repetiria a espera de 48 h com
     * quem já não respondeu. Não havendo outro, o caso sobe à equipe (§9.3).
     *
     * Deixar o prazo vencer **não** conta reversão: o §26.7 conta reversão de decisão, e aqui não
     * houve decisão nenhuma.
     */
    private function reatribuirVencidos(): int
    {
        $vencidos = Report::where('status', 'atribuido')
            ->where('deadline_at', '<=', now())
            ->orderBy('id')
            ->get();

        foreach ($vencidos as $denuncia) {
            $this->triagem->handle($denuncia, exceto: $denuncia->conciliator_user_id);
        }

        return $vencidos->count();
    }

    /**
     * A janela de 48 h fechou sem apelação (D-50): o caso encerra e o conciliador recebe o bônus de
     * +3 Fert$ do §26.7 — "apenas se a decisão NÃO for revertida em apelação".
     *
     * Casos julgados pela equipe encerram sem bônus: `conciliator_user_id` é nulo. Casos revertidos
     * nunca chegam aqui — o `Apelacao::reverter` já os marcou `bonus_paid`.
     */
    private function fecharJanelasDeApelacao(): int
    {
        $maduros = Report::where('status', 'decidido')
            ->where('appeal_until', '<=', now())
            ->orderBy('id')
            ->get();

        foreach ($maduros as $denuncia) {
            DB::transaction(function () use ($denuncia) {
                /*
                 * Releitura com lock, e a guarda no UPDATE: dois ticks sobrepostos não pagam o
                 * mesmo bônus duas vezes. É a mesma trava do `reputation_applied` do Acordo.
                 */
                $ganhou = Report::whereKey($denuncia->id)
                    ->where('status', 'decidido')
                    ->update(['status' => 'encerrado', 'updated_at' => now()]);

                if ($ganhou === 0) {
                    return;
                }

                $this->pagarBonus($denuncia->refresh());
            });
        }

        return $maduros->count();
    }

    private function pagarBonus(Report $denuncia): void
    {
        $conciliador = $denuncia->conciliator;
        $colonia = $conciliador?->colony;

        if (! $colonia || $denuncia->bonus_paid) {
            return;
        }

        DB::table('colonies')->where('id', $colonia->id)->increment('fert_micro', PunicaoSpecs::BONUS_MICRO);

        Ledger::create([
            'colony_id' => $colonia->id,
            'type' => 'bonus_conciliador',
            'amount' => PunicaoSpecs::BONUS_MICRO,
            'resource_type' => null,
            'ref' => "ministerio:bonus:denuncia:{$denuncia->id}",
            'created_at' => now(),
        ]);

        $denuncia->forceFill(['bonus_paid' => true])->save();
    }
}
