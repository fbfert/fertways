<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
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
    </style>
</head>
<body>
    <header class="topo">
        <div class="marca"><small>Administração</small>FERTWAYS</div>
        @auth('admin')
            <form method="POST" action="{{ route('admin.logout') }}" class="inline">
                @csrf
                <button class="leve" style="color:var(--sand-light);border-color:rgba(255,255,255,.3)">Sair</button>
            </form>
        @endauth
    </header>

    <main>
        @if (session('ok'))<div class="flash ok">{{ session('ok') }}</div>@endif
        @if (session('erro'))<div class="flash erro">{{ session('erro') }}</div>@endif
        @if ($errors->any())<div class="flash erro">{{ $errors->first() }}</div>@endif

        @yield('content')
    </main>
</body>
</html>
