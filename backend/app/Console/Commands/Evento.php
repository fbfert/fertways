<?php

namespace App\Console\Commands;

use App\Domain\Eventos\EntregarCestas;
use App\Domain\Eventos\Modificadores;
use App\Domain\Logistics\RequisitosDeOcupacao;
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
        {--ocupacao-marco= : o XP que ocupar zona neutra pede, em bps (-9500 = 5% do normal)}
        {--ocupacao-populacao= : os colonos livres que ocupar pede, em bps (-10000 isenta)}
        {--cesta= : o presente, "recurso:qtd,recurso:qtd" — use __fert__ para Fert$ (em Fert$, não micro)}
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
            'ocupacao-marco' => Modificadores::OCUPACAO_MARCO,
            'ocupacao-populacao' => Modificadores::OCUPACAO_POPULACAO,
        ];

        $escolhidos = [];

        foreach ($opcoes as $opcao => $modificador) {
            $v = $this->option($opcao);

            if ($v !== null && $v !== '') {
                $escolhidos[$modificador] = (int) $v;
            }
        }

        try {
            $cesta = $this->cesta();
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($escolhidos === [] && $cesta === []) {
            $this->error(
                'Diga um modificador (--producao, --consumo, --guerra-declaracao, --guerra-custo, '
                .'--ocupacao-marco, --ocupacao-populacao) ou uma --cesta.',
            );

            return self::FAILURE;
        }

        if (count($escolhidos) > 1) {
            $this->error('Um evento carrega UM modificador. Crie dois eventos, com slugs distintos.');

            return self::FAILURE;
        }

        // Nenhum modificador é legítimo desde o D-232: um evento que só entrega cesta não tem taxa
        // para mexer, e escrever `producao 0` para satisfazer um NOT NULL seria gravar uma mentira.
        $modificador = $escolhidos === [] ? null : array_key_first($escolhidos);
        $efeito = $modificador === null ? null : $escolhidos[$modificador];

        /*
         * ⚠️ Reescrever um evento que já entregou é recusado — a mesma guarda do painel. Depois da
         * primeira cesta, `updateOrCreate` deixa de ser "iterar sobre o rascunho" e passa a ser
         * "metade do mundo com um presente, metade com outro".
         */
        $jaEntregues = GameEvent::where('slug', $slug)->first()?->entregas()->count() ?? 0;

        if ($jaEntregues > 0) {
            $this->error("«{$slug}» já entregou a cesta a {$jaEntregues} colônia(s) e não se reescreve. Use outro slug.");

            return self::FAILURE;
        }

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
            'recompensas' => $cesta ?: null,
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

        $evento = GameEvent::updateOrCreate(['slug' => $slug], $dados);

        if ($this->option('ativar')) {
            $this->newLine();

            if ($modificador !== null) {
                $this->info('✔ Evento ATIVO. O modificador muda a taxa; quem credita continua sendo o tick.');
                $this->line('   O modificador não escreve no ledger, e nunca escreverá.');
            }

            /*
             * A cesta sai AGORA, e não no próximo passo do scheduler (D-232). Esperar cinco minutos
             * entre `--ativar` e o mundo mudar é tempo suficiente para o operador concluir que não
             * funcionou e rodar de novo — e a chave única salvaria do estrago, mas não da
             * desconfiança. `vigenteEm` decide: ativado hoje para começar amanhã não entrega hoje.
             */
            if ($cesta !== [] && $evento->vigenteEm(now())) {
                $n = app(EntregarCestas::class)->doEvento($evento);
                $this->info("✔ Cesta entregue a {$n} colônia(s), com lançamento `presente_evento` no ledger.");
            } elseif ($cesta !== []) {
                $this->line('   A cesta sai quando a janela abrir — o entregador roda a cada 5 min.');
            }
        }

        return self::SUCCESS;
    }

    /**
     * A cesta, de `"recurso:qtd,recurso:qtd"`.
     *
     * ⚠️ `__fert__` entra em **Fert$**, não em micro. É o que o operador digita; a conversão é a
     * mesma do painel, e pedir micro na linha de comando seria convidar um erro de seis casas numa
     * entrega irreversível.
     *
     * Recurso desconhecido **explode**, e não é filtrado em silêncio: aqui há um humano lendo, e um
     * `metal-bruto` digitado com hífen tem de virar erro agora, não uma cesta 1.100 unidades menor
     * descoberta depois da entrega.
     *
     * @return array<string,int>
     */
    private function cesta(): array
    {
        $texto = trim((string) $this->option('cesta'));

        if ($texto === '') {
            return [];
        }

        $codigos = ResourceType::pluck('code')->push(EntregarCestas::FERT)->all();
        $cesta = [];

        foreach (explode(',', $texto) as $par) {
            $par = trim($par);

            if ($par === '') {
                continue;
            }

            if (! str_contains($par, ':')) {
                throw new \InvalidArgumentException("Cesta mal formada em «{$par}» — use recurso:quantidade.");
            }

            [$recurso, $qtd] = array_map('trim', explode(':', $par, 2));

            if (! in_array($recurso, $codigos, true)) {
                throw new \InvalidArgumentException("Recurso desconhecido na cesta: {$recurso}");
            }

            if (! is_numeric($qtd) || (float) $qtd <= 0) {
                throw new \InvalidArgumentException("Quantidade inválida para {$recurso}: {$qtd}");
            }

            $cesta[$recurso] = $recurso === EntregarCestas::FERT
                ? (int) round(((float) $qtd) * Colony::MICRO_POR_FERT)
                : (int) $qtd;
        }

        return $cesta;
    }

    /** @param array<string,mixed> $d */
    private function preview(array $d, ?Colony $colonia): void
    {
        $horas = $d['comeca_em']->diffInHours($d['termina_em']);

        $this->info("Evento «{$d['nome']}» ({$d['slug']})");
        $pontual = in_array($d['modificador'], Modificadores::PONTUAIS, true);

        $this->line($d['modificador'] === null
            ? sprintf('  sem modificador — só a cesta, por %d h a partir de %s',
                $horas, $d['comeca_em']->format('d/m H:i'))
            : sprintf(
                '  %s de %+.1f%%%s, por %d h a partir de %s',
                $d['modificador'], $d['efeito_bps'] / 100,
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
        if ($d['modificador'] === null) {
            // Sem taxa não há frase de taxa. A cesta abaixo é a conta inteira deste evento.
        } elseif ($d['modificador'] === Modificadores::GUERRA_DECLARACAO) {
            $this->line($d['efeito_bps'] <= -10_000
                ? '  ⚠️ TRÉGUA: ninguém declara guerra enquanto durar.'
                : '  ⚠️ Só −10000 fecha o portão. Qualquer outro valor NÃO impede declaração nenhuma.');
        } elseif ($d['modificador'] === Modificadores::GUERRA_CUSTO) {
            $this->line(sprintf(
                '  declarar e mobilizar passa a custar %d%% do normal',
                max(0, 10_000 + $d['efeito_bps']) / 100,
            ));
        } elseif ($d['modificador'] === Modificadores::OCUPACAO_MARCO) {
            /*
             * A mesma exigência de sempre: a conta que o operador precisa ver antes de apertar o
             * botão. "−95%" é abstrato; "6.000 XP passam a 300, e 27 das 29 colônias deixam de estar
             * travadas pelo marco" não é.
             */
            $cheio = \App\Domain\Marco\Curva::xpDoMarco(RequisitosDeOcupacao::MARCO);
            $reduzido = intdiv($cheio * max(0, 10_000 + $d['efeito_bps']), 10_000);

            $this->line("  ocupar zona neutra passa a pedir {$reduzido} XP (o normal são {$cheio})");
            $this->line(sprintf(
                '  %d de %d colônia(s) passam a ter XP suficiente',
                Colony::where('xp', '>=', $reduzido)->count(),
                Colony::count(),
            ));
            $this->line('  ⚠️ Baixa a RÉGUA. Ninguém ganha XP, e o título de ninguém muda.');
        } elseif ($d['modificador'] === Modificadores::OCUPACAO_POPULACAO) {
            $base = app(\App\Domain\Populacao\Parametros::class);
            $exigidos = $base->ativo() ? $base->operadoresDeZona(1) : 0;
            $agora = intdiv($exigidos * max(0, 10_000 + $d['efeito_bps']), 10_000);

            $this->line("  ocupar zona neutra passa a pedir {$agora} colono(s) livre(s) (o normal são {$exigidos})");

            if ($agora <= 0) {
                $this->warn('  ⚠️ Isento: a zona nasce com equipe assim mesmo, e a colônia fica DEVENDO');
                $this->line('     operadores ao que já tem de pé. Degrada (§6.6); nada é confiscado.');
            }
        } else {
            $this->line(sprintf(
                '  uma taxa de 200/h passaria a %d/h enquanto durar',
                intdiv(200 * max(0, 10_000 + $d['efeito_bps']), 10_000),
            ));

            if ($d['efeito_bps'] <= -10_000) {
                $this->warn('  ⚠️ Isto ZERA a taxa: o piso do motor é −100%.');
            }
        }

        /*
         * A cesta, item por item e com o TOTAL — porque é o total que assusta na hora certa. Uma
         * linha de 20.000 de energia lida sozinha parece modesta; "× 29 colônias = 580.000 emitidos"
         * é a informação que faz o operador conferir o número antes de digitar `--ativar`.
         */
        if (($d['recompensas'] ?? []) !== []) {
            $this->newLine();
            $this->line('  CESTA, por colônia (emissão — escreve no ledger, ao contrário do modificador):');

            foreach ($d['recompensas'] as $recurso => $qtd) {
                $this->line($recurso === EntregarCestas::FERT
                    ? sprintf('    %-26s %s Fert$  (× %d = %s)', 'Fert$',
                        number_format($qtd / Colony::MICRO_POR_FERT, 2),
                        $atingidas,
                        number_format($atingidas * $qtd / Colony::MICRO_POR_FERT, 2))
                    : sprintf('    %-26s %s  (× %d = %s)', $recurso,
                        number_format($qtd), $atingidas, number_format($atingidas * $qtd)));
            }

            $this->newLine();
            $this->warn('  ⚠️ A cesta é IRREVERSÍVEL: o ledger é append-only e cancelar não recolhe.');
            $this->line('     Ela sai uma vez por colônia, e alcança quem fundar durante a janela.');
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

        $this->line(sprintf(
            '%-24s %-10s %-20s %-9s %-8s %s',
            'slug', 'status', 'modificador', 'efeito', 'recurso', 'janela',
        ));

        foreach ($eventos as $e) {
            $this->line(sprintf(
                '%-24s %-10s %-20s %-9s %-8s %s → %s%s%s',
                $e->slug, $e->status,
                // Desde o D-232 um evento pode só entregar cesta, sem modificador nenhum.
                $e->modificador ?? '—',
                $e->efeito_bps === null ? '—' : sprintf('%+.1f%%', $e->efeito_bps / 100),
                $e->resource_type ?? 'todos',
                $e->comeca_em->format('d/m H:i'), $e->termina_em->format('d/m H:i'),
                $e->temCesta() ? ' · cesta ('.$e->entregas()->count().' entregues)' : '',
                $e->cancelado_em ? ' (cancelado '.$e->cancelado_em->format('d/m H:i').')' : '',
            ));
        }

        return self::SUCCESS;
    }
}
