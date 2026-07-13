@extends('admin.layout')

@php
    $fert = fn ($micro) => number_format(((int) $micro) / 1000000, 2, ',', '.');
@endphp

@section('content')

    <h2 class="secao">Jogadores</h2>

    <div class="cartao">
        {{-- A busca do topo cai aqui. Repetimos o campo para quem chegar pela URL. --}}
        <form method="GET" action="{{ route('admin.jogadores') }}" class="linha-form">
            <div>
                <label>Buscar por nome, nickname, e-mail, colônia ou placa</label>
                <input type="text" name="q" value="{{ $q }}" placeholder="ex.: publico, FW-00007-F, Nova Aurora">
            </div>
            <div style="flex:0"><button>Buscar</button></div>
            @if ($q !== '')
                <div style="flex:0"><a href="{{ route('admin.jogadores') }}"><button type="button" class="leve">Limpar</button></a></div>
            @endif
        </form>

        @if ($q !== '')
            <p class="mut pequeno">{{ $jogadores->total() }} resultado(s) para “{{ $q }}”.</p>
        @endif

        <table style="margin-top:8px">
            <tr>
                <th>#</th><th>Nickname</th><th>E-mail</th><th>Colônia</th>
                <th class="num">Marco</th>
                <th class="num">Fert$</th><th>Situação</th><th></th>
            </tr>
            @forelse ($jogadores as $u)
                @php $sus = $u->suspenso_em && (!$u->suspenso_ate || $u->suspenso_ate->isFuture()); @endphp
                <tr data-jogador="{{ $u->id }}">
                    <td>{{ $u->id }}</td>
                    <td><b>{{ $u->nickname }}</b><div class="mut pequeno">{{ $u->name }}</div></td>
                    <td class="mut pequeno">{{ $u->email }}</td>
                    <td>
                        {{ $u->colony?->name ?? '—' }}
                        @if ($u->colony)
                            <div class="mut pequeno">({{ $u->colony->x }}, {{ $u->colony->y }})</div>
                        @endif
                    </td>
                    <td class="num">
                        @if ($u->colony)
                            @php $m = \App\Domain\Marco\Curva::marco((int) $u->colony->xp); @endphp
                            <b>{{ $m }}</b>
                            <div class="mut pequeno">{{ \App\Domain\Marco\Curva::titulo($m) }}</div>
                        @else
                            —
                        @endif
                    </td>
                    <td class="num">{{ $u->colony ? $fert($u->colony->fert_micro) : '—' }}</td>
                    <td>
                        @if ($sus)
                            <span class="pilula" style="background:#8a2f08;color:#fff">suspenso</span>
                        @elseif ($u->conciliador_desde)
                            <span class="pilula alerta">conciliador</span>
                        @else
                            <span class="mut pequeno">ativo</span>
                        @endif
                    </td>
                    <td><a href="{{ route('admin.jogador', $u) }}"><button type="button" class="leve">Ficha</button></a></td>
                </tr>
            @empty
                <tr><td colspan="7" class="mut pequeno">Nenhum jogador encontrado.</td></tr>
            @endforelse
        </table>

        <div style="margin-top:10px">{{ $jogadores->links() }}</div>
    </div>

@endsection
