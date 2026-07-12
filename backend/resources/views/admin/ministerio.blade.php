@extends("admin.layout")

@php
    $fert = fn ($micro) => number_format(((int) $micro) / 1000000, 2, ",", ".");
    $quando = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format("d/m H:i") : "—";
@endphp

@section("content")
    {{-- ── Ministério ── --}}
    <h2 class="secao">Ministério — casos com a equipe</h2>
    <div class="cartao">
        @forelse ($filaEquipe as $r)
            <div style="border-bottom:1px solid rgba(180,69,11,.12);padding:8px 0">
                <div>
                    <b>#{{ $r->id }}</b> — {{ $r->violation }}
                    @if ($r->grave)<span class="pilula alerta">grave</span>@endif
                    <span class="pilula" style="background:var(--sand)">{{ $r->status }}</span>
                </div>
                <div class="mut pequeno">{{ $r->reporter?->name }} → {{ $r->accused?->name }} · pena tabelada: {{ \App\Domain\Ministry\PunicaoSpecs::violacao($r->violation)['indice'] ?? '—' }}</div>
                <div class="linha-form">
                    @if ($r->status === 'na_equipe')
                        <form method="POST" action="{{ route('admin.julgar', $r) }}" class="inline">@csrf
                            <input type="hidden" name="procedente" value="1"><button>Procedente</button></form>
                        <form method="POST" action="{{ route('admin.julgar', $r) }}" class="inline">@csrf
                            <input type="hidden" name="procedente" value="0"><button class="leve">Improcedente</button></form>
                    @elseif ($r->status === 'apelado')
                        <form method="POST" action="{{ route('admin.apelacao', $r) }}" class="inline">@csrf
                            <input type="hidden" name="decisao" value="manter"><button>Manter</button></form>
                        <form method="POST" action="{{ route('admin.apelacao', $r) }}" class="inline"
                              onsubmit="return confirm('Reverter estorna a pena e conta uma reversão ao conciliador. Confirmar?')">@csrf
                            <input type="hidden" name="decisao" value="reverter"><button class="perigo">Reverter</button></form>
                    @endif
                </div>
            </div>
        @empty
            <p class="mut pequeno">Nenhum caso aguardando a equipe.</p>
        @endforelse
    </div>

    @if ($atribuidos->isNotEmpty() || $emApelacao->isNotEmpty())
    <div class="cartao">
        <table>
            <tr><th>Caso</th><th>Situação</th><th>Prazo</th><th>Conciliador</th></tr>
            @foreach ($atribuidos as $r)
                <tr><td>#{{ $r->id }} {{ $r->violation }}</td><td>atribuído</td><td>{{ $quando($r->deadline_at) }}</td><td>{{ $r->conciliator?->nickname ?? '—' }}</td></tr>
            @endforeach
            @foreach ($emApelacao as $r)
                <tr><td>#{{ $r->id }} {{ $r->violation }}</td><td>decidido (janela de apelação)</td><td>{{ $quando($r->appeal_until) }}</td><td>—</td></tr>
            @endforeach
        </table>
    </div>
    @endif

    {{-- ── Conciliadores ── --}}
    <h2 class="secao">Conciliadores</h2>
    <div class="cartao">
        <table>
            <tr><th>Colono</th><th>Reversões</th><th>Situação</th><th>Ações</th></tr>
            @forelse ($conciliadores as $u)
                <tr>
                    <td>{{ $u->nickname }}</td>
                    <td>{{ $u->reversoes }}/{{ \App\Domain\Ministry\PunicaoSpecs::LIMITE_REVERSOES }}</td>
                    <td>{{ $u->conciliador_suspenso_em ? 'suspenso' : 'ativo' }}</td>
                    <td>
                        @foreach (['reintegrar' => 'leve', 'suspender' => 'leve', 'demitir' => 'perigo'] as $acao => $cls)
                            <form method="POST" action="{{ route('admin.conciliador.gerir', $u) }}" class="inline"
                                  @if ($acao === 'demitir') onsubmit="return confirm('Demitir {{ $u->nickname }}?')" @endif>
                                @csrf<input type="hidden" name="acao" value="{{ $acao }}">
                                <button class="{{ $cls }}">{{ ucfirst($acao) }}</button>
                            </form>
                        @endforeach
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" class="mut pequeno">Nenhum conciliador. Todo caso sobe à equipe.</td></tr>
            @endforelse
        </table>
        <form method="POST" action="{{ route('admin.conciliador.nomear') }}" class="linha-form">
            @csrf
            <div><label>Nomear (nickname)</label><input type="text" name="nickname" required></div>
            <div style="flex:0"><button>Nomear</button></div>
        </form>
    </div>

@endsection
