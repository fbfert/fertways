@extends("admin.layout")

@php
    $fert = fn ($micro) => number_format(((int) $micro) / 1000000, 2, ",", ".");
    $quando = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format("d/m H:i") : "—";
@endphp

@section("content")
    {{-- ── Finanças ── --}}
    <h2 class="secao">Finanças — intervenções de preço</h2>
    <div class="cartao">
        <table>
            <tr><th>Recurso</th><th>Piso</th><th>Teto</th><th>Motivo</th><th>Expira</th><th></th></tr>
            @forelse ($intervencoes as $i)
                <tr>
                    <td>{{ $i->resource_type }}</td>
                    <td class="num">{{ $i->floor_micro !== null ? $fert($i->floor_micro) : '—' }}</td>
                    <td class="num">{{ $i->ceil_micro !== null ? $fert($i->ceil_micro) : '—' }}</td>
                    <td>{{ $i->reason }}</td>
                    <td>{{ $quando($i->expires_at) }}</td>
                    <td>
                        <form method="POST" action="{{ route('admin.intervencao.revogar') }}" class="inline">
                            @csrf<input type="hidden" name="resource_type" value="{{ $i->resource_type }}">
                            <button class="leve">Revogar</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="mut pequeno">Nenhuma intervenção vigente — o Mercado está livre.</td></tr>
            @endforelse
        </table>
        <form method="POST" action="{{ route('admin.intervencao') }}" class="linha-form">
            @csrf
            <div><label>Recurso</label>
                <select name="resource_type">
                    @foreach ($recursos as $r)<option value="{{ $r->code }}">{{ $r->nome }} ({{ $r->tax_class }})</option>@endforeach
                </select>
            </div>
            <div><label>Piso (Fert$)</label><input type="number" step="0.0001" min="0" name="piso"></div>
            <div><label>Teto (Fert$)</label><input type="number" step="0.0001" min="0" name="teto"></div>
            <div><label>Motivo</label><input type="text" name="motivo" required></div>
            <div style="flex:0"><label>Dias</label><input type="number" min="1" name="dias" value="7" style="width:70px"></div>
            <div style="flex:0"><button>Declarar</button></div>
        </form>
    </div>

    {{-- ── Ministério do Tesouro: o CAIXA ── --}}
    <h2 class="secao">Ministério do Tesouro</h2>
    <div class="cartao">
        <p class="mut pequeno">A reserva do governo — o tributo do comércio entra aqui, e é daqui que sai o Nióbio que o Quartel exige.</p>
        <div style="max-height:220px;overflow:auto;margin-top:8px">
            <table>
                <tr><th>Recurso</th><th class="num">Saldo</th></tr>
                <tr><td><b>Fert$</b></td><td class="num" data-tesouro-fert>{{ $fert($tesouroFert) }}</td></tr>
                @foreach ($tesouro as $h)
                    <tr><td>{{ $h->nome }}</td><td class="num">{{ number_format($h->amount, 0, ",", ".") }}</td></tr>
                @endforeach
            </table>
        </div>
    </div>

    {{-- ── Enviar recursos: card PRÓPRIO ──
         Era um formulário solto no rodapé do caixa, sem título. Quem chegava à tela via uma tabela de
         saldos e, embaixo, três selects sem explicação nenhuma do que aconteceria ao clicar em
         "Enviar". É um ato que CRIA valor na colônia do outro, saindo do caixa: merece nome. --}}
    <h2 class="secao">Enviar recursos</h2>
    <div class="cartao">
        <p class="mut pequeno">
            Tira do caixa do Tesouro e entrega a uma colônia (§2.1). Não passa por veículo e não paga
            tributo — é emissão do governo, e fica no extrato do colono.
        </p>
        <form method="POST" action="{{ route("admin.tesouro.distribuir") }}" class="linha-form" style="margin-top:8px">
            @csrf
            <div><label>Colônia</label>
                <select name="colony_id">
                    @foreach ($colonias as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach
                </select>
            </div>
            <div><label>Recurso</label>
                <select name="recurso">
                    <option value="{{ $FERT }}">Fert$</option>
                    @foreach ($recursos as $r)<option value="{{ $r->code }}">{{ $r->nome }}</option>@endforeach
                </select>
            </div>
            <div style="flex:0"><label>Quantidade</label><input type="number" step="0.0001" min="0.0001" name="quantidade" required></div>
            <div style="flex:0"><label>&nbsp;</label><button>Enviar</button></div>
        </form>
    </div>
@endsection
