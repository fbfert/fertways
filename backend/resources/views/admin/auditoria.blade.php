@extends('admin.layout')

@php
    $quando = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format('d/m/Y H:i:s') : '—';
    $valor = function ($v) {
        if ($v === null) return '—';
        if (is_scalar($v)) return (string) $v;
        return json_encode($v, JSON_UNESCAPED_UNICODE);
    };
@endphp

@section('content')

    <h2 class="secao">Auditoria</h2>

    <div class="cartao">
        <p class="mut pequeno">
            <b>Tudo o que a administração faz passa por aqui.</b> O <code>ledger</code> audita a
            economia; até o D-61, nada auditava a administração — o operador era a única figura do
            sistema capaz de criar valor sem deixar história. Este log é <b>append-only</b>: nem o
            admin apaga uma linha dele, e o modelo recusa <i>update</i> e <i>delete</i> no código,
            além de a tabela não ter <code>updated_at</code>.
        </p>

        <form method="GET" action="{{ route('admin.auditoria') }}" class="linha-form">
            <div>
                <label>Ação</label>
                <select name="acao">
                    <option value="">todas</option>
                    @foreach ($acoes as $a)
                        <option value="{{ $a }}" @selected($filtro['acao'] === $a)>{{ $a }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label>Admin</label>
                <select name="admin">
                    <option value="">todos</option>
                    @foreach ($admins as $ad)
                        <option value="{{ $ad->id }}" @selected($filtro['admin'] == $ad->id)>{{ $ad->name }} ({{ $ad->email }})</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label>Alvo (ex.: user:12, colony:4)</label>
                <input type="text" name="alvo" value="{{ $filtro['alvo'] }}">
            </div>
            <div style="flex:0"><button>Filtrar</button></div>
            <div style="flex:0"><a href="{{ route('admin.auditoria') }}"><button type="button" class="leve">Limpar</button></a></div>
        </form>
    </div>

    <div class="cartao">
        <table data-auditoria>
            <tr>
                <th>Quando</th><th>Quem</th><th>Ação</th><th>Alvo</th>
                <th>O quê</th><th>Mudou</th><th>De onde</th>
            </tr>
            @forelse ($entradas as $e)
                <tr @if (str_starts_with($e->acao, 'login.falhou')) style="background:rgba(138,47,8,.12)" @endif>
                    <td class="mut pequeno" style="white-space:nowrap">{{ $quando($e->created_at) }}</td>
                    <td class="pequeno">
                        {{ $e->admin_email ?? '—' }}
                        @if ($e->papel)<div class="mut" style="font-size:.58rem">{{ $e->papel }}</div>@endif
                    </td>
                    <td><span class="pilula alerta">{{ $e->acao }}</span></td>
                    <td class="pequeno">{{ $e->alvo ?? '—' }}</td>
                    <td class="pequeno">{{ $e->resumo }}</td>
                    <td class="pequeno">
                        @php $mudancas = $e->mudancas(); @endphp
                        @forelse ($mudancas as $campo => $m)
                            <div style="white-space:nowrap">
                                <b>{{ $campo }}</b>:
                                <span class="mut">{{ \Illuminate\Support\Str::limit($valor($m['de']), 40) }}</span>
                                →
                                {{ \Illuminate\Support\Str::limit($valor($m['para']), 40) }}
                            </div>
                        @empty
                            <span class="mut">—</span>
                        @endforelse
                    </td>
                    <td class="mut" style="font-size:.6rem">
                        {{ $e->ip ?? '—' }}
                        <div>{{ \Illuminate\Support\Str::limit($e->agente, 30) }}</div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="mut pequeno">Nada registrado com estes filtros.</td></tr>
            @endforelse
        </table>

        <div style="margin-top:10px">{{ $entradas->links() }}</div>
    </div>

@endsection
