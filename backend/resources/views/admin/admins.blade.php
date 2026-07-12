@extends('admin.layout')

@php
    $quando = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format('d/m/Y H:i') : '—';
    $eu = auth('admin')->id();
    $donosAtivos = $admins->where('role', 'dono')->whereNull('desativado_em')->count();
@endphp

@section('content')

    <h2 class="secao">Administradores</h2>

    <div class="cartao">
        <p class="mut pequeno">
            <b>Dois papéis (D-61), e a linha entre eles é o que altera o estado do jogo de forma
            difícil de desfazer.</b>
        </p>
        <table style="max-width:640px">
            <tr><th>Papel</th><th>Pode</th></tr>
            <tr>
                <td><b>dono</b></td>
                <td class="pequeno">Tudo. Gere admins e <b>realoca colônias</b>.</td>
            </tr>
            <tr>
                <td><b>operador</b></td>
                <td class="pequeno">
                    Julga casos, publica notícias, distribui o Tesouro; nos jogadores, <b>vê, suspende
                    e corrige estado</b>. Não gere admins e não realoca.
                </td>
            </tr>
        </table>
        <p class="mut pequeno" style="margin-top:8px">
            Duas travas: <b>ninguém desativa a si mesmo</b>, e <b>não se desativa o último dono</b> —
            o painel ficaria inacessível para sempre, e a única saída seria o <code>artisan</code>.
            Hoje há <b>{{ $donosAtivos }}</b> dono(s) ativo(s).
        </p>
    </div>

    <div class="cartao">
        <table data-admins>
            <tr><th>#</th><th>Nome</th><th>E-mail</th><th>Papel</th><th>Situação</th><th></th></tr>
            @foreach ($admins as $a)
                <tr data-admin="{{ $a->id }}" @if (! $a->ativo()) style="opacity:.55" @endif>
                    <td>{{ $a->id }}</td>
                    <td>
                        {{ $a->name }}
                        @if ($a->id === $eu)<span class="pilula alerta">você</span>@endif
                    </td>
                    <td class="mut pequeno">{{ $a->email }}</td>
                    <td><b>{{ $a->role }}</b></td>
                    <td class="pequeno">
                        {{ $a->ativo() ? 'ativo' : 'desativado em '.$quando($a->desativado_em) }}
                    </td>
                    <td style="white-space:nowrap">
                        @if ($a->ativo())
                            <form method="POST" action="{{ route('admin.admin.desativar', $a) }}" class="inline">
                                @csrf
                                <button class="perigo" data-desativar="{{ $a->id }}"
                                        @disabled($a->id === $eu || ($a->ehDono() && $donosAtivos <= 1))>
                                    Desativar
                                </button>
                            </form>
                        @else
                            <form method="POST" action="{{ route('admin.admin.reativar', $a) }}" class="inline">
                                @csrf
                                <button class="leve">Reativar</button>
                            </form>
                        @endif
                    </td>
                </tr>
                <tr>
                    <td colspan="6" style="border-bottom:1px solid rgba(180,69,11,.2)">
                        <form method="POST" action="{{ route('admin.admin.editar', $a) }}" class="linha-form" style="margin:0 0 8px">
                            @csrf
                            <div><label>Nome</label><input type="text" name="name" value="{{ $a->name }}" required></div>
                            <div><label>E-mail</label><input type="email" name="email" value="{{ $a->email }}" required></div>
                            <div style="flex:0"><label>Papel</label>
                                <select name="role">
                                    @foreach ($papeis as $p)
                                        <option value="{{ $p }}" @selected($a->role === $p)>{{ $p }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div><label>Nova senha (opcional, mín. 10)</label><input type="password" name="password"></div>
                            <div style="flex:0"><button class="leve">Salvar</button></div>
                        </form>
                    </td>
                </tr>
            @endforeach
        </table>
    </div>

    <h2 class="secao">Novo administrador</h2>
    <div class="cartao">
        <form method="POST" action="{{ route('admin.admin.criar') }}" class="linha-form">
            @csrf
            <div><label>Nome</label><input type="text" name="name" required></div>
            <div><label>E-mail</label><input type="email" name="email" required></div>
            <div><label>Senha (mín. 10)</label><input type="password" name="password" required></div>
            <div style="flex:0"><label>Papel</label>
                <select name="role">
                    @foreach ($papeis as $p)
                        <option value="{{ $p }}" @selected($p === 'operador')>{{ $p }}</option>
                    @endforeach
                </select>
            </div>
            <div style="flex:0"><button data-criar-admin>Criar</button></div>
        </form>
        <p class="mut pequeno">
            Não há auto-registro: um admin só nasce aqui ou pelo <code>artisan fertways:admin --criar</code>.
        </p>
    </div>

@endsection
