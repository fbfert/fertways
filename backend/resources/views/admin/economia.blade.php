@extends("admin.layout")

@php
    $fert = fn ($micro) => number_format(((int) $micro) / 1000000, 2, ",", ".");
    $quando = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format("d/m H:i") : "—";
    $abas = [
        "financas" => "Finanças",
        "tesouro" => "Ministério do Tesouro",
        "enviar" => "Enviar Recursos",
        "mercado" => "Mercado",
    ];
@endphp

@section("content")

    <nav class="abas" style="background:transparent;padding:0;margin-bottom:16px">
        @foreach ($abas as $slug => $rotulo)
            <a href="{{ route('admin.economia', ['aba' => $slug]) }}"
               data-aba-economia="{{ $slug }}"
               style="color:{{ $aba === $slug ? 'var(--rust)' : 'var(--ink-soft)' }};
                      background:{{ $aba === $slug ? 'var(--sand-light)' : 'transparent' }};
                      border:1px solid rgba(180,69,11,.2)">
                {{ $rotulo }}
            </a>
        @endforeach
    </nav>

    {{-- ── Finanças ── --}}
    @if ($aba === "financas")
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
    @endif

    {{-- ── Ministério do Tesouro: o CAIXA ── --}}
    @if ($aba === "tesouro")
        <h2 class="secao">Ministério do Tesouro</h2>
        <div class="cartao">
            <p class="mut pequeno">A reserva do governo — o tributo do comércio entra aqui, e é daqui que sai o Nióbio que o Quartel exige, e o que o Governo anuncia no Mercado (aba Mercado).</p>
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
    @endif

    {{-- ── Enviar recursos ── --}}
    @if ($aba === "enviar")
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
    @endif

    {{-- ── Mercado: o Governo vende no Mercado Central (D-87) ── --}}
    @if ($aba === "mercado")
        <h2 class="secao">Mercado — ofertas do Governo</h2>
        <div class="cartao">
            <p class="mut pequeno">
                Uma lista para estar sempre à venda no Mercado Central, ao lado das ofertas dos
                colonos. <b>A quantidade aqui é quanto deve estar disponível AGORA</b> — não soma
                ao que já está anunciado: subir o número reserva mais do Tesouro, descer devolve a
                diferença, zerar cancela a oferta daquele recurso. O que vender cai daqui igual a
                qualquer venda: anuncie 100 e alguém compre 5, e o número aqui vira 95 sozinho.
            </p>
            <form method="POST" action="{{ route('admin.mercado.governo') }}" style="margin-top:12px">
                @csrf
                <table>
                    <tr><th>Recurso</th><th class="num">No Tesouro</th><th class="num">Preço Base (Fert$)</th><th class="num">À venda agora</th><th class="num">Preço/un. (Fert$)</th></tr>
                    @foreach ($recursos as $r)
                        @php
                            $oferta = $ofertasDoGoverno[$r->code] ?? null;
                            $noTesouro = (int) ($tesouro->firstWhere('code', $r->code)->amount ?? 0);
                        @endphp
                        <tr data-linha-recurso="{{ $r->code }}">
                            <td>{{ $r->nome }}</td>
                            <td class="num mut">{{ number_format($noTesouro, 0, ',', '.') }}</td>
                            <td class="num mut">{{ number_format(((int) $r->preco_base_micro) / 1000000, 4, ',', '.') }}</td>
                            <td class="num">
                                <input type="number" min="0" name="qtd[{{ $r->code }}]"
                                       value="{{ $oferta->qty ?? 0 }}" style="width:100px;text-align:right">
                            </td>
                            <td class="num">
                                <input type="number" step="0.0001" min="0" name="preco[{{ $r->code }}]"
                                       value="{{ $oferta ? number_format($oferta->price_micro / 1000000, 4, '.', '') : '' }}"
                                       style="width:110px;text-align:right">
                            </td>
                        </tr>
                    @endforeach
                </table>
                <div style="margin-top:10px"><button>Salvar</button></div>
            </form>
        </div>
    @endif
@endsection
