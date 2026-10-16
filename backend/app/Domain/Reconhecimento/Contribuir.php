<?php

namespace App\Domain\Reconhecimento;

use App\Domain\Marco\ConcederXp;
use App\Domain\Missoes\Janela;
use App\Exceptions\DomainRuleException;
use App\Models\Colony;
use App\Models\MissaoDeReconhecimento;
use Illuminate\Support\Facades\DB;

/**
 * Uma tarefa de pesquisa numa Missão de Reconhecimento (aditivo v3.4 §2.1, §2.2, §2.5).
 *
 * *"O jogador participa por missões públicas de pesquisa, com teto diário de contribuição. Fert$,
 * compras, cosméticos e recursos pagos nunca aceleram uma descoberta."* Por isso a tarefa não custa
 * nada e não se compra: ela é um ato do colono, com teto.
 *
 * - **Quem:** colônia com Laboratório. São tarefas de *pesquisa*, e o Laboratório é onde a pesquisa mora
 *   (A2.3).
 * - **Quantas:** `tarefas_por_dia` por colônia, somando todas as missões (2 na partida: "até duas
 *   tarefas diárias", §2.2). O dia é o dia de missão (07h→07h, `Janela`), o mesmo das missões diárias.
 * - **Quando:** na triangulação de um planeta, na leitura orbital de uma lua e na janela orbital dela.
 * - **O que rende:** `xp_por_tarefa` de XP (100 na partida, o de um nível de obra), e a entrada do
 *   Diário do Colono quando a fase que ela ajudou fechar se revela. Nunca recurso, Fert$, veículo ou
 *   prioridade (§2.5).
 */
class Contribuir
{
    public function __construct(private readonly Gagarin $gagarin) {}

    public function handle(Colony $colonia, MissaoDeReconhecimento $missao): MissaoDeReconhecimento
    {
        if (! $this->gagarin->ativo()) {
            throw new DomainRuleException('gagarin_inativo', 'O Telescópio Gagarin ainda não entrou em operação.');
        }

        if ($this->gagarin->pausado()) {
            throw new DomainRuleException('gagarin_pausado', 'As operações do Gagarin estão pausadas para manutenção.');
        }

        if ((int) $colonia->buildings()->where('type', 'laboratorio')->max('level') < 1) {
            throw new DomainRuleException(
                'sem_laboratorio',
                'As tarefas de reconhecimento são de pesquisa: erga um Laboratório para contribuir.',
            );
        }

        $config = $this->gagarin->config();
        $dia = Janela::diaAtual()->toDateString();

        return DB::transaction(function () use ($colonia, $missao, $config, $dia) {
            $m = MissaoDeReconhecimento::whereKey($missao->id)->lockForUpdate()->firstOrFail();

            if ($m->concluida_em || ! in_array($m->fase, Gagarin::COM_TAREFA, true)) {
                throw new DomainRuleException(
                    'fase_sem_tarefa',
                    'Esta fase da missão não recebe tarefas de pesquisa: é trabalho da sonda e da equipe automática.',
                );
            }

            $hoje = (int) DB::table('contribuicoes_de_reconhecimento')
                ->where('colony_id', $colonia->id)->where('dia', $dia)->lockForUpdate()->sum('tarefas');

            if ($hoje >= (int) $config->tarefas_por_dia) {
                throw new DomainRuleException(
                    'tarefas_do_dia',
                    "Você já fez as {$config->tarefas_por_dia} tarefas de reconhecimento de hoje. O dia vira às 07h.",
                );
            }

            $linha = DB::table('contribuicoes_de_reconhecimento')
                ->where('missao_id', $m->id)->where('colony_id', $colonia->id)->where('dia', $dia);

            if ($linha->exists()) {
                $linha->update(['tarefas' => DB::raw('tarefas + 1'), 'updated_at' => now()]);
            } else {
                DB::table('contribuicoes_de_reconhecimento')->insert([
                    'missao_id' => $m->id, 'colony_id' => $colonia->id, 'dia' => $dia, 'tarefas' => 1,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            $m->increment('tarefas');

            app(ConcederXp::class)->direto($colonia->id, 'reconhecimento', (int) $config->xp_por_tarefa, "reconhecimento:{$m->id}");

            return $m->fresh();
        });
    }
}
