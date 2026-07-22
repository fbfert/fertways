<?php

namespace App\Domain\Zona;

use App\Exceptions\DomainRuleException;
use App\Models\Colony;
use App\Models\NeutralZone;
use App\Models\ZoneBuild;
use App\Models\ZoneEvent;
use App\Models\ZoneStructure;
use Illuminate\Support\Facades\DB;

/**
 * Demole uma estrutura da zona neutra — o espelho de `Domain\Building\Demolir` (D-59) que nunca
 * existiu (docs/decisoes.md D-122/D-123, achado 7: "isso nunca foi decidido, só nunca foi
 * levantado").
 *
 * As mesmas três perguntas que o achado 7 deixou em aberto, respondidas aqui com o MESMO
 * julgamento que já vale para a colônia — não uma regra nova, a mesma regra, no lugar novo
 * (2026-07-20, D-138):
 *
 *  - **O investido não volta.** Nenhum material do canteiro é devolvido, nenhum Fert$. É a mesma
 *    perda seca de `Demolir::handle()` — o custo já virou construção, e a construção agora vira pó.
 *  - **A manutenção NÃO cai.** Fui conferir antes de prometer isso: `NeutralZone::custoDeManutencao()`
 *    é função só de `level` (o Posto de Comando, por `SubirNivelDaZona`) — nunca leu Muralha, Torre
 *    nem nenhum dos outros 12 tipos de `Estruturas::TODAS`. Demolir uma delas não move o número
 *    que já não dependia dela. Não é um efeito que este serviço decide não ter; é um efeito que a
 *    manutenção nunca teve, para NENHUMA estrutura, mesmo antes de demolição existir.
 *  - **Não desfaz saque de guerra.** O `Ledger`/`ZoneEvent` de um saque já sofrido não muda: demolir
 *    é sobre o AGORA da estrutura, não uma reversão de história. Nada aqui apaga ou reescreve um
 *    evento passado — mesma régua do resto do jogo ("todo Fert$/recurso tem história", D-122 item 4).
 *
 * Duas guardas vêm de fora dessas três perguntas, por analogia direta com o que já existe:
 *
 *  - **O Posto de Comando é indemolível** — nasce com a ocupação (D-52) e não está em
 *    `Estruturas::CONSTRUIVEIS`; sem ele não há controle territorial sobre a zona, o mesmo motivo
 *    que torna as cinco essenciais da colônia indemolíveis.
 *  - **Não se demole sob cerco, nem em obra.** `ConstruirNaZona`/`SubirNivelDaZona` já bloqueiam
 *    QUALQUER investimento numa zona cercada ("não se constrói/investe sob sítio") — demolir é o
 *    inverso do investimento, mas mexer no estado defensivo no meio de um combate em curso abriria
 *    a mesma pergunta sem resposta que a guarda de "em obra" já existe para fechar do lado da
 *    colônia: o que aconteceria a uma obra em andamento sobre a estrutura que acabou de sumir.
 */
class DemolirEstruturaDaZona
{
    /**
     * @param  int  $slot  desde o D-144 a zona é uma colmeia de linhas (`zone_structures`), como a
     *                      colônia — demolir é apagar a linha do slot, não zerar uma coluna.
     */
    public function handle(Colony $colony, NeutralZone $zona, int $slot): void
    {
        if ($slot === ZonaSlots::POSTO_SLOT) {
            throw new DomainRuleException(
                'estrutura_indemolivel',
                'O Posto de Comando nasce com a ocupação e não se demole — sem ele não há controle territorial sobre a zona.',
            );
        }

        DB::transaction(function () use ($colony, $zona, $slot) {
            $zona = NeutralZone::whereKey($zona->id)->lockForUpdate()->firstOrFail();

            if ($zona->owner_colony_id !== $colony->id) {
                throw new DomainRuleException('zona_nao_e_sua', 'Esta zona neutra não é sua.');
            }

            if ($zona->cercada()) {
                throw new DomainRuleException(
                    'zona_cercada',
                    'A zona está cercada: não se demole sob sítio. Rompa o cerco ou espere as 48 h.',
                );
            }

            $linha = ZoneStructure::where('neutral_zone_id', $zona->id)->where('slot', $slot)->first();

            if ($linha === null) {
                throw new DomainRuleException(
                    'nada_para_demolir',
                    'Este slot ainda não tem estrutura erguida.',
                );
            }

            $emObra = ZoneBuild::where('zone_id', $zona->id)->where('slot', $slot)->exists();

            if ($emObra) {
                throw new DomainRuleException(
                    'demolir_em_obra',
                    'Esta estrutura está com uma obra em curso. Espere-a terminar antes de demolir.',
                );
            }

            $estrutura = $linha->type;
            $nivelAtual = $linha->level;

            // A linha some, e com ela o slot volta a ficar vazio: o mesmo estado de quem nunca
            // construiu nada ali — exatamente como o `delete()` de `Demolir` já faz para o slot da
            // colônia (D-59). Até o D-144 a zona zerava uma coluna em vez de apagar uma linha,
            // porque não havia linha; agora há, e o padrão se unifica.
            $linha->delete();

            ZoneEvent::create([
                'zone_id' => $zona->id,
                'type' => 'estrutura_demolida',
                'colony_id' => $colony->id,
                'meta' => ['estrutura' => $estrutura, 'nivel_perdido' => $nivelAtual],
                'created_at' => now(),
            ]);
        });
    }
}
