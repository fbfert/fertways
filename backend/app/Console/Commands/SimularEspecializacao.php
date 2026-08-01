<?php

namespace App\Console\Commands;

use App\Domain\Especializacao\Perfil;
use App\Domain\Production\Siderurgica;
use App\Models\Colony;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * TRILHA A2.S — terceira entrega: a dependência entre especializações (A2.4).
 *
 * ## O critério que este comando existe para verificar
 *
 * O da A2.4 foi reescrito quando os bots saíram do escopo. O original — *"bots especializados
 * negociam entre si por necessidade econômica"* — dependia de simulação que este repositório não
 * tem. O substituto é verificável no papel:
 *
 * > **A dependência é estrutural e demonstrável.** Para cada especialização, ao menos uma cadeia de
 * > que ela depende não é suprível por produção própria em nível competitivo, e isso é mostrado
 * > pelo simulador da trilha A2.S, não por observação de tráfego.
 *
 * ## As duas provas, e a primeira é definitiva
 *
 * 1. **Há recursos que nenhum prédio do jogo produz.** Conferido contra o catálogo — e o cálculo
 *    precisa conhecer a Siderúrgica, cujo JSON diz `metal_bruto` porque é o que ela PROCESSA.
 *    Ignorá-la produz a conclusão falsa de que ninguém faz mineral eletrônico; ela faz cinco dos
 *    oito (D-82, contrariando o §4.3 de propósito). Depender do que ninguém produz é dependência
 *    por desenho: **nenhum nível de investimento a resolve**.
 * 2. **Quem converte depende do que converte.** Refinaria, Destilaria e Siderúrgica consomem o que
 *    outros prédios produzem. Uma colônia pode fechar essa cadeia sozinha — e aí gasta slots com
 *    isso, o que é precisamente o custo de oportunidade que a especialização deveria ter.
 *
 * A segunda prova é mais fraca de propósito, e o relatório diz isso: ela mostra **dependência
 * escolhida**, não estrutural. É a primeira que cumpre o critério.
 */
class SimularEspecializacao extends Command
{
    protected $signature = 'fertways:simular-especializacao';

    protected $description = 'Trilha A2.S: a dependência entre especializações é estrutural?';

    /** @var array<string,array<array{0:string,1:int}>> */
    private const ARQUETIPOS = [
        'metalúrgica' => [['mina_local', 4], ['mina_local', 3], ['industria_siderurgica', 4], ['reator_de_energia', 2]],
        'química' => [['refinaria_quimica', 4], ['refinaria_quimica', 3], ['reator_de_energia', 2], ['captacao_de_agua', 2]],
        'eletrônica' => [['oficina', 4], ['oficina', 3], ['reator_de_energia', 3], ['captacao_de_agua', 1]],
        'energética' => [['reator_de_energia', 5], ['destilaria', 4], ['fazenda', 2]],
        'agrícola' => [['fazenda', 4], ['fazenda', 3], ['captacao_de_agua', 3], ['reator_de_energia', 2]],
    ];

    public function handle(Perfil $perfil): int
    {
        $this->line('<options=bold>TRILHA A2.S — a dependência entre especializações é estrutural?</>');
        $this->newLine();

        /*
         * A prova dura, conferida contra o catálogo e não afirmada de memória: se um dia alguém
         * publicar um prédio que produza mineral eletrônico, este número deixa de ser zero e o
         * relatório passa a dizer outra coisa — que é o comportamento certo.
         */
        $mineraisSemProdutor = $this->mineraisSemProdutor();

        DB::beginTransaction();

        try {
            $linhas = [];

            foreach (self::ARQUETIPOS as $nome => $predios) {
                $p = $perfil->de($this->colonia($nome, $predios));

                $estruturais = array_values(array_intersect($p['depende_de'], $mineraisSemProdutor));
                $escolhidas = array_values(array_diff($p['depende_de'], $mineraisSemProdutor));

                $linhas[] = [
                    $nome,
                    $p['vocacao'] ?? '—',
                    $p['forca_pct'].'%',
                    $estruturais === [] ? '—' : implode(', ', array_slice($estruturais, 0, 3)),
                    $escolhidas === [] ? '—' : implode(', ', array_slice($escolhidas, 0, 3)),
                ];
            }

            $this->table(
                ['especialização', 'vocação', 'força', 'depende (ESTRUTURAL)', 'depende (escolhida)'],
                $linhas,
            );

            $this->veredito($linhas, $mineraisSemProdutor);
        } finally {
            DB::rollBack();
            $this->newLine();
            $this->line('<fg=gray>Mundo descartado: a transação foi revertida, nada foi gravado.</>');
        }

        return self::SUCCESS;
    }

    /**
     * Os recursos que **nenhum prédio do jogo produz**, em nenhum nível.
     *
     * Conferido contra `building_specs` — a mesma tabela que o jogo usa. Um recurso que ninguém
     * produz só entra na colônia pelo Mercado, e por isso depender dele é estrutural.
     */
    private function mineraisSemProdutor(): array
    {
        $produzidos = [];

        foreach (DB::table('building_specs')->get(['building_type', 'producao_hora_json']) as $s) {
            /*
             * ⚠️ A Siderúrgica precisa de tratamento à parte, e ignorá-la me fez publicar uma
             * afirmação falsa: o `producao_hora_json` dela traz `metal_bruto`, que é **o que ela
             * PROCESSA**, não o que produz. Lida ingenuamente, ela some da lista de produtores — e
             * a conclusão vira "nenhum prédio produz mineral eletrônico", que é mentira.
             */
            if ($s->building_type === 'industria_siderurgica') {
                foreach (array_keys(Siderurgica::SAIDAS) as $r) {
                    $produzidos[$r] = true;
                }

                continue;
            }

            foreach (array_keys(json_decode($s->producao_hora_json ?? '{}', true) ?: []) as $r) {
                $produzidos[$r] = true;
            }
        }

        return DB::table('resource_types')->pluck('code')
            ->reject(fn ($c) => isset($produzidos[$c]))
            ->values()->all();
    }

    private function colonia(string $nome, array $predios): Colony
    {
        $userId = DB::table('users')->insertGetId([
            'name' => $nome, 'nickname' => 'esp'.random_int(100000, 999999),
            'email' => 'esp'.random_int(100000, 999999).'@simulador.local',
            'password' => 'x', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $colonyId = DB::table('colonies')->insertGetId([
            'user_id' => $userId, 'name' => $nome,
            'x' => random_int(-40, 40), 'y' => random_int(-40, 40),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $slot = 0;

        foreach ($predios as [$tipo, $nivel]) {
            DB::table('buildings')->insert([
                'colony_id' => $colonyId, 'type' => $tipo, 'level' => $nivel, 'slot' => $slot++,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return Colony::with('buildings')->findOrFail($colonyId);
    }

    private function veredito(array $linhas, array $semProdutor): void
    {
        $comEstrutural = count(array_filter($linhas, fn ($l) => $l[3] !== '—'));
        $total = count($linhas);

        $this->newLine();
        $this->line('<options=bold>Veredito (critério de saída da A2.4)</>');
        $this->line('  · Recursos que NENHUM prédio do jogo produz: <options=bold>'.count($semProdutor).'</>');
        $this->line('    <fg=gray>'.implode(', ', array_slice($semProdutor, 0, 12)).'</>');
        $this->line("  · Especializações com dependência ESTRUTURAL: <options=bold>{$comEstrutural}/{$total}</>");

        if ($comEstrutural === $total) {
            $this->line('  <fg=green>✓ Toda especialização depende de ao menos uma cadeia que ela NÃO PODE</>');
            $this->line('  <fg=green>  suprir em nenhum nível de investimento. O critério é cumprido.</>');
        } elseif ($comEstrutural > 0) {
            $this->line('  <fg=yellow>· Algumas especializações se bastam. Ver quais e por quê.</>');
        } else {
            $this->line('  <fg=red>⚠️ Nenhuma dependência estrutural: toda colônia pode se bastar.</>');
        }

        $this->newLine();
        $this->line('<fg=yellow>  ⚠️ A diferença entre as duas colunas não é detalhe:</>');
        $this->line('<fg=gray>     ESTRUTURAL é o que a colônia não pode produzir em NENHUM nível —</>');
        $this->line('<fg=gray>     nenhum prédio do jogo o produz. É dependência por desenho.</>');
        $this->line('<fg=gray>     ESCOLHIDA é o que ela poderia produzir gastando slots, e preferiu</>');
        $this->line('<fg=gray>     não produzir. Essa é decisão do jogador, e pode ser desfeita.</>');
        $this->line('<fg=gray>     Só a primeira cumpre o critério de saída da fase.</>');
    }
}
