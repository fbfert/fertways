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
            // "Capital" (D-91) é uma conta de sistema, não um jogador — sem colônia, sem senha
            // utilizável, e nada aqui deveria poder suspendê-la ou editá-la como se fosse gente.
            ->where('email', '!=', ContaSistema::EMAIL_CAPITAL)
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
        $abas = ['financas', 'tesouro', 'enviar', 'mercado', 'ofertas_globais', 'extrato_governo', 'extrato_colonos'];
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
        return view('admin.transportes', [
            // A Garagem do frete público (D-76): a frota real do serviço do §07.
            'garagem' => \App\Domain\Frete\Garagem::frota()->orderBy('id')->get(),
            'garagemLivres' => \App\Domain\Frete\Garagem::livres()->count(),
            /*
             * A frota inteira do planeta, com placa (2026-07-13). O painel dizia quantos veículos
             * havia e nunca **quais** — e a placa é o único identificador de um veículo que aparece
             * na tela de outro jogador (§16.3), logo é por ela que uma reclamação chega ao operador.
             *
             * Inclui os SUCATEADOS (`withTrashed`): a sucata arquiva e não apaga, justamente para a
             * placa não ser reciclada — e um veículo que sumiu da lista é um veículo que ninguém mais
             * consegue rastrear.
             */
            'veiculos' => Vehicle::withTrashed()
                ->with('colony:id,name')
                ->when($request->query('placa'), fn ($w, $p) => $w->where('plate', 'like', "%{$p}%"))
                ->orderByRaw('plate is null')   // sem placa por último: elas são a coluna que se lê
                ->orderBy('plate')
                ->paginate(50)
                ->withQueryString(),
            'placa' => (string) $request->query('placa', ''),
            'conservacao' => app(Conservacao::class),
            'transporte' => TransportSetting::singleton(),
            'frotaGoverno' => [
                'estoque' => Vehicle::whereNull('colony_id')->where('status', 'estoque')->count(),
                'fabricando' => Vehicle::whereNull('colony_id')->where('status', 'fabricando')->count(),
                'alvo' => Ministerio::ESTOQUE_ALVO,
            ],
            'volumeVeiculos' => [
                'registrados' => Vehicle::whereNotNull('plate')->count(),
                'em_rota' => Vehicle::where('status', 'em_rota')->count(),
                'anunciados' => VehicleListing::where('status', 'aberto')->count(),
                'vendidos' => VehicleListing::where('status', 'concluido')->count(),
                'sucateados' => Vehicle::onlyTrashed()->count(),
                'sucateados_7d' => Vehicle::onlyTrashed()->where('deleted_at', '>=', now()->subDays(7))->count(),
                'vendidos_7d' => VehicleListing::where('status', 'concluido')
                    ->where('updated_at', '>=', now()->subDays(7))->count(),
            ],
        ]);
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
     * A aba Missões (§06; D-78) — o CRUD do catálogo. Ganhou aba própria porque o formulário de
     * criar/editar é grande demais para caber num card da Operação sem afogar o resto da tela.
     */
    public function missoes(): View
    {
        return view('admin.missoes', [
            'missoes' => \App\Models\MissionTemplate::withCount('assignments')
                ->orderBy('categoria')->orderBy('chave')->get(),
            'acoes' => \App\Domain\Missoes\Acoes::TODAS,
        ]);
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
