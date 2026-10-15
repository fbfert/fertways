<?php

namespace App\Console\Commands;

use App\Domain\Telemetria\Retencao as RelatorioDeRetencao;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Onde os jogadores param (D-264), no terminal.
 *
 * **Só lê.** Existe para medir a produção antes de o painel de métricas chegar lá: roda da árvore de
 * trabalho apontando o banco de produção por variável de ambiente, e por isso começa dizendo com que
 * banco fala — a mesma conferência que o `deploy.sh` faz antes do `migrate` (D-214). Nenhuma linha é
 * escrita, em banco nenhum.
 */
class Retencao extends Command
{
    protected $signature = 'fertways:retencao {--json : a saída crua, para colar numa conversa}';

    protected $description = 'Onde os jogadores param: funil de retenção e abandono (só leitura, D-264).';

    public function handle(RelatorioDeRetencao $retencao): int
    {
        $this->line('Banco: '.DB::connection()->getDatabaseName());
        $r = $retencao->relatorio();

        if ($this->option('json')) {
            $this->line(json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->line('Última atividade humana: '.($r['ultima_atividade_humana'] ?? '—'));
        $this->newLine();
        $this->table(['Degrau', 'Jogadores'], collect($r['funil'])->map(fn ($n, $k) => [$k, $n])->values()->all());
        $this->table(['Quem foi embora: onde parou', 'Jogadores'],
            collect($r['abandonos']['por_degrau'])->map(fn ($n, $k) => [$k, $n])->values()->all());
        $this->line('Mediana de dias de vida de quem foi embora: '.($r['abandonos']['mediana_dias_de_vida'] ?? '—'));
        $this->line('Último ato de quem foi embora: '.json_encode($r['abandonos']['ultimo_ato'], JSON_UNESCAPED_UNICODE));
        $this->line('Marco em que pararam: '.json_encode($r['abandonos']['por_marco']));
        $this->newLine();
        $this->table(['Mês de cadastro', 'Cadastrou', 'Fundou', 'Ativo agora'],
            collect($r['por_mes_de_cadastro'])->map(fn ($m, $mes) => [$mes, $m['cadastrou'], $m['fundou'], $m['ativo_agora']])->values()->all());

        return self::SUCCESS;
    }
}
