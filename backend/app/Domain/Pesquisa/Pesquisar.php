<?php

namespace App\Domain\Pesquisa;

use App\Domain\Endurance\EfeitosDaEndurance;
use App\Exceptions\DomainRuleException;
use App\Models\Colony;
use App\Models\Ledger;
use App\Models\Technology;
use Illuminate\Support\Facades\DB;

/**
 * Inicia uma pesquisa (A2.3).
 *
 * As cinco portas, e a ordem entre elas importa: a mais barata de checar vem primeiro, e a que
 * cobra recurso vem por último — nada é debitado antes de todas as outras passarem.
 */
class Pesquisar
{
    public function __construct(private Vagas $vagas, private EfeitosDaPesquisa $efeitos) {}

    public function handle(Colony $colonia, Technology $tecnologia): void
    {
        DB::transaction(function () use ($colonia, $tecnologia) {
            /*
             * `lockForUpdate` na colônia, e não só nas linhas de recurso: dois pedidos simultâneos
             * poderiam ver a mesma vaga livre e iniciar duas pesquisas. É o mesmo cuidado que o
             * `garantirEncadeada()` das missões toma.
             */
            Colony::whereKey($colonia->id)->lockForUpdate()->first();

            $colonia->load('buildings');

            // ── 1. a chave-mestra
            if (! DB::table('research_settings')->find(1)->ativo) {
                throw new DomainRuleException(
                    'pesquisa_desligada',
                    'A pesquisa ainda não está aberta neste servidor.',
                );
            }

            if (! $tecnologia->ativa) {
                throw new DomainRuleException('tecnologia_inativa', 'Esta tecnologia não está disponível.');
            }

            // ── 2. o Laboratório
            $nivelLab = (int) ($colonia->buildings->firstWhere('type', 'laboratorio')?->level ?? 0);

            if ($nivelLab < $tecnologia->laboratorio_minimo) {
                throw new DomainRuleException(
                    'laboratorio_insuficiente',
                    "Exige Laboratório nível {$tecnologia->laboratorio_minimo}; o seu está no {$nivelLab}.",
                );
            }

            // ── 3. o estado atual desta tecnologia
            $atual = DB::table('colony_technologies')
                ->where('colony_id', $colonia->id)
                ->where('technology_id', $tecnologia->id)
                ->first();

            if ($atual && $atual->status === 'pesquisando') {
                throw new DomainRuleException('ja_pesquisando', 'Esta tecnologia já está em pesquisa.');
            }

            $nivelAlvo = (int) ($atual->nivel ?? 0) + 1;

            if ($nivelAlvo > $tecnologia->nivel_maximo) {
                throw new DomainRuleException(
                    'nivel_maximo',
                    "Esta tecnologia já está no nível máximo ({$tecnologia->nivel_maximo}).",
                );
            }

            // ── 4. o pré-requisito
            if ($tecnologia->requer_technology_id !== null) {
                $preRequisito = DB::table('colony_technologies')
                    ->where('colony_id', $colonia->id)
                    ->where('technology_id', $tecnologia->requer_technology_id)
                    ->where('status', 'concluida')
                    ->exists();

                if (! $preRequisito) {
                    throw new DomainRuleException(
                        'pre_requisito',
                        'Falta a tecnologia anterior desta linha de pesquisa.',
                    );
                }
            }

            // ── 5. a vaga
            if ($this->vagas->livres($colonia) < 1) {
                throw new DomainRuleException(
                    'sem_vaga',
                    'Todas as vagas de pesquisa estão ocupadas. Suba o Laboratório ou espere terminar.',
                );
            }

            // ── e só agora o custo
            $this->debitar($colonia, $tecnologia, $nivelAlvo);

            $agora = now();

            /*
             * A trilha de Ciência encurta o que vem depois. Aplicado AQUI, no início, e não na
             * conclusão: o prazo tem de ser o que foi prometido quando o colono apertou o botão —
             * um prazo que encurta no meio do caminho é surpresa, ainda que boa.
             */
            $desconto = $this->efeitos->descontoDeDuracao($colonia);
            $duracao = EfeitosDaEndurance::aplicarDesconto($tecnologia->duracao_segundos, $desconto);

            DB::table('colony_technologies')->updateOrInsert(
                ['colony_id' => $colonia->id, 'technology_id' => $tecnologia->id],
                [
                    'status' => 'pesquisando',
                    'starts_at' => $agora,
                    'finishes_at' => $agora->copy()->addSeconds($duracao),
                    // Congela a versão do catálogo: mexer no custo depois não muda o que já começou.
                    'versao' => $tecnologia->versao,
                    'updated_at' => $agora,
                    'created_at' => $atual->created_at ?? $agora,
                ],
            );
        });
    }

    /**
     * Cobra o custo em recursos — **os que já existem no jogo**.
     *
     * §8.2 é explícito ao proibir "Pontos de Pesquisa": uma moeda paralela criaria uma segunda
     * economia para balancear, e a fase existe para dar escolha, não para dobrar o trabalho.
     */
    private function debitar(Colony $colonia, Technology $tecnologia, int $nivelAlvo): void
    {
        $estoque = $colonia->resources()->lockForUpdate()->get()->keyBy('resource_type');

        foreach ($tecnologia->custo_json as $recurso => $qtd) {
            /*
             * O custo do catálogo é o do NÍVEL 1 e multiplica pelo nível alvo. Um custo fixo faria
             * o nível 5 sair pelo preço do 1; uma curva própria por nível exigiria cadastrar cinco
             * linhas por tecnologia. A multiplicação linear é a arbitragem mais simples que ainda
             * cresce — e, como todo número desta fase, é HIPÓTESE.
             */
            $preciso = (int) $qtd * $nivelAlvo;
            $tem = (int) ($estoque[$recurso]->amount ?? 0);

            if ($tem < $preciso) {
                throw new DomainRuleException(
                    'recurso_insuficiente',
                    "Faltam recursos para pesquisar: {$recurso} exige {$preciso}, você tem {$tem}.",
                );
            }
        }

        foreach ($tecnologia->custo_json as $recurso => $qtd) {
            $preciso = (int) $qtd * $nivelAlvo;
            $estoque[$recurso]->decrement('amount', $preciso);

            Ledger::create([
                'colony_id' => $colonia->id,
                'type' => 'custo_pesquisa',
                'amount' => -$preciso,
                'resource_type' => $recurso,
                'ref' => "pesquisa:{$tecnologia->chave}:n{$nivelAlvo}",
                'created_at' => now(),
            ]);
        }
    }
}
