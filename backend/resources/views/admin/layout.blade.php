<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- Só o mapa (D-146/D-147) faz requisição via JS neste painel — o resto é formulário comum
         com @csrf. Sem este meta, o fetch de "Liberar Fundação" não teria como mandar o token. --}}
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>FERTWAYS — Administração</title>
    <style>
        :root {
            --rust: #b4450b; --rust-bright: #cd5512; --ember: #eaae65;
            --sand: #f8e7d6; --sand-light: #fdf0e2; --ink: #1e1c17; --ink-soft: #372f27;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0; background: var(--sand); color: var(--ink);
            font-family: Inter, "Segoe UI", system-ui, Arial, sans-serif; font-size: 14px; line-height: 1.5;
        }
        a { color: var(--rust); }
        header.topo {
            display: flex; align-items: center; justify-content: space-between;
            background: var(--ink); color: var(--sand-light); padding: 12px 20px;
        }
        header.topo .marca { font-weight: 900; letter-spacing: .04em; }
        header.topo .marca small { color: var(--ember); font-weight: 700; letter-spacing: .18em; text-transform: uppercase; font-size: .6rem; display: block; }
        main { max-width: 1100px; margin: 0 auto; padding: 20px; }
        h2.secao {
            color: var(--rust); text-transform: uppercase; letter-spacing: .12em; font-size: .72rem;
            margin: 28px 0 10px; border-bottom: 1px solid rgba(180,69,11,.2); padding-bottom: 6px;
        }
        .cartao { background: var(--sand-light); border: 1px solid rgba(180,69,11,.2); padding: 14px; margin-bottom: 12px; }
        .grade { display: grid; gap: 10px; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); }
        .tile { background: var(--sand-light); border: 1px solid rgba(180,69,11,.2); padding: 10px; text-align: center; }
        .tile b { display: block; font-size: 1.4rem; font-weight: 900; }
        .tile span { color: var(--ink-soft); text-transform: uppercase; letter-spacing: .1em; font-size: .58rem; }
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; padding: 6px 8px; border-bottom: 1px solid rgba(180,69,11,.12); vertical-align: top; }
        th { color: var(--ink-soft); text-transform: uppercase; letter-spacing: .1em; font-size: .6rem; }
        .num { text-align: right; font-variant-numeric: tabular-nums; }
        button, input[type=submit] {
            background: var(--rust); color: var(--sand-light); border: 0; padding: 6px 12px;
            font-weight: 700; cursor: pointer; font-size: .82rem;
        }
        button:hover { background: var(--rust-bright); }
        button.leve { background: transparent; color: var(--ink-soft); border: 1px solid rgba(180,69,11,.3); }
        button.leve:hover { color: var(--rust); background: transparent; }
        button.perigo { background: #8a2f08; }
        input[type=text], input[type=number], input[type=email], input[type=password], select, textarea {
            border: 1px solid rgba(180,69,11,.3); background: var(--sand); padding: 6px 8px; font: inherit; width: 100%;
        }
        form.inline { display: inline; }
        .linha-form { display: flex; gap: 8px; flex-wrap: wrap; align-items: flex-end; margin-top: 8px; }
        .linha-form > div { flex: 1; min-width: 120px; }
        .linha-form label { display: block; font-size: .6rem; text-transform: uppercase; letter-spacing: .08em; color: var(--ink-soft); margin-bottom: 2px; }
        .flash { padding: 10px 14px; margin: 14px 0; font-weight: 700; }
        .flash.ok { background: #dff0d8; color: #2f5e1e; border: 1px solid #9fca86; }
        .flash.erro { background: #f4d9d0; color: #8a2f08; border: 1px solid #d8a58f; }
        .pilula { display: inline-block; padding: 1px 7px; border-radius: 999px; font-size: .66rem; font-weight: 700; }
        .pilula.alerta { background: var(--ember); color: var(--ink); }
        .mut { color: var(--ink-soft); }
        .pequeno { font-size: .72rem; }

        /* ── Navegação por seções (D-61). A página única não aguentava o CRUD e o log. ── */
        nav.abas {
            display: flex; gap: 2px; flex-wrap: wrap; background: var(--ink-soft); padding: 0 20px;
        }
        nav.abas a {
            color: rgba(253,240,226,.7); text-decoration: none; padding: 9px 13px;
            font-size: .74rem; font-weight: 700; text-transform: uppercase; letter-spacing: .06em;
        }
        nav.abas a:hover { color: var(--ember); }
        nav.abas a.ativa { background: var(--sand); color: var(--rust); }

        /* A paginação (ver resources/views/admin/paginacao.blade.php). */
        .paginacao { display: flex; align-items: center; gap: 12px; }
        .paginacao .pg {
            padding: 4px 10px;
            border: 1px solid rgba(180, 69, 11, .25);
            text-decoration: none;
            color: var(--rust);
            font-weight: 700;
            font-size: 13px;
        }
        .paginacao a.pg:hover { background: var(--sand); }
        .paginacao .pg.mut { color: #aaa; border-color: #e5e5e5; font-weight: 400; }
        header.topo .busca { display: flex; gap: 6px; align-items: center; }
        header.topo .busca input {
            width: 220px; background: rgba(255,255,255,.1); border-color: rgba(255,255,255,.25);
            color: var(--sand-light);
        }
        header.topo .busca input::placeholder { color: rgba(253,240,226,.45); }
        .papel { font-size: .58rem; color: var(--ember); letter-spacing: .12em; text-transform: uppercase; }
    </style>
</head>
<body>
    <header class="topo">
        <div class="marca"><small>Administração</small>FERTWAYS</div>

        @auth('admin')
            {{-- A busca global: é o que se usa em 90% das vezes que alguém reclama de alguma coisa. --}}
            <form method="GET" action="{{ route('admin.jogadores') }}" class="busca">
                <input type="text" name="q" value="{{ request('q') }}"
                       placeholder="jogador, e-mail, colônia ou placa…">
                <button class="leve" style="color:var(--sand-light);border-color:rgba(255,255,255,.3)">Buscar</button>
            </form>

            <div style="display:flex;align-items:center;gap:12px">
                <div style="text-align:right">
                    <div style="font-weight:700">{{ auth('admin')->user()->name }}</div>
                    <div class="papel">{{ auth('admin')->user()->role }}</div>
                </div>
                <form method="POST" action="{{ route('admin.logout') }}" class="inline">
                    @csrf
                    <button class="leve" style="color:var(--sand-light);border-color:rgba(255,255,255,.3)">Sair</button>
                </form>
            </div>
        @endauth
    </header>

    @auth('admin')
        @php
            // O CRUD de admins é só do dono (D-61) — e o menu não oferece o que a rota vai recusar.
            /*
             * "Ministério" sozinho ficara ambíguo: há TRÊS no jogo — o das Reputações (§9), o do
             * Tesouro (dentro de Economia) e o dos Transportes (aba própria). A aba passa a chamar-se
             * **Reputações**, que é o nome que o GDD lhe dá (§9.1–9.4) e o mesmo que o colono vê na
             * tela dele: painel e jogo passam a chamar a mesma coisa pelo mesmo nome.
             *
             * Não é "Justiça": esse ministério não existe em Fertways.
             */
            $abas = [
                'admin.dashboard' => 'Visão geral',
                'admin.metricas' => 'Métricas',
                'admin.mapa' => 'Mapa',
                'admin.jogadores' => 'Jogadores',
                'admin.ministerio' => 'Reputações',
                'admin.economia' => 'Economia',
                'admin.noticias' => 'Notícias',
                'admin.imagens' => 'Imagens',
                'admin.guerra' => 'Guerra',
                'admin.chat' => 'Chat',
                'admin.missoes' => 'Missões',
                'admin.transportes' => 'Transportes',
                'admin.federacoes' => 'Federações',
                'admin.auditoria' => 'Auditoria',
                'admin.operacao' => 'Operação',
                'admin.construcoes' => 'Gestão de Construções',
                'admin.endurance' => 'Endurance',
                'admin.feedback' => 'Bugs/Melhorias',
            ];

            if (auth('admin')->user()->ehDono()) {
                $abas['admin.admins'] = 'Admins';
            }
        @endphp

        <nav class="abas">
            @foreach ($abas as $rota => $rotulo)
                <a href="{{ route($rota) }}"
                   class="{{ request()->routeIs($rota) ? 'ativa' : '' }}"
                   data-aba="{{ $rota }}">{{ $rotulo }}</a>
            @endforeach
        </nav>
    @endauth

    <main>
        @if (session('ok'))<div class="flash ok">{{ session('ok') }}</div>@endif
        @if (session('erro'))<div class="flash erro">{{ session('erro') }}</div>@endif
        @if ($errors->any())<div class="flash erro">{{ $errors->first() }}</div>@endif

        @yield('content')
    </main>
</body>
</html>
