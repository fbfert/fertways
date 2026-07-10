<?php

namespace App\Domain\Ministry;

use App\Models\Punishment;
use App\Models\Report;

/**
 * Executa a tabela fixa do §26.8 sobre um caso julgado procedente.
 *
 * O conciliador não escolhe a punição: ele decide se a violação ocorreu. É isso que o §26.8 quer
 * dizer com "elimina decisão totalmente subjetiva". Tudo o que sai daqui está no D-49.
 *
 * As punições inertes — silêncio sem chat, bloqueio de leilões sem leilões — são **gravadas com
 * índice e prazo** e passam a morder no dia em que o sistema existir (D-44). A redução de pontos,
 * essa, morde sempre: o índice do §26.2 existe.
 */
class AplicarPunicao
{
    public function __construct(private MoverReputacao $reputacao) {}

    /** @return array<Punishment> */
    public function handle(Report $denuncia): array
    {
        $spec = PunicaoSpecs::violacao($denuncia->violation);
        $condenado = $denuncia->accused->user;
        $agora = now();

        if (! $condenado) {
            return [];
        }

        $aplicadas = [];

        foreach ($spec['punicoes'] as $punicao) {
            $horas = PunicaoSpecs::duracaoHoras($punicao);

            /*
             * Só a `reducao` carrega pontos. A advertência é "registro no histórico, sem efeito
             * imediato na reputação" (§9.4), e silêncio e restrição comercial mordem por prazo, não
             * por dedução — somar pontos neles puniria duas vezes o mesmo fato.
             */
            $pontos = $punicao === PunicaoSpecs::REDUCAO ? $spec['pontos'] : 0;

            $aplicadas[] = Punishment::create([
                'report_id' => $denuncia->id,
                'user_id' => $condenado->id,
                'kind' => $punicao,
                'index_name' => $pontos !== 0 ? $spec['indice'] : null,
                'points' => $pontos,
                'expires_at' => $horas ? $agora->copy()->addHours($horas) : null,
                'applied_at' => $agora,
            ]);

            if ($pontos !== 0) {
                // Um índice, nunca dois: o §26.9 veda compensação cruzada.
                $this->reputacao->somar($condenado, $spec['indice'], $pontos);
            }
        }

        return $aplicadas;
    }

    /**
     * Estorna tudo que este caso puniu, porque a equipe reverteu a decisão (§26.7).
     *
     * Devolve os pontos ao índice de onde saíram e marca a punição como estornada — nunca a apaga.
     * O §26.8 quer processo auditável, e apagar a punição apagaria a prova do erro do conciliador,
     * que é justamente o que alimenta o contador de reversões.
     *
     * O clamp da escala pode engolir a devolução: um colono levado a 0 por −250 e depois punido de
     * novo volta a 250, não a 500. É consequência aceita de a escala não ter negativo — e é o mesmo
     * comportamento que o D-43 já escolheu para o calote.
     */
    public function estornar(Report $denuncia): void
    {
        foreach ($denuncia->punishments()->whereNull('revoked_at')->get() as $p) {
            if ($p->points !== 0 && $p->index_name) {
                $this->reputacao->somar($p->user, $p->index_name, -$p->points);
            }

            $p->forceFill(['revoked_at' => now()])->save();
        }
    }
}
