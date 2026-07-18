<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Admin\CorrigirEstado;
use App\Domain\Admin\RealocarColonia;
use App\Domain\Admin\Suspender;
use App\Domain\Building\Funcoes;
use App\Domain\Chat\ContaSistema;
use App\Domain\Media\Biblioteca;
use App\Domain\Media\Vinculaveis;
use App\Domain\Ministry\PunicaoSpecs;
use App\Domain\Transport\Conservacao;
use App\Domain\Transport\Ministerio;
use App\Domain\Treasury\Tesouro;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\AuditEntry;
use App\Models\BuildQueue;
use App\Models\Colony;
use App\Models\Combat;
use App\Models\ImageBinding;
use App\Models\MediaAsset;
use App\Models\Ledger;
use App\Models\MarketOrder;
use App\Models\NeutralZone;
use App\Models\News;
use App\Models\PriceIntervention;
use App\Models\Punishment;
use App\Models\Report;
use App\Models\ResourceType;
use App\Models\TradeAgreement;
use App\Models\TransportSetting;
use App\Models\TreasuryLedger;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleListing;
use App\Models\WarSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * As telas do painel de administração — **só leitura**. As ações vivem no `AcoesController`.
 *
 * **Quebrado em seções desde o D-61.** Era uma página só, e ela não aguentaria o que o usuário pediu:
 * o CRUD de jogadores e um log de auditoria que cresce sem parar dominariam tudo o mais. Continua
 * Blade por sessão, mesma estética, sem SPA — o que mudou foi a navegação, não a natureza.
 */
class PainelController extends Controller
{
    /** Visão geral: os números de topo e o que exige atenção agora. */
    public function dashboard(Request $request): View
    {
        $abas = ['panorama', 'atos', 'colonias', 'logistica'];
        $aba = in_array($request->query('aba'), $abas, true) ? $request->query('aba') : 'panorama';

        $dados = ['aba' => $aba];

        // O panorama e os alertas: o primeiro lugar que o operador olha, por isso é o padrão.
        if ($aba === 'panorama') {
            $dados['resumo'] = $this->resumo();
            // O Governo no Mercado Central (D-87): o que falta anunciar ou repor.
            $dados['recursosSemOfertaDoGoverno'] = $this->recursosSemOfertaDoGoverno();
        }

        // O que aconteceu no painel ultimamente — o primeiro lugar onde se olha quando algo parece
        // errado, e por isso é aba própria em vez de enterrado dentro do Panorama.
        if ($aba === 'atos') {
            $dados['ultimosAtos'] = AuditEntry::orderByDesc('id')->limit(20)->get();
        }

        if ($aba === 'colonias') {
            $dados['colonias'] = Colony::with('user:id,nickname')->orderBy('id')->get();
        }

        if ($aba === 'logistica') {
            $dados['obras'] = BuildQueue::query()->ativos()->with('colony:id,name')->orderBy('finishes_at')->get();
            $dados['zonas'] = NeutralZone::with('owner:id,name')->whereNotNull('owner_colony_id')->orderBy('id')->get();
        }

        return view('admin.dashboard', $dados);
    }

    // ─────────────────────────────────────────────────────────── Jogadores

    /** A lista, com a busca global do topo. */
    public function jogadores(Request $request): View
    {
        $q = trim((string) $request->query('q'));

        $jogadores = User::query()
            ->with('colony')
            // "Capital" (D-91) e "Missões" são contas de sistema, não jogadores — sem colônia,
            // sem senha utilizável, e nada aqui deveria poder suspendê-las como se fossem gente.
            ->whereNotIn('email', [ContaSistema::EMAIL_CAPITAL, ContaSistema::EMAIL_MISSOES])
            ->when($q !== '', function ($query) use ($q) {
                /*
                 * A busca aceita as quatro coisas que alguém tem à mão quando reclama de algo: o
                 * nome, o e-mail, o nome da colônia — e a **placa de um veículo**, que é o único
                 * identificador que aparece na tela de outro jogador.
                 */
                $query->where(fn ($w) => $w
                    ->where('name', 'like', "%{$q}%")
                    ->orWhere('nickname', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%")
                    ->orWhereHas('colony', fn ($c) => $c->where('name', 'like', "%{$q}%"))
                    ->orWhereHas('colony.vehicles', fn ($v) => $v->where('plate', 'like', "%{$q}%")),
                );
            })
            ->orderBy('id')
            // 50 por página (decisão do usuário, 2026-07-13). Eram 30, e a página não mostrava os
            // controles de paginação — a lista simplesmente terminava, e ninguém via os do meio.
            ->paginate(50)
            ->withQueryString();

        return view('admin.jogadores', ['jogadores' => $jogadores, 'q' => $q]);
    }

    /** A ficha completa de um jogador. É o coração do CRUD (D-61). */
    public function jogador(User $user): View
    {
        $colony = $user->colony;

        return view('admin.jogador', [
            'jogador' => $user,
            'colonia' => $colony,
            'suspenso' => Suspender::estaSuspenso($user),
            'indices' => CorrigirEstado::INDICES,

            'recursos' => $colony
                ? $colony->resources()->join('resource_types', 'resources.resource_type', '=', 'resource_types.code')
                    ->orderBy('resource_types.tax_class')->orderBy('resource_types.nome')
                    ->get(['resources.resource_type as code', 'resource_types.nome', 'resources.amount'])
                : collect(),

            'construcoes' => $colony
                ? $colony->buildings()->orderBy('slot')->get()->map(fn ($b) => [
                    'slot' => $b->slot,
                    'type' => $b->type,
                    'nome' => Funcoes::de($b->type)['frase'],
                    'level' => $b->level,
                ])
                : collect(),

            'frota' => $colony ? $colony->vehicles()->orderBy('id')->get() : collect(),
            'conservacao' => app(Conservacao::class),

            'punicoes' => $colony ? Punishment::where('user_id', $user->id)->orderByDesc('id')->limit(10)->get() : collect(),
            'denuncias' => $colony
                ? Report::where('reporter_colony_id', $colony->id)->orWhere('accused_colony_id', $colony->id)
                    ->orderByDesc('id')->limit(10)->get()
                : collect(),
            'acordos' => $colony
                ? TradeAgreement::where('colony_a_id', $colony->id)->orWhere('colony_b_id', $colony->id)
                    ->orderByDesc('id')->limit(10)->get()
                : collect(),

            // O extrato: é ele que explica de onde veio cada unidade — inclusive os `ajuste_admin`
            // que este mesmo painel lança quando o operador corrige alguma coisa (D-61).
            'ledger' => $colony
                ? Ledger::where('colony_id', $colony->id)->orderByDesc('id')->limit(40)->get()
                : collect(),

            // O que este jogador já sofreu do painel. Fecha o círculo: a ficha mostra o rastro
            // administrativo dele sem que se precise garimpar a aba da auditoria.
            'auditoria' => AuditEntry::where('alvo', "user:{$user->id}")
                ->when($colony, fn ($q) => $q->orWhere('alvo', "colony:{$colony->id}"))
                ->orderByDesc('id')->limit(20)->get(),

            'avisosRealocacao' => $colony ? app(RealocarColonia::class)->avisos($colony, 0, 0) : [],
        ]);
    }

    // ─────────────────────────────────────────────────────────── Seções do jogo

    public function ministerio(Request $request): View
    {
        /*
         * A nomeação de conciliador era um `<select>` com **todos** os jogadores do servidor. Funciona
         * com cinco; com quinhentos, é uma lista impossível de percorrer — e o operador não procura um
         * jogador rolando uma lista, ele procura pelo nome de quem acabou de falar com ele.
         *
         * Vira busca (decisão do usuário, 2026-07-13). A lista só aparece quando se procura algo, e o
         * teto de 20 existe para uma busca vaga ("a") não despejar o servidor inteiro na tela.
         */
        $qc = trim((string) $request->query('qc'));

        $candidatos = $qc === ''
            ? collect()
            : User::whereNull('conciliador_desde')
                ->where(fn ($w) => $w
                    ->where('name', 'like', "%{$qc}%")
                    ->orWhere('nickname', 'like', "%{$qc}%")
                    ->orWhere('email', 'like', "%{$qc}%")
                    ->orWhereHas('colony', fn ($c) => $c->where('name', 'like', "%{$qc}%")))
                ->with('colony:id,user_id,name')
                ->orderBy('id')
                ->limit(20)
                ->get();

        return view('admin.ministerio', [
            'conciliadores' => User::whereNotNull('conciliador_desde')->orderBy('id')->get(),
            'candidatos' => $candidatos,
            'qc' => $qc,
            'filaEquipe' => Report::with(['reporter:id,name', 'accused:id,name'])
                ->whereIn('status', ['na_equipe', 'apelado'])->orderBy('id')->get(),
            'atribuidos' => Report::with(['reporter:id,name', 'accused:id,name', 'conciliator:id,nickname'])
                ->where('status', 'atribuido')->orderBy('deadline_at')->get(),
            'emApelacao' => Report::where('status', 'decidido')->orderBy('appeal_until')->get(),
            'especs' => PunicaoSpecs::class,
        ]);
    }

    public function economia(Request $request): View
    {
        $abas = ['financas', 'tesouro', 'subsidios', 'mercado', 'ofertas_globais', 'extrato_governo', 'extrato_colonos'];
        $aba = in_array($request->query('aba'), $abas, true) ? $request->query('aba') : 'financas';

        $ofertasDoGoverno = app(\App\Domain\Market\OfertarComoGoverno::class)->ofertas();

        $dados = [
            'aba' => $aba,
            'colonias' => Colony::orderBy('id')->get(),
            // `preco_base_micro` alimenta a coluna "Preço Base" da aba Mercado (o mesmo preço de
            // referência do §06 que a Secretaria de Finanças já publica para o jogador).
            'recursos' => ResourceType::orderBy('tax_class')->orderBy('nome')
                ->get(['code', 'nome', 'tax_class', 'preco_base_micro']),
            'intervencoes' => PriceIntervention::query()->vigentes()->orderBy('resource_type')->get(),
            'tesouro' => DB::table('treasury_holdings')
                ->join('resource_types', 'treasury_holdings.resource_type', '=', 'resource_types.code')
                ->orderBy('resource_types.tax_class')->orderBy('resource_types.nome')
                ->get(['treasury_holdings.resource_type as code', 'resource_types.nome', 'treasury_holdings.amount']),
            'tesouroFert' => app(Tesouro::class)->saldoFertMicro(),
            'FERT' => Tesouro::FERT,
            // O Governo no Mercado Central (D-87): a oferta de hoje, por recurso — o formulário
            // pré-preenche com isto, e quem não está na lista simplesmente nunca foi anunciado.
            'ofertasDoGoverno' => $ofertasDoGoverno,
            // As notícias saíram daqui: viraram aba própria (2026-07-13). Elas não são economia, e
            // estavam ali só porque a Central de Notícias é vizinha do Tesouro na Capital.
        ];

        // ── Ofertas Globais (D-96): o livro do Mercado Central inteiro, colono a colono, e o Governo
        // junto — sem o filtro `aberta/parcial` que a aba Mercado usa só para as ofertas do Governo.
        if ($aba === 'ofertas_globais') {
            $q = trim((string) $request->query('q'));
            $status = (string) $request->query('status', '');
            $side = (string) $request->query('side', '');
            $recurso = (string) $request->query('recurso', '');

            $dados['ofertasGlobais'] = MarketOrder::query()
                ->with('colony:id,name')
                ->when($q !== '', fn ($w) => $w->where(function ($s) use ($q) {
                    $s->where('resource_type', 'like', "%{$q}%")
                        ->orWhereHas('colony', fn ($c) => $c->where('name', 'like', "%{$q}%"));
                    if (str_contains('governo', mb_strtolower($q))) {
                        $s->orWhereNull('colony_id');
                    }
                }))
                ->when($status !== '', fn ($w) => $w->where('status', $status))
                ->when($side !== '', fn ($w) => $w->where('side', $side))
                ->when($recurso !== '', fn ($w) => $w->where('resource_type', $recurso))
                ->orderByDesc('id')
                ->paginate(30)
                ->withQueryString();
            $dados['filtrosOfertas'] = compact('q', 'status', 'side', 'recurso');
        }

        // ── Extrato do Governo (D-96): o `treasury_ledger` — todo crédito, débito e distribuição
        // que já passou pelo caixa do Tesouro, o espelho administrativo do extrato de um colono.
        if ($aba === 'extrato_governo') {
            $q = trim((string) $request->query('q'));
            $tipo = (string) $request->query('tipo', '');
            $recurso = (string) $request->query('recurso', '');
            $de = (string) $request->query('de', '');
            $ate = (string) $request->query('ate', '');

            $dados['extratoGoverno'] = TreasuryLedger::query()
                ->when($q !== '', fn ($w) => $w->where('ref', 'like', "%{$q}%"))
                ->when($tipo !== '', fn ($w) => $w->where('type', $tipo))
                ->when($recurso === 'fert', fn ($w) => $w->whereNull('resource_type'))
                ->when($recurso !== '' && $recurso !== 'fert', fn ($w) => $w->where('resource_type', $recurso))
                ->when($de !== '', fn ($w) => $w->whereDate('created_at', '>=', $de))
                ->when($ate !== '', fn ($w) => $w->whereDate('created_at', '<=', $ate))
                ->orderByDesc('id')
                ->paginate(30)
                ->withQueryString();
            $dados['filtrosGoverno'] = compact('q', 'tipo', 'recurso', 'de', 'ate');
            $dados['tiposGoverno'] = TreasuryLedger::TIPOS;
        }

        // ── Extrato Colonos (D-96): o `ledger` de TODAS as colônias — o mesmo que a ficha de um
        // jogador já mostra sozinha, aqui juntado, com busca por nome de colônia.
        if ($aba === 'extrato_colonos') {
            $q = trim((string) $request->query('q'));
            $tipo = (string) $request->query('tipo', '');
            $recurso = (string) $request->query('recurso', '');
            $de = (string) $request->query('de', '');
            $ate = (string) $request->query('ate', '');

            $dados['extratoColonos'] = Ledger::query()
                ->with('colony:id,name,user_id')
                ->when($q !== '', fn ($w) => $w->where(fn ($s) => $s
                    ->where('ref', 'like', "%{$q}%")
                    ->orWhereHas('colony', fn ($c) => $c->where('name', 'like', "%{$q}%"))))
                ->when($tipo !== '', fn ($w) => $w->where('type', $tipo))
                ->when($recurso === 'fert', fn ($w) => $w->whereNull('resource_type'))
                ->when($recurso !== '' && $recurso !== 'fert', fn ($w) => $w->where('resource_type', $recurso))
                ->when($de !== '', fn ($w) => $w->whereDate('created_at', '>=', $de))
                ->when($ate !== '', fn ($w) => $w->whereDate('created_at', '<=', $ate))
                ->orderByDesc('id')
                ->paginate(30)
                ->withQueryString();
            $dados['filtrosColonos'] = compact('q', 'tipo', 'recurso', 'de', 'ate');
            $dados['tiposColonos'] = Ledger::TIPOS;
        }

        return view('admin.economia', $dados);
    }

    /**
     * O mural, com os quatro filtros que o usuário pediu (2026-07-13).
     *
     * Antes as notícias eram um pedaço de Economia: dez últimas, e um botão de apagar. Não havia como
     * corrigir uma redação, escondê-la um instante, nem dizer "isto envelheceu" — só destruir, que é
     * justamente o que um mural público não pode fazer com o registro do que foi dito.
     */
    public function noticias(Request $request): View
    {
        $q = trim((string) $request->query('q'));
        $estado = (string) $request->query('estado', '');
        $kind = (string) $request->query('kind', '');
        $de = (string) $request->query('de', '');
        $ate = (string) $request->query('ate', '');

        $noticias = News::query()
            ->when($q !== '', fn ($w) => $w->where(fn ($s) => $s
                ->where('title', 'like', "%{$q}%")
                ->orWhere('body', 'like', "%{$q}%")))
            ->when($estado === 'mural', fn ($w) => $w->noMural())
            ->when($estado === 'oculta', fn ($w) => $w->whereNotNull('hidden_at')->whereNull('inactive_at'))
            ->when($estado === 'inativa', fn ($w) => $w->whereNotNull('inactive_at'))
            ->when($estado === 'agendada', fn ($w) => $w
                ->whereNull('hidden_at')->whereNull('inactive_at')->where('published_at', '>', now()))
            ->when($kind !== '', fn ($w) => $w->where('kind', $kind))
            ->when($de !== '', fn ($w) => $w->whereDate('published_at', '>=', $de))
            ->when($ate !== '', fn ($w) => $w->whereDate('published_at', '<=', $ate))
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return view('admin.noticias', [
            'noticias' => $noticias,
            'filtros' => compact('q', 'estado', 'kind', 'de', 'ate'),
            // Os tipos que existem de fato. Hoje só há `comunicado`; o seletor não inventa outros.
            'kinds' => News::query()->distinct()->orderBy('kind')->pluck('kind'),
        ]);
    }

    /**
     * Bugs/Melhorias (D-95): o que os jogadores mandaram, com os mesmos filtros que a lista de
     * notícias já usa (estado, tipo, busca) — é a mesma forma de problema: uma fila que só cresce.
     */
    public function feedback(Request $request): View
    {
        $q = trim((string) $request->query('q'));
        $estado = (string) $request->query('estado', '');
        $tipo = (string) $request->query('tipo', '');

        $feedback = \App\Models\Feedback::query()
            ->when($q !== '', fn ($w) => $w->where(fn ($s) => $s
                ->where('assunto', 'like', "%{$q}%")
                ->orWhere('mensagem', 'like', "%{$q}%")
                ->orWhere('nickname', 'like', "%{$q}%")
                ->orWhere('colony_name', 'like', "%{$q}%")))
            ->when($estado === 'nao_lida', fn ($w) => $w->whereNull('lida_at'))
            ->when($estado === 'lida', fn ($w) => $w->whereNotNull('lida_at'))
            ->when($estado === 'respondida', fn ($w) => $w->whereNotNull('respondida_at'))
            ->when($estado === 'feita', fn ($w) => $w->whereNotNull('feito_at'))
            ->when($estado === 'pendente', fn ($w) => $w->whereNull('feito_at'))
            ->when($tipo !== '', fn ($w) => $w->where('tipo', $tipo))
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return view('admin.feedback', [
            'feedback' => $feedback,
            'filtros' => compact('q', 'estado', 'tipo'),
            'tipos' => \App\Models\Feedback::TIPOS,
        ]);
    }

    /**
     * A gestão de imagens (D-68).
     *
     * Uma aba por categoria. Em cada uma: a biblioteca (miniaturas, envio, exclusão) e as coisas do
     * jogo que aquela categoria costuma vestir, cada uma com um seletor.
     *
     * ⚠️ **O seletor lista TODAS as imagens, não só as da categoria.** A arrumação por categoria é
     * conveniência, não trava: se a melhor arte para a Oficina estiver na pasta das especializações,
     * o operador tem de poder usá-la sem mover arquivo nenhum.
     */
    public function imagens(Request $request): View
    {
        $categoria = (string) $request->query('cat', array_key_first(Biblioteca::CATEGORIAS));

        if (! array_key_exists($categoria, Biblioteca::CATEGORIAS)) {
            $categoria = array_key_first(Biblioteca::CATEGORIAS);
        }

        $grupos = Vinculaveis::porCategoria();

        return view('admin.imagens', [
            'categorias' => Biblioteca::CATEGORIAS,
            'categoria' => $categoria,

            // As imagens desta categoria — o que se envia, se vê e se apaga.
            'imagens' => MediaAsset::where('category', $categoria)->orderBy('filename')->get(),

            // TODAS, para o seletor. A categoria arruma; não tranca.
            'todasAsImagens' => MediaAsset::orderBy('category')->orderBy('filename')->get()
                ->groupBy('category'),

            // As coisas do jogo que esta categoria costuma vestir. Nulo se a categoria não veste nada
            // (é o caso de `mapas`, `espacoporto`, `destrocos-da-endurance` — a arte deles vai para
            // as ÁREAS da Capital, que estão sob `capital`).
            'grupo' => $grupos[$categoria] ?? null,

            // Quem já tem imagem. `entity_key => MediaAsset`.
            'vinculos' => ImageBinding::with('asset')->get()->keyBy('entity_key'),

            'contagem' => MediaAsset::selectRaw('category, count(*) as n')
                ->groupBy('category')->pluck('n', 'category'),

            // Quantas coisas do jogo ainda estão sem arte — é o número que diz quanto falta.
            'semArte' => count(Vinculaveis::todas()) - ImageBinding::count(),
            'totalVinculavel' => count(Vinculaveis::todas()),
        ]);
    }

    /**
     * A guerra (§27, §28.10; D-70). **Dez números que só se mudavam por SQL.**
     *
     * O §27.3 escreve os bônus defensivos como "(valores configuráveis)" e o §28.10 manda calcular
     * duas chances que nunca publica — quer dizer: o GDD delega ao operador e o painel não oferecia
     * onde. Até aqui, mexer no preço do Nióbio ou no alcance da Torre exigia um `UPDATE` à mão na
     * produção, que é exatamente o tipo de coisa que ninguém audita e ninguém desfaz.
     *
     * A tela também mostra **a guerra como ela está agora**: quem marcha contra quem, que zonas
     * estão sitiadas e quanto exército existe no planeta. Sem isso o operador só descobre que a
     * guerra desandou quando os jogadores reclamam.
     */
    public function guerra(): View
    {
        return view('admin.guerra', [
            'guerra' => WarSetting::singleton(),

            'combates' => Combat::with(['zone:id,x,y,mineral', 'attacker:id,name', 'defender:id,name'])
                ->whereIn('status', ['marchando', 'em_curso'])
                ->orderBy('proxima_rodada_at')
                ->limit(100)
                ->get(),

            // As sitiadas primeiro: são as que têm relógio correndo (48 h) e é onde o operador olha.
            'cercadas' => NeutralZone::with('owner:id,name')
                ->whereNotNull('sieged_at')
                ->orderBy('sieged_at')
                ->get(),

            'exercito' => Unit::selectRaw('type, count(*) as n')
                ->groupBy('type')
                ->pluck('n', 'type'),

            // Quanto Nióbio o governo já vendeu, em estoque nas colônias. É o teto do exército que
            // ainda pode nascer: 3 por Sentinela, e nada no planeta o produz (D-66).
            'niobio' => (int) DB::table('resources')
                ->where('resource_type', 'niobio_alienigena')
                ->sum('amount'),

            // Os olhos do planeta (D-74): os Drones não são `units` — são veículos — e sem esta
            // lista a guerra de informação seria invisível para o operador.
            'drones' => \App\Models\Vehicle::with('colony:id,name')
                ->where('type', \App\Domain\Drone\DroneSpecs::TIPO)
                ->orderBy('id')
                ->get(),
            'fotos' => (int) DB::table('drone_sightings')->count(),
        ]);
    }

    public function transportes(Request $request): View
    {
        $abas = ['ministerio', 'fabrica', 'garagem', 'frota'];
        $aba = in_array($request->query('aba'), $abas, true) ? $request->query('aba') : 'ministerio';

        $dados = [
            'aba' => $aba,
            'conservacao' => app(Conservacao::class),
            'transporte' => TransportSetting::singleton(),
        ];

        if ($aba === 'ministerio') {
            $dados['frotaGoverno'] = [];
            foreach (Ministerio::TIPOS as $tipo) {
                $dados['frotaGoverno'][$tipo] = [
                    'estoque' => Vehicle::whereNull('colony_id')->where('type', $tipo)->where('status', 'estoque')->count(),
                    'fabricando' => Vehicle::whereNull('colony_id')->where('type', $tipo)->where('status', 'fabricando')->count(),
                    'alvo' => Ministerio::config($tipo)['estoque_alvo'],
                ];
            }
            $dados['volumeVeiculos'] = [
                'registrados' => Vehicle::whereNotNull('plate')->count(),
                'em_rota' => Vehicle::where('status', 'em_rota')->count(),
                'anunciados' => VehicleListing::where('status', 'aberto')->count(),
                'vendidos' => VehicleListing::where('status', 'concluido')->count(),
                'sucateados' => Vehicle::onlyTrashed()->count(),
                'sucateados_7d' => Vehicle::onlyTrashed()->where('deleted_at', '>=', now()->subDays(7))->count(),
                'vendidos_7d' => VehicleListing::where('status', 'concluido')
                    ->where('updated_at', '>=', now()->subDays(7))->count(),
            ];
        }

        // A Fábrica (D-109): preço, estoque-alvo, tempo e custo em recursos, por tipo — hoje
        // admin-editável, antes era constante de PHP.
        if ($aba === 'fabrica') {
            $dados['fabricaConfig'] = collect(Ministerio::TIPOS)
                ->mapWithKeys(fn ($tipo) => [$tipo => Ministerio::config($tipo)]);
            $dados['fabricaEstoque'] = collect(Ministerio::TIPOS)->mapWithKeys(fn ($tipo) => [
                $tipo => [
                    'estoque' => Vehicle::whereNull('colony_id')->where('type', $tipo)->where('status', 'estoque')->count(),
                    'fabricando' => Vehicle::whereNull('colony_id')->where('type', $tipo)->where('status', 'fabricando')->count(),
                ],
            ]);
        }

        // A Garagem do frete público (D-76): a frota real do serviço do §07.
        if ($aba === 'garagem') {
            $dados['garagem'] = \App\Domain\Frete\Garagem::frota()->orderBy('id')->get();
            $dados['garagemLivres'] = \App\Domain\Frete\Garagem::livres()->count();
        }

        /*
         * A frota inteira do planeta, com placa (2026-07-13). O painel dizia quantos veículos
         * havia e nunca **quais** — e a placa é o único identificador de um veículo que aparece
         * na tela de outro jogador (§16.3), logo é por ela que uma reclamação chega ao operador.
         *
         * Inclui os SUCATEADOS (`withTrashed`): a sucata arquiva e não apaga, justamente para a
         * placa não ser reciclada — e um veículo que sumiu da lista é um veículo que ninguém mais
         * consegue rastrear.
         *
         * A busca por Dono e a ordenação por cabeçalho (D-96) exigem um join com `colonies` — o
         * dono só existe do lado de lá, e `orderBy` num relacionamento Eloquent não alcança o SQL.
         */
        if ($aba === 'frota') {
            $ordenaveis = [
                'placa' => 'vehicles.plate', 'tipo' => 'vehicles.type', 'dono' => 'colonies.name',
                'situacao' => 'vehicles.status', 'conservacao' => 'vehicles.conservacao_bps',
                'teto' => 'vehicles.teto_conservacao_bps', 'manutencao' => 'vehicles.manutencoes',
                'uso' => 'vehicles.uso_ativo_seg',
            ];
            $sort = (string) $request->query('sort', '');
            $coluna = $ordenaveis[$sort] ?? null;
            $dir = $request->query('dir') === 'desc' ? 'desc' : 'asc';
            $dono = trim((string) $request->query('dono', ''));

            $dados['veiculos'] = Vehicle::withTrashed()
                ->with('colony:id,name')
                ->leftJoin('colonies', 'colonies.id', '=', 'vehicles.colony_id')
                ->select('vehicles.*')
                ->when($request->query('placa'), fn ($w, $p) => $w->where('vehicles.plate', 'like', "%{$p}%"))
                ->when($dono !== '', fn ($w) => $w->where('colonies.name', 'like', "%{$dono}%"))
                ->when(
                    $coluna,
                    fn ($w) => $w->orderBy($coluna, $dir),
                    // sem placa por último: elas são a coluna que se lê
                    fn ($w) => $w->orderByRaw('vehicles.plate is null')->orderBy('vehicles.plate'),
                )
                ->paginate(50)
                ->withQueryString();
            $dados['placa'] = (string) $request->query('placa', '');
            $dados['dono'] = $dono;
            $dados['sort'] = $sort;
            $dados['dir'] = $dir;
        }

        return view('admin.transportes', $dados);
    }

    /**
     * A Operação: o tick, e a realocação — que é **pontual**, sobre um jogador escolhido.
     *
     * ⚠️ **Não há realocação em massa aqui, e isso é decisão do usuário (2026-07-13).** Existiu um
     * botão "Realocar founders" que movia **todas as colônias do jogo de uma vez**. Ele era a
     * ferramenta de uma migração histórica (D-51) que ficara pendurada nesta tela, ao lado do
     * "Disparar tick", como se fosse coisa que se faz no dia a dia. Foi retirado.
     */
    public function operacao(): View
    {
        // O kit inicial (D-85, editável desde o D-92): um valor por recurso, na mesma ordem por
        // classe que o resto do painel já usa, com o que está gravado hoje ao lado do nome.
        $kitRecursos = DB::table('resource_types')
            ->leftJoin('kit_inicial_recursos', 'kit_inicial_recursos.resource_type', '=', 'resource_types.code')
            ->orderBy('resource_types.tax_class')->orderBy('resource_types.nome')
            ->get(['resource_types.code', 'resource_types.nome', 'resource_types.tax_class',
                DB::raw('COALESCE(kit_inicial_recursos.amount, 0) as amount')]);

        return view('admin.operacao', [
            'resumo' => $this->resumo(),
            // Com a colônia de cada uma, para o operador ver de onde ela sai antes de escolher o destino.
            'colonias' => Colony::with('user:id,nickname')->orderBy('id')->get(),
            // Os valores de XP por ato (D-75) — e o marco de cada colônia sai da lista acima.
            'marco' => \App\Models\MilestoneSetting::singleton(),
            'kitRecursos' => $kitRecursos,
            'kitSettings' => \App\Models\KitInicialSetting::singleton(),
            // As chaves batem com `Vehicle::CAPACIDADE` — hoje só duas, mas o formulário não
            // hardcoda os tipos, para não ficar obsoleto se um terceiro veículo aparecer.
            'kitVeiculos' => \App\Models\Vehicle::CAPACIDADE,
            'kitMuroNiobio' => \App\Domain\Colony\KitInicial::MURO_NIOBIO_REABRE_EM,
            'kitMuroQuartzo' => \App\Domain\Colony\KitInicial::MURO_QUARTZO_REABRE_EM,
        ]);
    }

    /**
     * Gestão de Construções (D-107): tempo, Silo e custo — hoje fixos no GDD, o admin passa a
     * ajustar por nível. Três sub-abas, mesmo padrão `?aba=` de `missoes()`.
     */
    public function construcoes(Request $request): View
    {
        $abas = ['tempo', 'custo', 'silo', 'fila', 'manutencao'];
        $aba = in_array($request->query('aba'), $abas, true) ? $request->query('aba') : 'tempo';

        $dados = ['aba' => $aba, 'naoConstroi' => \App\Models\Building::NASCE_NO_NIVEL_UM];

        if ($aba === 'tempo' || $aba === 'custo') {
            $dados['grupos'] = $this->construcoesAgrupadas();
        } elseif ($aba === 'silo') {
            $dados['capacidades'] = DB::table('silo_capacidades')
                ->orderBy('resource_type')->orderBy('level')
                ->get()
                ->groupBy('resource_type');
            $dados['recursos'] = \App\Models\ResourceType::orderBy('tax_class')->orderBy('nome')->get();
            $dados['niveisSilo'] = range(1, 10);
        } elseif ($aba === 'manutencao') {
            $dados['gruposManutencao'] = $this->manutencaoAgrupada();
            // Raros ficam de fora — decisão do usuário (D-112): só primário e industrial.
            $dados['recursosManutencao'] = \App\Models\ResourceType::where('tax_class', '!=', 'raro')
                ->orderBy('tax_class')->orderBy('nome')->get();
        } else {
            $dados['fila'] = \App\Models\FilaSetting::singleton();
        }

        return view('admin.construcoes', $dados);
    }

    /**
     * Toda `building_specs`, base + o ajuste do admin quando existir, agrupada como o painel de
     * imagens já agrupa (`Vinculaveis`) — essenciais, progressão, zona neutra, veículos/unidades —
     * mais um grupo "outras" pro que nenhum dos quatro cobre (hoje só o Depósito Local, que não
     * está em `Building::MVP` de propósito — D-105/106).
     */
    private function construcoesAgrupadas(): array
    {
        $base = DB::table('building_specs')->orderBy('building_type')->orderBy('level')->get();
        $overrides = DB::table('building_specs_overrides')->get()
            ->keyBy(fn ($o) => "{$o->building_type}:{$o->level}");

        $porTipo = $base->groupBy('building_type');

        $jaAgrupado = [];
        $grupos = [];

        foreach ($this->definicaoDeGrupos() as $titulo => $tipos) {
            $itens = [];

            foreach ($tipos as $tipo) {
                if (isset($jaAgrupado[$tipo]) || ! $porTipo->has($tipo)) {
                    continue;
                }
                $jaAgrupado[$tipo] = true;
                $itens[] = $this->construcaoParaAba($tipo, $porTipo[$tipo], $overrides);
            }

            if ($itens !== []) {
                $grupos[$titulo] = $itens;
            }
        }

        // O que sobrar (hoje: só o Depósito Local) — nenhum tipo de building_specs fica de fora.
        $outras = [];
        foreach ($porTipo->keys() as $tipo) {
            if (! isset($jaAgrupado[$tipo])) {
                $outras[] = $this->construcaoParaAba($tipo, $porTipo[$tipo], $overrides);
            }
        }
        if ($outras !== []) {
            $grupos['Outras'] = $outras;
        }

        return $grupos;
    }

    /** As mesmas quatro categorias que agrupam Tempo/Custo, reaproveitadas em Manutenção (D-112). */
    private function definicaoDeGrupos(): array
    {
        return [
            'As cinco essenciais' => \App\Models\Building::ESSENCIAIS,
            'Progressão da colônia' => \App\Models\Building::PROGRESSAO,
            'Zona neutra' => array_keys(\App\Domain\Zona\Estruturas::COLUNA),
            'Veículos e unidades' => [
                'furgao_de_comercio', 'caminhao_de_carga', 'nave_de_transporte_planetaria',
                'drone_de_exploracao', 'sentinela', 'robo_minerador', 'infiltrador', 'predador',
            ],
        ];
    }

    /**
     * Gestão de Construções — Manutenção (D-112): consumo extra de recursos por hora, por TIPO de
     * construção (sem nível — diferente de Tempo/Custo). Mesmo agrupamento por categoria de
     * `construcoesAgrupadas()`, mas listando o que já está configurado em `manutencao_estruturas`
     * em vez de tempo/custo.
     */
    private function manutencaoAgrupada(): array
    {
        $tipos = DB::table('building_specs')->select('building_type')->distinct()->pluck('building_type');
        $config = DB::table('manutencao_estruturas')->get()->groupBy('building_type');

        $paraAba = fn (string $tipo) => [
            'tipo' => $tipo,
            'nome' => \App\Domain\Media\NomesDeExibicao::de($tipo),
            'recursos' => ($config->get($tipo) ?? collect())->pluck('qtd_hora', 'resource_type')->all(),
        ];

        $jaAgrupado = [];
        $grupos = [];

        foreach ($this->definicaoDeGrupos() as $titulo => $listaTipos) {
            $itens = [];

            foreach ($listaTipos as $tipo) {
                if (isset($jaAgrupado[$tipo]) || ! $tipos->contains($tipo)) {
                    continue;
                }
                $jaAgrupado[$tipo] = true;
                $itens[] = $paraAba($tipo);
            }

            if ($itens !== []) {
                $grupos[$titulo] = $itens;
            }
        }

        $outras = [];
        foreach ($tipos as $tipo) {
            if (! isset($jaAgrupado[$tipo])) {
                $outras[] = $paraAba($tipo);
            }
        }
        if ($outras !== []) {
            $grupos['Outras'] = $outras;
        }

        return $grupos;
    }

    private function construcaoParaAba(string $tipo, $niveis, $overrides): array
    {
        return [
            'tipo' => $tipo,
            'nome' => \App\Domain\Media\NomesDeExibicao::de($tipo),
            'niveis' => $niveis->map(function ($n) use ($tipo, $overrides) {
                $override = $overrides->get("{$tipo}:{$n->level}");

                return [
                    'nivel' => $n->level,
                    'tempo_base_min' => $n->build_time_seconds !== null ? (int) round($n->build_time_seconds / 60) : null,
                    'tempo_override_min' => $override?->build_time_seconds !== null
                        ? (int) round($override->build_time_seconds / 60) : null,
                    'custo_base' => json_decode($n->cost_json, true),
                    'custo_override' => $override?->cost_json !== null ? json_decode($override->cost_json, true) : null,
                ];
            })->values()->all(),
        ];
    }

    /**
     * A aba Missões (§06; D-78) — o CRUD do catálogo. Ganhou aba própria porque o formulário de
     * criar/editar é grande demais para caber num card da Operação sem afogar o resto da tela.
     */
    public function missoes(Request $request): View
    {
        $abas = ['catalogo', 'criar', 'baralho'];
        $aba = in_array($request->query('aba'), $abas, true) ? $request->query('aba') : 'catalogo';

        $dados = [
            'aba' => $aba,
            'acoes' => \App\Domain\Missoes\Acoes::TODAS,
            'nomeCategoria' => \App\Models\MissionTemplate::CATEGORIAS,
        ];

        /*
         * A visão geral do catálogo (D-96): por molde, quantas vezes foi sorteada e como terminou —
         * concluída, rejeitada, ainda ativa (vigente ou já vencida sem ninguém ter voltado a olhar).
         * Sem isto o operador só via "sorteada: N×" e não tinha como saber se o molde estava
         * funcionando (concluída na maioria) ou era ignorado/expirava sem ninguém tocar.
         */
        if ($aba === 'catalogo') {
            $porMolde = \App\Models\MissionAssignment::selectRaw('template_id, status, count(*) as total')
                ->groupBy('template_id', 'status')
                ->get()
                ->groupBy('template_id');

            $ativasVigentes = \App\Models\MissionAssignment::ativa()
                ->selectRaw('template_id, count(*) as total')
                ->groupBy('template_id')
                ->pluck('total', 'template_id');

            $ultimaSorteada = \App\Models\MissionAssignment::selectRaw('template_id, max(created_at) as ultima')
                ->groupBy('template_id')
                ->pluck('ultima', 'template_id');

            $dados['catalogo'] = \App\Models\MissionTemplate::withCount('assignments')
                ->orderBy('categoria')->orderBy('chave')->get()
                ->map(function ($t) use ($porMolde, $ativasVigentes, $ultimaSorteada) {
                    $porStatus = ($porMolde[$t->id] ?? collect())->pluck('total', 'status');
                    $vigentes = (int) ($ativasVigentes[$t->id] ?? 0);
                    $ativasTotal = (int) ($porStatus['ativa'] ?? 0);

                    return [
                        'template' => $t,
                        'sorteada' => $t->assignments_count,
                        'concluida' => (int) ($porStatus['concluida'] ?? 0),
                        'rejeitada' => (int) ($porStatus['rejeitada'] ?? 0),
                        'ativa_vigente' => $vigentes,
                        'ativa_vencida' => max(0, $ativasTotal - $vigentes),
                        'ultima_sorteada' => $ultimaSorteada[$t->id] ?? null,
                    ];
                });
        }

        // O baralho, com sub-abas por categoria (D-96) — a lista crescia sem parar numa página só.
        if ($aba === 'baralho') {
            $categorias = array_keys(\App\Models\MissionTemplate::CATEGORIAS);
            $cat = (string) $request->query('cat', '');
            $cat = in_array($cat, $categorias, true) ? $cat : $categorias[0];

            $dados['catAtual'] = $cat;
            $dados['missoes'] = \App\Models\MissionTemplate::withCount('assignments')
                ->where('categoria', $cat)
                ->orderBy('chave')->get();
        }

        return view('admin.missoes', $dados);
    }

    /** A aba Chat (§10; D-77): o rádio do planeta pelos olhos do moderador. */
    public function chat(\Illuminate\Http\Request $request): View
    {
        $privadaA = (int) $request->query('privada_a');
        $privadaB = (int) $request->query('privada_b');

        /*
         * A conversa privada SÓ aparece se a URL veio do `chatEspiar` — que registrou o acesso na
         * auditoria antes de redirecionar (§10.3: "todo acesso interno é registrado"). Abrir esta
         * página sem os parâmetros não mostra privada nenhuma.
         */
        $privada = ($privadaA && $privadaB)
            ? \App\Models\ChatMessage::where('channel', 'privada')
                ->where(fn ($q) => $q
                    ->where(fn ($a) => $a->where('user_id', $privadaA)->where('recipient_user_id', $privadaB))
                    ->orWhere(fn ($b) => $b->where('user_id', $privadaB)->where('recipient_user_id', $privadaA)))
                ->with('user:id,nickname')->orderByDesc('id')->limit(100)->get()->reverse()->values()
            : collect();

        return view('admin.chat', [
            'config' => \App\Models\ChatSetting::singleton(),
            'mensagens' => \App\Models\ChatMessage::where('channel', '!=', 'privada')
                ->with('user:id,nickname')->orderByDesc('id')->limit(100)->get(),
            'reincidencia' => \Illuminate\Support\Facades\DB::table('chat_filter_hits')
                ->join('users', 'users.id', '=', 'chat_filter_hits.user_id')
                ->selectRaw('users.nickname, count(*) as barradas, max(chat_filter_hits.created_at) as ultima')
                ->groupBy('users.nickname')->orderByDesc('barradas')->limit(20)->get(),
            'silenciados' => \App\Models\Punishment::where('kind', \App\Domain\Ministry\PunicaoSpecs::SILENCIO)
                ->vigente()->with('user:id,nickname')->get(),
            'privada' => $privada,
        ]);
    }

    // ─────────────────────────────────────────────────────────── Auditoria e admins

    /**
     * O log de auditoria (D-61), com filtros.
     *
     * Ele existe para responder à pergunta que o `ledger` não responde: **quem, do lado de dentro,
     * fez isto?** Append-only — nem o admin apaga.
     */
    public function auditoria(Request $request): View
    {
        $acao = (string) $request->query('acao', '');
        $adminId = (string) $request->query('admin', '');
        $alvo = trim((string) $request->query('alvo', ''));

        $entradas = AuditEntry::query()
            ->with('admin:id,name,email')
            ->when($acao !== '', fn ($q) => $q->where('acao', $acao))
            ->when($adminId !== '', fn ($q) => $q->where('admin_id', $adminId))
            ->when($alvo !== '', fn ($q) => $q->where('alvo', 'like', "%{$alvo}%"))
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        return view('admin.auditoria', [
            'entradas' => $entradas,
            'acoes' => AuditEntry::select('acao')->distinct()->orderBy('acao')->pluck('acao'),
            'admins' => Admin::orderBy('name')->get(['id', 'name', 'email']),
            'filtro' => ['acao' => $acao, 'admin' => $adminId, 'alvo' => $alvo],
        ]);
    }

    /** O CRUD de contas de admin. Só o dono chega aqui — a rota tem o middleware `dono`. */
    public function admins(): View
    {
        return view('admin.admins', [
            'admins' => Admin::orderBy('id')->get(),
            'papeis' => Admin::PAPEIS,
        ]);
    }

    /** Números de topo. */
    private function resumo(): array
    {
        return [
            'colonias' => Colony::count(),
            'jogadores' => User::count(),
            'suspensos' => User::whereNotNull('suspenso_em')
                ->where(fn ($q) => $q->whereNull('suspenso_ate')->orWhere('suspenso_ate', '>', now()))
                ->count(),
            'admins' => Admin::whereNull('desativado_em')->count(),
            'fert_em_circulacao_micro' => (int) Colony::sum('fert_micro'),
            'casos_na_equipe' => Report::whereIn('status', ['na_equipe', 'apelado'])->count(),
            'ordens_abertas' => DB::table('market_orders')->whereIn('status', ['aberta', 'parcial'])->count(),
            'veiculos_em_rota' => Vehicle::where('status', 'em_rota')->count(),
            'veiculos_ociosos' => Vehicle::where('status', 'ocioso')->count(),
            'zonas_ocupadas' => NeutralZone::whereNotNull('owner_colony_id')->count(),
            // Bugs/Melhorias (D-95): o card de "tem coisa nova" na Visão Geral.
            'feedback_nao_lido' => \App\Models\Feedback::whereNull('lida_at')->count(),
        ];
    }

    /**
     * Recursos sem oferta ativa do Governo no Mercado Central (D-87) — inclusive os que nunca
     * foram anunciados, não só os que zeraram: é a lista do que falta preencher, não só repor.
     */
    private function recursosSemOfertaDoGoverno(): \Illuminate\Support\Collection
    {
        $comOferta = DB::table('market_orders')
            ->whereNull('colony_id')
            ->whereIn('status', ['aberta', 'parcial'])
            ->pluck('resource_type');

        return ResourceType::whereNotIn('code', $comOferta)->orderBy('nome')->pluck('nome');
    }
}
