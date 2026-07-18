@extends("admin.layout")

@php
    $quando = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format("d/m H:i") : "—";
    $cargo = [
        'lider' => 'Líder', 'diplomata' => 'Diplomata', 'intendente' => 'Intendente', 'membro' => 'Membro',
    ];
@endphp

@section("content")
    <h2 class="secao">Federações</h2>
    <p class="mut pequeno">
        Sistema 100% jogador-a-jogador (§04/§07) — criar, convidar, cargos e o fundo são todos
        atos dos próprios colonos. O operador só observa, com uma alavanca de emergência.
    </p>

    <div class="cartao">
        <table>
            <tr><th>Nome</th><th class="num">Membros</th><th>Status</th><th></th></tr>
            @forelse ($federacoes as $f)
                <tr data-federacao="{{ $f->id }}">
                    <td>{{ $f->name }}</td>
                    <td class="num">{{ $f->membros_count }}</td>
                    <td>{{ $f->disbanded_at ? 'dissolvida em '.$quando($f->disbanded_at) : 'ativa' }}</td>
                    <td>
                        @if (! $f->disbanded_at)
                            <a class="leve" href="{{ route('admin.federacoes', ['ver' => $f->id]) }}">Ver</a>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" class="mut pequeno">Nenhuma federação fundada ainda.</td></tr>
            @endforelse
        </table>
    </div>

    @if ($federacao)
        <h2 class="secao">{{ $federacao->name }}</h2>

        <div class="cartao">
            <table>
                <tr><th>Colônia</th><th>Cargo</th></tr>
                @foreach ($membros as $m)
                    <tr>
                        <td>{{ $m->name }}</td>
                        <td>{{ $cargo[$m->federation_role] ?? $m->federation_role }}</td>
                    </tr>
                @endforeach
            </table>
        </div>

        <h3 style="margin-top:16px">Fundo</h3>
        <div class="cartao">
            <table>
                <tr><th>Recurso</th><th class="num">Saldo</th></tr>
                @forelse ($fundo as $h)
                    <tr><td>{{ $h->resource_type }}</td><td class="num">{{ number_format($h->amount, 0, ",", ".") }}</td></tr>
                @empty
                    <tr><td colspan="2" class="mut pequeno">Fundo vazio.</td></tr>
                @endforelse
            </table>
        </div>

        <h3 style="margin-top:16px">Extrato do fundo (últimos 50)</h3>
        <div class="cartao">
            <table>
                <tr><th>Quando</th><th>Tipo</th><th>Colônia</th><th>Recurso</th><th class="num">Quantidade</th></tr>
                @forelse ($ledgerFederacao as $l)
                    <tr>
                        <td class="mut pequeno">{{ $quando($l->created_at) }}</td>
                        <td class="pequeno">{{ $l->type }}</td>
                        <td class="pequeno">{{ $l->colony->name ?? '—' }}</td>
                        <td class="pequeno">{{ $l->resource_type }}</td>
                        <td class="num">{{ number_format($l->amount, 0, ",", ".") }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="mut pequeno">Nenhum movimento ainda.</td></tr>
                @endforelse
            </table>
        </div>

        <h3 style="margin-top:16px">Emergência</h3>
        <div class="cartao">
            <p class="mut pequeno">
                Dissolve a federação AGORA — desliga todos os membros e manda o saldo do fundo para
                o Tesouro, a mesma regra de quando o último membro sai por conta própria. Para nome
                ofensivo, disputa entre jogadores, ou Líder inativo com a federação travada.
            </p>
            <form method="POST" action="{{ route('admin.federacoes.dissolver', $federacao) }}" class="linha-form">
                @csrf
                <div style="flex:0"><label>Escreva DISSOLVER</label>
                    <input type="text" name="confirmacao" placeholder="DISSOLVER" required style="width:130px">
                </div>
                <div style="flex:0"><button class="perigo">Dissolver</button></div>
            </form>
        </div>
    @endif
@endsection
