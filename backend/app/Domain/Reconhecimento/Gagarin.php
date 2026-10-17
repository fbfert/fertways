<?php

namespace App\Domain\Reconhecimento;

use App\Domain\Telemetria\Retencao;
use App\Models\MissaoDeReconhecimento;
use App\Models\News;
use App\Models\PlanetaNpc;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * O Telescópio Orbital Gagarin e o relógio das Missões de Reconhecimento (aditivo v3.4 §2, D-282).
 *
 * Roda no tick. A cada volta:
 *
 * 1. **Ativa** o Gagarin quando o servidor chega a 50 jogadores cadastrados ou a 45 dias, o que vier
 *    primeiro (§2.1). A abertura do servidor é `servidor_aberto_em`; vazia, é a fundação da primeira
 *    colônia, que é o dia em que o mundo passou a ter alguém.
 * 2. **Avança as fases** das missões em curso. As fases que pedem contribuição (a triangulação de um
 *    planeta, a leitura orbital de uma lua) só terminam com o calendário **e** a meta de tarefas:
 *    "a missão atinge 100% por calendário e contribuição; não pode ser concluída instantaneamente".
 * 3. **Publica o boletim** quando ele vence (§2.1: "a cada 2 a 4 dias"; 72 h na partida). Se não há
 *    missão lunar em curso e falta lua a revelar, o boletim abre a leitura da próxima, na ordem do
 *    §2.4. A missão planetária, não: o resultado dela (o planeta novo) não está no GDD, e quem o
 *    escreve é o operador, no painel.
 *
 * Pausado (§2.1, manutenção), nada anda: nem fase, nem boletim. Ao retomar, os prazos em curso são
 * empurrados pelo tempo da pausa, para ninguém perder fase por manutenção.
 */
class Gagarin
{
    public const AUTOR = 'Telescópio Gagarin';

    /** As fases de cada tipo, em ordem, com a duração em horas. Nula: a duração vem da missão. */
    public const FASES = [
        MissaoDeReconhecimento::PLANETARIA => [
            'triangulacao' => 48,
            'preparacao' => 24,
            'voo' => null,
            'validacao' => 24,
        ],
        MissaoDeReconhecimento::LUNAR => [
            'leitura_orbital' => 48,
            'mapeamento' => 24,
            'validacao' => 24,
            'janela_orbital' => 72,
        ],
    ];

    /** As fases em que o colono contribui com tarefas de pesquisa. */
    public const COM_TAREFA = ['triangulacao', 'leitura_orbital', 'janela_orbital'];

    /** As fases que só terminam com a meta de tarefas cumprida, além do calendário. */
    public const COM_META = ['triangulacao', 'leitura_orbital'];

    /** A faixa de distância do alvo e as horas de voo da sonda (§2.2). */
    public const FAIXAS = [
        'proxima' => [4, 6],
        'media' => [7, 12],
        'distante' => [13, 18],
        'remota' => [19, 31],
    ];

    /** O tipo de assinatura da pista de cada lua, lido da primeira revelação do §2.4. */
    private const ANOMALIA_DA_LUA = [
        'armstrong' => 'gravitacional',
        'tereshkova' => 'espectral',
        'sagan' => 'espectral',
        'aldrin' => 'gravitacional',
        'ride' => 'espectral',
        'leonov' => 'espectral',
        'hawking' => 'energetica',
        'laika' => 'energetica',
    ];

    public function __construct(private readonly Registro $registro) {}

    public function config(): object
    {
        return DB::table('gagarin_settings')->find(1);
    }

    public function ativo(): bool
    {
        return $this->config()?->ativado_em !== null;
    }

    public function pausado(): bool
    {
        return $this->config()?->pausado_em !== null;
    }

    /** O dia em que o servidor abriu: o do painel, ou a fundação da primeira colônia. */
    public function abertura(): ?Carbon
    {
        $c = $this->config();
        $quando = $c?->servidor_aberto_em ?? DB::table('colonies')->min('created_at');

        return $quando ? Carbon::parse($quando) : null;
    }

    /** @return array{jogadores:int, limiar_jogadores:int, dias:int, limiar_dias:int} */
    public function contagem(CarbonInterface $agora): array
    {
        $c = $this->config();
        $abertura = $this->abertura();

        return [
            // "Jogadores cadastrados": os bots (que rodam contra a produção, D-238) e as contas de
            // sistema do rádio não são jogadores. É o mesmo filtro do `fertways:equipe`.
            'jogadores' => (int) DB::table('users')
                ->where('email', 'not like', '%'.Retencao::DOMINIO_DOS_BOTS)
                ->where('email', 'not like', '%'.Retencao::DOMINIO_DO_SISTEMA)
                ->count(),
            'limiar_jogadores' => (int) $c->limiar_jogadores,
            'dias' => $abertura ? (int) floor($abertura->diffInDays($agora)) : 0,
            'limiar_dias' => (int) $c->limiar_dias,
        ];
    }

    public function proximoBoletim(): ?Carbon
    {
        $c = $this->config();
        $desde = $c->ultimo_boletim_em ?? $c->ativado_em;

        return $desde ? Carbon::parse($desde)->addHours((int) $c->boletim_intervalo_horas) : null;
    }

    /**
     * D-284: o tick inteiro do Gagarin roda com a linha de `gagarin_settings` travada. Dois
     * `fertways:tick` ao mesmo tempo (o `withoutOverlapping` expira em 10 min, e o `--colony=` roda as
     * etapas globais também) publicavam dois boletins e abriam a mesma lua duas vezes.
     *
     * @return array{ativou: bool, fases: int, boletim: bool}
     */
    public function tick(CarbonInterface $agora): array
    {
        return DB::transaction(function () use ($agora) {
            DB::table('gagarin_settings')->where('id', 1)->lockForUpdate()->first();

            return $this->tickTravado($agora);
        });
    }

    /** @return array{ativou: bool, fases: int, boletim: bool} */
    private function tickTravado(CarbonInterface $agora): array
    {
        $ativou = false;

        if (! $this->ativo()) {
            $n = $this->contagem($agora);
            if ($n['jogadores'] < $n['limiar_jogadores'] && $n['dias'] < $n['limiar_dias']) {
                return ['ativou' => false, 'fases' => 0, 'boletim' => false];
            }

            DB::table('gagarin_settings')->where('id', 1)->update(['ativado_em' => $agora, 'updated_at' => $agora]);
            $this->noticia(
                'O Telescópio Gagarin entrou em operação',
                "O satélite do Governo de Fertways começou a ler o céu. Com {$n['jogadores']} jogadores e {$n['dias']} dias de servidor, "
                .'a Central de Pesquisas e Notícias passa a publicar os boletins orbitais. Toda descoberta é pública: '
                .'ninguém compra, acelera ou guarda para si o que o Gagarin revela.',
                $agora,
            );
            $ativou = true;
        }

        if ($this->pausado()) {
            return ['ativou' => $ativou, 'fases' => 0, 'boletim' => false];
        }

        $fases = $this->avancarFases($agora);
        $boletim = $ativou || ($this->proximoBoletim() !== null && $this->proximoBoletim() <= $agora);

        if ($boletim) {
            $this->boletim($agora);
        }

        return ['ativou' => $ativou, 'fases' => $fases, 'boletim' => $boletim];
    }

    private function avancarFases(CarbonInterface $agora): int
    {
        $avancou = 0;

        foreach (MissaoDeReconhecimento::whereNull('concluida_em')->whereNull('pausada_em')->orderBy('id')->pluck('id') as $id) {
            // Uma fase por volta do tick: um servidor parado por dias não pula etapa sem anunciá-la.
            $avancou += DB::transaction(function () use ($id, $agora) {
                $m = MissaoDeReconhecimento::whereKey($id)->lockForUpdate()->first();

                if (! $m || $m->concluida_em || ! $m->fase_ate || $m->fase_ate > $agora) {
                    return 0;
                }

                if (in_array($m->fase, self::COM_META, true) && $m->tarefas < $m->meta_de_tarefas) {
                    return 0;
                }

                $this->fecharFase($m, $agora);

                return 1;
            });
        }

        return $avancou;
    }

    /** Fecha a fase de agora e abre a seguinte; a última conclui a missão. */
    private function fecharFase(MissaoDeReconhecimento $m, CarbonInterface $agora): void
    {
        $fases = array_keys(self::FASES[$m->tipo]);
        $i = array_search($m->fase, $fases, true);
        $proxima = $fases[$i + 1] ?? null;

        app(Revelacoes::class)->aoFechar($m, $agora);

        if ($proxima === null) {
            $m->update(['fase' => 'concluida', 'fase_desde' => $agora, 'fase_ate' => null, 'concluida_em' => $agora]);
            $this->registro->anotar($m, 'concluida', 'A missão terminou.', $agora);

            return;
        }

        $m->update(['fase' => $proxima, 'fase_desde' => $agora, 'fase_ate' => $this->fim($m, $proxima, $agora)]);
        $this->registro->anotar($m, 'fase', "Começou a fase {$proxima}.", $agora);
        app(Revelacoes::class)->aoAbrir($m, $agora);
    }

    /** O prazo de uma fase: a tabela, ou as horas de voo que a missão planetária guarda. */
    public function fim(MissaoDeReconhecimento $m, string $fase, CarbonInterface $agora): CarbonInterface
    {
        $horas = self::FASES[$m->tipo][$fase] ?? null;
        $horas ??= (int) ($m->resultado['voo_horas'] ?? self::FAIXAS[$m->faixa ?? 'proxima'][0]);

        return $agora->copy()->addHours($horas);
    }

    /**
     * O boletim orbital: o que está em curso, e a pista da próxima lua quando não há leitura lunar.
     * Conta para a regra do §2.5 ("três boletins do Gagarin") antes de uma rota planetária nova.
     */
    private function boletim(CarbonInterface $agora): void
    {
        $novaLua = null;
        $lunarEmCurso = MissaoDeReconhecimento::where('tipo', MissaoDeReconhecimento::LUNAR)->whereNull('concluida_em')->exists();

        if (! $lunarEmCurso) {
            $lua = DB::table('luas')->whereNull('revelada_em')->orderBy('ordem')->first();
            if ($lua) {
                $novaLua = $this->abrirLunar($lua, $agora);
            }
        }

        $linhas = MissaoDeReconhecimento::whereNull('concluida_em')->orderBy('id')->get()
            ->map(fn (MissaoDeReconhecimento $m) => '• '.Fases::rotulo($m).'.')
            ->implode("\n");

        $corpo = ($novaLua
            ? "Pista orbital: uma assinatura {$novaLua->anomalia} em {$novaLua->setor}. A leitura pública começou, e cada colono com Laboratório pode contribuir com até duas tarefas de pesquisa por dia.\n\n"
            : '')
            .($linhas !== '' ? "Operações em curso:\n{$linhas}" : 'Nenhuma operação em curso. O Gagarin segue lendo o céu.');

        $this->noticia('Boletim orbital do Gagarin', $corpo, $agora);

        DB::table('gagarin_settings')->where('id', 1)->update([
            'ultimo_boletim_em' => $agora,
            'boletins_desde_a_ultima_rota' => DB::raw('boletins_desde_a_ultima_rota + 1'),
            'updated_at' => $agora,
        ]);
    }

    private function abrirLunar(object $lua, CarbonInterface $agora): MissaoDeReconhecimento
    {
        $resultado = [
            'lua' => $lua->chave,
            'nome' => $lua->nome,
            'recurso' => $lua->recurso,
            'primeira_revelacao' => $lua->primeira_revelacao,
            'gancho' => $lua->gancho,
        ];

        $m = MissaoDeReconhecimento::create([
            'tipo' => MissaoDeReconhecimento::LUNAR,
            'alvo' => $lua->chave,
            'setor' => "Órbita de Fertways, lua {$lua->ordem} de 8",
            'anomalia' => self::ANOMALIA_DA_LUA[$lua->chave] ?? 'gravitacional',
            'fase' => 'leitura_orbital',
            'fase_desde' => $agora,
            'fase_ate' => $agora->copy()->addHours(self::FASES[MissaoDeReconhecimento::LUNAR]['leitura_orbital']),
            'meta_de_tarefas' => (int) $this->config()->meta_de_tarefas,
            'resultado' => $resultado,
            'assinatura' => MissaoDeReconhecimento::assinar($resultado),
        ]);

        $this->registro->criada($m, $agora);

        return $m;
    }

    /** Uma rota planetária nova só depois de 14 dias da última e de três boletins (§2.5). */
    public function ultimaRotaNova(): ?Carbon
    {
        $quando = PlanetaNpc::whereNotNull('missao_id')->max('created_at');

        return $quando ? Carbon::parse($quando) : null;
    }

    public function noticia(string $titulo, string $corpo, CarbonInterface $agora): void
    {
        News::create([
            'title' => $titulo,
            'body' => $corpo,
            'kind' => 'boletim',
            'author' => self::AUTOR,
            'published_at' => $agora,
        ]);
    }
}
