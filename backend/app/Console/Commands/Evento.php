<?php

namespace App\Console\Commands;

use App\Domain\Eventos\Modificadores;
use App\Models\Colony;
use App\Models\GameEvent;
use App\Models\ResourceType;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * O Motor de Eventos, na mão do Dono (A2.8).
 *
 * ## Por que artisan, e não uma rota
 *
 * Criar evento de mundo é **ato de governo**, não de colono — mesmo molde de `fertways:intervencao`
 * (D-35) e `fertways:conciliador` (D-44). E há uma razão a mais aqui: um evento secreto que passasse
 * por uma rota HTTP deixaria rastro no log de acesso do servidor web, que é o lugar mais fácil do
 * mundo de alguém ler por acidente.
 *
 * ## ⚠️ Preview obrigatório: sem `--ativar`, ele não ativa
 *
 * A §Segurança da fase pede *"preview antes de ativar"* e *"dry-run/simulação"*. O comando **sempre**
 * mostra o que faria; ativar exige dizer `--ativar` em voz alta. Mesma escolha do
 * `fertways:populacao-grandfather`, que salvou a operação da população quando o ensaio a seco
 * contradisse o que eu tinha escrito (D-178).
 *
 * ## Cancelar NUNCA apaga
 *
 * *"Rollback lógico do modificador futuro, sem apagar efeitos históricos já ocorridos."* Cancelar
 * grava `cancelado_em`: o evento para de valer dali para a frente e **continua valendo para trás**.
 * Apagar a linha faria o "Desde sua última visita" dizer que a produção caiu sem motivo.
 *
 *     php84 artisan fertways:evento tempestade --nome="Tempestade de poeira" --producao=-2000 --horas=48
 *     php84 artisan fertways:evento tempestade --nome="..." --producao=-2000 --horas=48 --ativar
 *     php84 artisan fertways:evento tempestade --cancelar
 *     php84 artisan fertways:evento --listar
 */
class Evento extends Command
{
    protected $signature = 'fertways:evento
        {slug? : identificador do evento (ex.: tempestade_de_poeira)}
        {--nome= : o nome que o jogador lê}
        {--mensagem= : a mensagem pública}
        {--notas= : notas internas, que o jogador nunca vê}
        {--producao= : efeito na produção em bps (-2000 = -20%)}
        {--consumo= : efeito no consumo em bps}
        {--guerra-declaracao= : o portão da guerra (A2.10) — -10000 impõe trégua}
        {--guerra-custo= : quanto muda o custo de declarar e mobilizar, em bps}
        {--recurso= : limita a um recurso (padrão: todos)}
        {--colonia= : limita a uma colônia, por id — o dry-run em escala de um}
        {--horas=24 : duração}
        {--comeca-em= : início (padrão: agora)}
        {--visibilidade=anunciado : anunciado|parcial|secreto}
        {--segredo : nem o nome aparece em lugar nenhum}
        {--ativar : sem isto, só mostra o que faria}
        {--cancelar : encerra o futuro e preserva o passado}
        {--listar}';

    protected $description = 'Motor de Eventos: cria, prevê, ativa e cancela eventos de mundo';

    public function handle(): int
    {
        if ($this->option('listar')) {
            return $this->listar();
        }

        $slug = (string) $this->argument('slug');

        if ($slug === '') {
            $this->error('Diga o slug do evento, ou use --listar.');

            return self::FAILURE;
        }

        if ($this->option('cancelar')) {
            return $this->cancelar($slug);
        }

        return $this->criar($slug);
    }

    private function criar(string $slug): int
    {
        /*
         * Um evento, UM modificador. Dois numa linha só pareceriam economia e viravam confusão:
         * cancelar metade seria impossível, e o operador perderia a leitura de qual dos dois causou
         * o quê — que é o motivo pelo qual a ativação da população foi em duas etapas (D-178).
         */
        $opcoes = [
            'producao' => Modificadores::PRODUCAO,
            'consumo' => Modificadores::CONSUMO,
            'guerra-declaracao' => Modificadores::GUERRA_DECLARACAO,
            'guerra-custo' => Modificadores::GUERRA_CUSTO,
        ];

        $escolhidos = [];

        foreach ($opcoes as $opcao => $modificador) {
            $v = $this->option($opcao);

            if ($v !== null && $v !== '') {
                $escolhidos[$modificador] = (int) $v;
            }
        }

        if ($escolhidos === []) {
            $this->error('Diga um modificador: --producao, --consumo, --guerra-declaracao ou --guerra-custo.');

            return self::FAILURE;
        }

        if (count($escolhidos) > 1) {
            $this->error('Um evento carrega UM modificador. Crie dois eventos, com slugs distintos.');

            return self::FAILURE;
        }

        $modificador = array_key_first($escolhidos);
        $efeito = $escolhidos[$modificador];

        $recurso = $this->option('recurso');

        if ($recurso !== null && $recurso !== '' && ! ResourceType::whereKey($recurso)->exists()) {
            $this->error("Recurso desconhecido: {$recurso}");

            return self::FAILURE;
        }

        $colonia = null;

        if (($id = $this->option('colonia')) !== null && $id !== '') {
            $colonia = Colony::find((int) $id);

            if (! $colonia) {
                $this->error("Colônia {$id} não existe.");

                return self::FAILURE;
            }
        }

        $comeca = ($v = $this->option('comeca-em')) ? Carbon::parse($v) : now();
        $termina = $comeca->copy()->addHours(max(1, (int) $this->option('horas')));

        $dados = [
            'slug' => $slug,
            'nome' => (string) ($this->option('nome') ?: $slug),
            'mensagem_publica' => $this->option('mensagem'),
            'notas_internas' => $this->option('notas'),
            'comeca_em' => $comeca,
            'termina_em' => $termina,
            'visibilidade' => (string) $this->option('visibilidade'),
            'escopo' => $colonia ? 'colonia' : 'mundo',
            'colony_id' => $colonia?->id,
            'modificador' => $modificador,
            'efeito_bps' => $efeito,
            'resource_type' => $recurso ?: null,
            'segredo' => (bool) $this->option('segredo'),
            'status' => $this->option('ativar') ? 'ativo' : 'rascunho',
            // Auditoria (§Segurança): quem rodou o comando, pelo usuário do sistema.
            'criado_por' => get_current_user(),
        ];

        $this->preview($dados, $colonia);

        if (! $this->option('ativar')) {
            $this->newLine();
            $this->warn('Nada foi ativado. Rode de novo com --ativar.');
            $this->line('(A linha é gravada como RASCUNHO, e rascunho não vale nada no mundo.)');
        }

        GameEvent::updateOrCreate(['slug' => $slug], $dados);

        if ($this->option('ativar')) {
            $this->newLine();
            $this->info('✔ Evento ATIVO. Ele muda a taxa; quem credita continua sendo o tick.');
            $this->line('   Nada foi escrito no ledger, e nada será.');
        }

        return self::SUCCESS;
    }

    /** @param array<string,mixed> $d */
    private function preview(array $d, ?Colony $colonia): void
    {
        $pct = $d['efeito_bps'] / 100;
        $horas = $d['comeca_em']->diffInHours($d['termina_em']);

        $this->info("Evento «{$d['nome']}» ({$d['slug']})");
        $pontual = in_array($d['modificador'], Modificadores::PONTUAIS, true);

        $this->line(sprintf(
            '  %s de %+.1f%%%s, por %d h a partir de %s',
            $d['modificador'], $pct,
            $pontual ? '' : ' em '.($d['resource_type'] ?? 'TODOS os recursos'),
            $horas, $d['comeca_em']->format('d/m H:i'),
        ));
        $this->line('  escopo: '.($colonia ? "colônia {$colonia->id} ({$colonia->name})" : 'MUNDO'));
        $this->line('  visibilidade: '.$d['visibilidade'].($d['segredo'] ? ' · SEGREDO' : ''));

        $atingidas = $colonia ? 1 : Colony::count();
        $this->newLine();
        $this->line("  atinge {$atingidas} colônia(s)");

        /*
         * A conta que o operador precisa ver antes de apertar o botão. "-20%" é abstrato; "uma mina
         * de 200/h passa a 160/h" não é. Para os pontuais a frase é outra, porque taxa não é a
         * pergunta — o portão da guerra ou está aberto ou não está.
         */
        if ($d['modificador'] === Modificadores::GUERRA_DECLARACAO) {
            $this->line($d['efeito_bps'] <= -10_000
                ? '  ⚠️ TRÉGUA: ninguém declara guerra enquanto durar.'
                : '  ⚠️ Só −10000 fecha o portão. Qualquer outro valor NÃO impede declaração nenhuma.');
        } elseif ($d['modificador'] === Modificadores::GUERRA_CUSTO) {
            $this->line(sprintf(
                '  declarar e mobilizar passa a custar %d%% do normal',
                max(0, 10_000 + $d['efeito_bps']) / 100,
            ));
        } else {
            $this->line(sprintf(
                '  uma taxa de 200/h passaria a %d/h enquanto durar',
                intdiv(200 * max(0, 10_000 + $d['efeito_bps']), 10_000),
            ));

            if ($d['efeito_bps'] <= -10_000) {
                $this->warn('  ⚠️ Isto ZERA a taxa: o piso do motor é −100%.');
            }
        }
    }

    private function cancelar(string $slug): int
    {
        $evento = GameEvent::where('slug', $slug)->first();

        if (! $evento) {
            $this->error("Evento «{$slug}» não existe.");

            return self::FAILURE;
        }

        if ($evento->cancelado_em !== null) {
            $this->warn('Já estava cancelado em '.$evento->cancelado_em->format('d/m H:i').'.');

            return self::SUCCESS;
        }

        $evento->update(['status' => 'cancelado', 'cancelado_em' => now()]);

        $this->info("Evento «{$evento->nome}» cancelado agora.");
        $this->line('⚠️ O passado NÃO foi apagado: o efeito que já valeu continua calculável, e o');
        $this->line('   "Desde sua última visita" ainda consegue explicar por que a produção caiu.');

        return self::SUCCESS;
    }

    private function listar(): int
    {
        $eventos = GameEvent::orderByDesc('comeca_em')->get();

        if ($eventos->isEmpty()) {
            $this->info('Nenhum evento.');

            return self::SUCCESS;
        }

        $this->line(sprintf('%-24s %-10s %-9s %-8s %s', 'slug', 'status', 'efeito', 'recurso', 'janela'));

        foreach ($eventos as $e) {
            $this->line(sprintf(
                '%-24s %-10s %+-8.1f%% %-8s %s → %s%s',
                $e->slug, $e->status, $e->efeito_bps / 100,
                $e->resource_type ?? 'todos',
                $e->comeca_em->format('d/m H:i'), $e->termina_em->format('d/m H:i'),
                $e->cancelado_em ? ' (cancelado '.$e->cancelado_em->format('d/m H:i').')' : '',
            ));
        }

        return self::SUCCESS;
    }
}
