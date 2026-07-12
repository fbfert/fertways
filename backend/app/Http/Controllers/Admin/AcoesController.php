<?php

namespace App\Http\Controllers\Admin;

use App\Console\Commands\TickColonies;
use App\Domain\Admin\Auditoria;
use App\Domain\Admin\Contas;
use App\Domain\Admin\CorrigirEstado;
use App\Domain\Admin\RealocarColonia;
use App\Domain\Admin\Suspender;
use App\Domain\Finance\DeclararIntervencao;
use App\Domain\Ministry\Apelacao;
use App\Domain\Ministry\DecidirCaso;
use App\Domain\Ministry\GerirConciliador;
use App\Domain\News\PublicarNoticia;
use App\Domain\Treasury\Tesouro;
use App\Exceptions\DomainRuleException;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\Colony;
use App\Models\News;
use App\Models\Report;
use App\Models\TransportSetting;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * As ações de operador do painel. Cada uma chama a MESMA classe de domínio que o comando artisan
 * correspondente usa (não "shell out"), traduz erros de regra numa mensagem e volta ao dashboard.
 *
 * O que não vive no domínio (tick e realocação de founders — orquestração com guardas) é invocado
 * em processo por `Artisan::call`, que é o mesmo container, não um processo de shell.
 */
class AcoesController extends Controller
{
    // ── Ministério ───────────────────────────────────────────────────────────

    public function julgar(Request $request, Report $report, DecidirCaso $decidir): RedirectResponse
    {
        $procedente = $request->validate(['procedente' => ['required', 'boolean']])['procedente'];

        return $this->tentar('ministerio.julgar', function () use ($decidir, $report, $procedente) {
            $decidir->pelaEquipe($report, (bool) $procedente);

            return 'Caso #'.$report->id.' julgado '.($procedente ? 'procedente' : 'improcedente').'.';
        }, "report:{$report->id}");
    }

    public function apelacao(Request $request, Report $report, Apelacao $apelacao): RedirectResponse
    {
        $decisao = $request->validate(['decisao' => ['required', 'in:manter,reverter']])['decisao'];

        return $this->tentar('ministerio.apelacao', function () use ($apelacao, $report, $decisao) {
            $decisao === 'reverter' ? $apelacao->reverter($report) : $apelacao->manter($report);

            return 'Apelação do caso #'.$report->id.': '.($decisao === 'reverter' ? 'revertida' : 'mantida').'.';
        }, "report:{$report->id}");
    }

    // ── Conciliadores ────────────────────────────────────────────────────────

    public function conciliadorNomear(Request $request, GerirConciliador $gerir): RedirectResponse
    {
        $nick = $request->validate(['nickname' => ['required', 'string']])['nickname'];
        $colono = User::where('nickname', $nick)->first();

        if (! $colono) {
            return $this->erro("Colono não encontrado: {$nick}");
        }

        return $this->tentar('conciliador.nomear', fn () => $gerir->nomear($colono)
            ? "{$colono->nickname} é conciliador."
            : "{$colono->nickname} já era conciliador.", "user:{$colono->id}");
    }

    public function conciliadorGerir(Request $request, User $user, GerirConciliador $gerir): RedirectResponse
    {
        $acao = $request->validate(['acao' => ['required', 'in:demitir,reintegrar,suspender']])['acao'];

        return $this->tentar("conciliador.{$acao}", function () use ($gerir, $user, $acao) {
            match ($acao) {
                'demitir' => $gerir->demitir($user),
                'reintegrar' => $gerir->reintegrar($user),
                'suspender' => $gerir->suspender($user),
            };

            return "{$user->nickname}: {$acao}.";
        }, "user:{$user->id}");
    }

    // ── Finanças ─────────────────────────────────────────────────────────────

    public function intervencao(Request $request, DeclararIntervencao $declarar): RedirectResponse
    {
        $dados = $request->validate([
            'resource_type' => ['required', 'string'],
            'teto' => ['nullable', 'numeric', 'min:0'],
            'piso' => ['nullable', 'numeric', 'min:0'],
            'motivo' => ['required', 'string', 'max:255'],
            'dias' => ['required', 'integer', 'min:1'],
        ]);

        return $this->tentar('financas.intervencao', function () use ($declarar, $dados) {
            $i = $declarar->declarar(
                $dados['resource_type'],
                $this->emMicro($dados['teto'] ?? null),
                $this->emMicro($dados['piso'] ?? null),
                $dados['motivo'],
                (int) $dados['dias'],
            );

            return "Intervenção #{$i->id} em {$i->resource_type} declarada.";
        }, "resource:{$dados['resource_type']}");
    }

    public function intervencaoRevogar(Request $request, DeclararIntervencao $declarar): RedirectResponse
    {
        $recurso = $request->validate(['resource_type' => ['required', 'string']])['resource_type'];
        $n = $declarar->revogar($recurso);

        return $this->ok(
            'financas.intervencao_revogar',
            $n > 0 ? "Revogadas {$n} intervenção(ões) de {$recurso}." : "Nenhuma vigente em {$recurso}.",
            "resource:{$recurso}",
        );
    }

    // ── Notícias ─────────────────────────────────────────────────────────────

    public function noticiaPublicar(Request $request, PublicarNoticia $publicar): RedirectResponse
    {
        $dados = $request->validate([
            'titulo' => ['required', 'string', 'max:140'],
            'corpo' => ['required', 'string'],
            'autor' => ['nullable', 'string', 'max:60'],
        ]);

        return $this->tentar('noticia.publicar', function () use ($publicar, $dados) {
            $n = $publicar->publicar($dados['titulo'], $dados['corpo'], $dados['autor'] ?? null);

            return "Comunicado #{$n->id} publicado.";
        });
    }

    public function noticiaRemover(News $news, PublicarNoticia $publicar): RedirectResponse
    {
        $id = $news->id;
        $titulo = $news->title ?? '';
        $publicar->remover($id);

        return $this->ok('noticia.remover', "Notícia #{$id} removida. {$titulo}", "news:{$id}");
    }

    /**
     * Reescreve uma notícia já publicada (2026-07-13).
     *
     * A auditoria guarda o **antes e o depois** — e aqui isso não é zelo burocrático: um comunicado
     * público que muda de texto depois de lido é exatamente o tipo de coisa que alguém vai querer
     * conferir, e a única defesa do operador contra a acusação de ter reescrito a história é o
     * registro de que reescreveu, e do quê para o quê.
     */
    public function noticiaEditar(Request $request, News $news): RedirectResponse
    {
        $dados = $request->validate([
            'titulo' => ['required', 'string', 'max:140'],
            'corpo' => ['required', 'string'],
            'autor' => ['nullable', 'string', 'max:60'],
        ]);

        $antes = "«{$news->title}» / {$news->author}";

        $news->forceFill([
            'title' => $dados['titulo'],
            'body' => $dados['corpo'],
            'author' => $dados['autor'] ?? $news->author,
            'updated_at' => now(),
        ])->save();

        return $this->ok(
            'noticia.editar',
            "Notícia #{$news->id} reescrita. Antes: {$antes}. Agora: «{$news->title}» / {$news->author}",
            "news:{$news->id}",
        );
    }

    /**
     * OCULTAR — administrativo e **reversível**. Sai do mural agora e volta a qualquer momento.
     *
     * É o botão para o erro de redação e para a notícia publicada cedo demais. O colono deixa de
     * vê-la (o `noMural()` do endpoint dele barra); o painel continua a mostrá-la.
     */
    public function noticiaOcultar(News $news): RedirectResponse
    {
        $voltando = $news->oculta();

        $news->forceFill(['hidden_at' => $voltando ? null : now()])->save();

        return $this->ok(
            $voltando ? 'noticia.reexibir' : 'noticia.ocultar',
            $voltando
                ? "Notícia #{$news->id} de volta ao mural. «{$news->title}»"
                : "Notícia #{$news->id} oculta do mural. «{$news->title}»",
            "news:{$news->id}",
        );
    }

    /**
     * INATIVAR — **fim de vida**. A notícia deixou de ser verdadeira.
     *
     * Sai do mural e fica arquivada, marcada. É o que preserva o histórico em vez de destruí-lo: uma
     * notícia inativa continua provando que a coisa foi dita, e quando. Reversível também — mas o
     * nome diz outra coisa, e é essa a diferença para o ocultar.
     */
    public function noticiaInativar(News $news): RedirectResponse
    {
        $reativando = $news->inativa();

        $news->forceFill(['inactive_at' => $reativando ? null : now()])->save();

        return $this->ok(
            $reativando ? 'noticia.reativar' : 'noticia.inativar',
            $reativando
                ? "Notícia #{$news->id} reativada. «{$news->title}»"
                : "Notícia #{$news->id} inativada (envelheceu). «{$news->title}»",
            "news:{$news->id}",
        );
    }

    // ── Ministério do Tesouro (D-57) ─────────────────────────────────────────

    public function distribuir(Request $request, Tesouro $tesouro): RedirectResponse
    {
        $dados = $request->validate([
            'colony_id' => ['required', 'integer', 'exists:colonies,id'],
            'recurso' => ['required', 'string'],
            'quantidade' => ['required', 'numeric', 'min:0.0001'],
        ]);

        $destino = Colony::findOrFail($dados['colony_id']);
        $ehFert = $dados['recurso'] === Tesouro::FERT;
        $qtd = $ehFert
            ? (int) round(((float) $dados['quantidade']) * Colony::MICRO_POR_FERT)
            : (int) $dados['quantidade'];

        return $this->tentar('tesouro.distribuir', function () use ($tesouro, $destino, $dados, $qtd, $ehFert) {
            $tesouro->distribuir($destino, $dados['recurso'], $qtd);

            return 'Tesouro enviou '.number_format($qtd).' de '.($ehFert ? 'Fert$ (micro)' : $dados['recurso'])." a {$destino->name}.";
        }, "colony:{$destino->id}");
    }

    /**
     * O Painel do Ministério dos Transportes (§16), na parte que é do **operador** (D-60).
     *
     * O GDD dá a este painel quatro atribuições de configuração — "configurar a curva de depreciação
     * por hora de uso", "configurar o limite crítico de desempenho", "configurar a perda de vida
     * útil e o teto de revenda a cada manutenção" — e **não publica nenhum dos números**. Eles são,
     * portanto, do operador. Foi isso que permitiu tirar a depreciação da geladeira sem inventar
     * constante no código, e é este formulário.
     *
     * O jogo obedece ao que estiver na linha — não ao que o seeder escreveu.
     */
    public function transporte(Request $request): RedirectResponse
    {
        $dados = $request->validate([
            // 0 = sem desgaste nenhum (desliga a depreciação). Teto de 1000 bps/h = 10%/h, que já é
            // absurdo — mas é uma guarda contra o dedo escorregando, não uma regra de jogo.
            'desgaste_bps_por_hora' => ['required', 'integer', 'min:0', 'max:1000'],
            // O piso não pode passar de 100%: um veículo não anda melhor do que novo.
            'piso_desempenho_bps' => ['required', 'integer', 'min:0', 'max:10000'],
            'manutencao_bps_do_custo' => ['required', 'integer', 'min:0', 'max:10000'],
            'perda_de_teto_bps' => ['required', 'integer', 'min:0', 'max:10000'],
        ]);

        // O antes e o depois destes quatro números importam: eles mudam o envelhecimento da frota
        // inteira, e sem o log ninguém saberia por que a depreciação começou a morder.
        $config = TransportSetting::singleton();
        $antes = $config->only(array_keys($dados));

        return $this->tentar('transporte.parametros', function () use ($dados, $config, $antes) {
            $config->update($dados);

            $mudou = collect($dados)
                ->reject(fn ($v, $k) => (int) $v === (int) $antes[$k])
                ->map(fn ($v, $k) => "{$k}: {$antes[$k]} → {$v}")
                ->implode('; ');

            return 'Parâmetros do Ministério dos Transportes atualizados. '
                .($mudou !== '' ? $mudou : 'Nada mudou.').' Valem já no próximo tick.';
        });
    }

    // ── Jogadores (D-61) ─────────────────────────────────────────────────────

    /**
     * Suspender: barra o acesso e congela **só o comércio** (reusa a restrição do §9.4).
     *
     * Motivo e prazo são obrigatórios — um banimento que não diz por quê e até quando não é
     * moderação, é castigo mudo. `dias` vazio = definitiva.
     */
    public function suspender(Request $request, User $user, Suspender $suspender): RedirectResponse
    {
        $dados = $request->validate([
            'motivo' => ['required', 'string', 'max:500'],
            'dias' => ['nullable', 'integer', 'min:1', 'max:3650'],
        ]);

        return $this->tentarJaAuditado(function () use ($suspender, $user, $dados) {
            $ate = isset($dados['dias']) ? now()->addDays((int) $dados['dias']) : null;
            $suspender->suspender($user, $dados['motivo'], $ate);

            return "{$user->nickname} suspenso.";
        }, "admin.jogador", $user->id);
    }

    public function reintegrar(Request $request, User $user, Suspender $suspender): RedirectResponse
    {
        $motivo = $request->validate(['motivo' => ['required', 'string', 'max:500']])['motivo'];

        return $this->tentarJaAuditado(
            fn () => tap("{$user->nickname} reintegrado.", fn () => $suspender->reintegrar($user, $motivo)),
            'admin.jogador',
            $user->id,
        );
    }

    /**
     * Corrigir o estado de jogo. Lança `ajuste_admin` no ledger, sempre (D-61).
     *
     * Os campos vêm como **saldo absoluto**, não como delta: é o que o operador vê na tela, e pedir
     * "+300" a quem está olhando "1.200" é convite a erro de conta.
     */
    public function corrigir(Request $request, User $user, CorrigirEstado $corrigir): RedirectResponse
    {
        $dados = $request->validate([
            'motivo' => ['required', 'string', 'max:255'],
            'fert' => ['nullable', 'numeric', 'min:0'],
            'recursos' => ['array'],
            'recursos.*' => ['nullable', 'integer', 'min:0'],
            'indices' => ['array'],
            'indices.*' => ['nullable', 'integer', 'min:0', 'max:1000'],
        ]);

        $colony = $user->colony;

        if (! $colony) {
            return $this->erro('Este jogador ainda não fundou colônia.');
        }

        return $this->tentarJaAuditado(function () use ($corrigir, $colony, $dados) {
            $corrigir->corrigir(
                $colony,
                isset($dados['fert']) ? (int) round(((float) $dados['fert']) * Colony::MICRO_POR_FERT) : null,
                array_filter($dados['recursos'] ?? [], fn ($v) => $v !== null),
                array_filter($dados['indices'] ?? [], fn ($v) => $v !== null),
                $dados['motivo'],
            );

            return "Estado de {$colony->name} corrigido.";
        }, 'admin.jogador', $user->id);
    }

    public function editarJogador(Request $request, User $user, Auditoria $auditoria): RedirectResponse
    {
        $dados = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'nickname' => ['required', 'string', 'max:60', Rule::unique('users', 'nickname')->ignore($user->id)],
            'email' => ['required', 'email', Rule::unique('users', 'email')->ignore($user->id)],
        ]);

        $antes = $user->only(['name', 'nickname', 'email']);
        $user->update($dados);

        $auditoria->registrar(
            'jogador.editar',
            "Editou os dados de {$user->nickname}.",
            "user:{$user->id}",
            $antes,
            $user->fresh()->only(['name', 'nickname', 'email']),
        );

        return $this->voltarAoJogador($user, 'Dados atualizados.');
    }

    /**
     * Redefinir a senha de um colono.
     *
     * **Os tokens dele morrem junto.** Uma senha nova que não revogue as sessões antigas não
     * recupera conta nenhuma: quem tiver roubado o token continua entrando com ele, porque token do
     * Sanctum não expira. É a mesma lição do logout (D-53) e da suspensão.
     */
    public function redefinirSenha(Request $request, User $user, Auditoria $auditoria): RedirectResponse
    {
        $senha = $request->validate([
            'password' => ['required', 'string', Password::min(8)],
        ])['password'];

        $user->forceFill(['password' => Hash::make($senha)])->save();
        $tokens = $user->tokens()->count();
        $user->tokens()->delete();

        $auditoria->registrar(
            'jogador.senha',
            "Redefiniu a senha de {$user->nickname} e revogou {$tokens} token(s).",
            "user:{$user->id}",
        );

        return $this->voltarAoJogador($user, 'Senha redefinida e sessões encerradas.');
    }

    /** Realocar a colônia. **Só o dono** (a rota tem o middleware). Exige a palavra REALOCAR. */
    public function realocarColonia(Request $request, User $user, RealocarColonia $realocar): RedirectResponse
    {
        $dados = $request->validate([
            'x' => ['required', 'integer'],
            'y' => ['required', 'integer'],
            'motivo' => ['required', 'string', 'max:255'],
            'confirmacao' => ['required', 'string'],
        ]);

        if ($dados['confirmacao'] !== 'REALOCAR') {
            return $this->erro('Para realocar, escreva REALOCAR. A viagem de todo veículo em rota será refeita.');
        }

        $colony = $user->colony;

        if (! $colony) {
            return $this->erro('Este jogador ainda não fundou colônia.');
        }

        return $this->tentarJaAuditado(function () use ($realocar, $colony, $dados) {
            $realocar->handle($colony, (int) $dados['x'], (int) $dados['y'], $dados['motivo']);

            return "{$colony->name} realocada para ({$dados['x']}, {$dados['y']}).";
        }, 'admin.jogador', $user->id);
    }

    // ── Admins (D-61). Só o dono — a rota tem o middleware `dono`. ────────────

    public function adminCriar(Request $request, Contas $contas): RedirectResponse
    {
        $dados = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'unique:admins,email'],
            'password' => ['required', 'string', Password::min(10)],
            'role' => ['required', Rule::in(Admin::PAPEIS)],
        ]);

        return $this->tentarJaAuditado(
            fn () => tap('Admin criado.', fn () => $contas->criar($dados)),
            'admin.admins',
        );
    }

    public function adminEditar(Request $request, Admin $admin, Contas $contas): RedirectResponse
    {
        $dados = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', Rule::unique('admins', 'email')->ignore($admin->id)],
            'password' => ['nullable', 'string', Password::min(10)],
            'role' => ['required', Rule::in(Admin::PAPEIS)],
        ]);

        return $this->tentarJaAuditado(
            fn () => tap('Admin atualizado.', fn () => $contas->editar($admin, $this->eu(), $dados)),
            'admin.admins',
        );
    }

    public function adminDesativar(Admin $admin, Contas $contas): RedirectResponse
    {
        return $this->tentarJaAuditado(
            fn () => tap("Admin {$admin->email} desativado.", fn () => $contas->desativar($admin, $this->eu())),
            'admin.admins',
        );
    }

    public function adminReativar(Admin $admin, Contas $contas): RedirectResponse
    {
        return $this->tentarJaAuditado(
            fn () => tap("Admin {$admin->email} reativado.", fn () => $contas->reativar($admin)),
            'admin.admins',
        );
    }

    // ── Operação (orquestração, via Artisan em processo) ─────────────────────

    public function tick(): RedirectResponse
    {
        Artisan::call(TickColonies::class);

        return $this->ok('operacao.tick', 'Tick disparado. O mundo avançou.');
    }

    /**
     * Realocação **pontual**: esta colônia, para este `x,y` (2026-07-13).
     *
     * ⚠️ **Não existe realocação em massa pelo painel, e é decisão do usuário.** Existiu um botão
     * "Realocar founders" que movia **todas as colônias do jogo de uma vez** — a ferramenta de uma
     * migração histórica (D-51) que ficara pendurada na tela de Operação, ao lado do "Disparar tick",
     * como se fosse uma coisa que se faz. Não é. Realocar é ato sobre **um jogador escolhido**.
     *
     * O comando `artisan fertways:realocar-founders` continua existindo, fora do painel: ele simula
     * por omissão e só aplica com `--force`.
     *
     * Reusa o mesmo `RealocarColonia` da ficha do jogador (D-61), com os mesmos avisos: a energia já
     * gasta não é acertada, e os Acordos abertos ficam com o prazo da distância antiga.
     */
    public function realocarManual(Request $request, RealocarColonia $realocar): RedirectResponse
    {
        $dados = $request->validate([
            'colony_id' => ['required', 'integer', 'exists:colonies,id'],
            'x' => ['required', 'integer'],
            'y' => ['required', 'integer'],
            'motivo' => ['required', 'string', 'max:255'],
            'confirmacao' => ['required', 'string'],
        ]);

        if ($dados['confirmacao'] !== 'REALOCAR') {
            return $this->erro('Para realocar, escreva REALOCAR. A viagem de todo veículo em rota será refeita.');
        }

        $colonia = Colony::findOrFail($dados['colony_id']);

        // `tentarJaAuditado`: o `RealocarColonia` audita por dentro, com o antes e o depois. Auditar
        // aqui de novo duplicaria a linha.
        return $this->tentarJaAuditado(function () use ($realocar, $colonia, $dados) {
            $realocar->handle($colonia, (int) $dados['x'], (int) $dados['y'], $dados['motivo']);

            return "{$colonia->name} realocada para ({$dados['x']}, {$dados['y']}).";
        }, 'admin.operacao');
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Roda a ação, **audita**, traduz erro de regra e volta.
     *
     * **A auditoria não é opcional.** Este é o gargalo por onde toda ação bem-sucedida passa, e por
     * isso `ok()` **exige o nome da ação**: não dá para acrescentar um botão ao painel e esquecer de
     * registrá-lo — o código não compila sem nomear o que faz. É a mesma ideia do `ledger`, que
     * obriga todo recurso a ter origem.
     */
    private function tentar(string $acao, callable $fn, ?string $alvo = null): RedirectResponse
    {
        try {
            return $this->ok($acao, $fn(), $alvo);
        } catch (DomainRuleException $e) {
            return $this->erro($e->getMessage());
        }
    }

    /**
     * Para as ações cujo **serviço de domínio já auditou** — com o antes e o depois, que só ele
     * conhece: `Suspender`, `CorrigirEstado`, `Contas` e `RealocarColonia`.
     *
     * Auditar de novo aqui duplicaria a linha. O nome é feio de propósito: quem o usar sem que o
     * domínio audite está criando uma ação invisível, e o nome do método é o aviso.
     */
    private function tentarJaAuditado(callable $fn, string $rota = 'admin.dashboard', mixed $param = null): RedirectResponse
    {
        try {
            $msg = $fn();

            return redirect()->route($rota, $param ? [$param] : [])->with('ok', $msg);
        } catch (DomainRuleException $e) {
            return $this->erro($e->getMessage());
        }
    }

    private function ok(string $acao, string $msg, ?string $alvo = null): RedirectResponse
    {
        app(Auditoria::class)->registrar($acao, $msg, $alvo);

        return redirect()->back()->with('ok', $msg);
    }

    private function erro(string $msg): RedirectResponse
    {
        return redirect()->back()->with('erro', $msg);
    }

    private function voltarAoJogador(User $user, string $msg): RedirectResponse
    {
        return redirect()->route('admin.jogador', $user)->with('ok', $msg);
    }

    private function eu(): Admin
    {
        return Auth::guard('admin')->user();
    }

    private function emMicro(mixed $fert): ?int
    {
        return ($fert === null || $fert === '') ? null : (int) round(((float) $fert) * Colony::MICRO_POR_FERT);
    }
}
