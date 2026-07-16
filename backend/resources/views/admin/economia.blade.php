@extends("admin.layout")

@php
    $fert = fn ($micro) => number_format(((int) $micro) / 1000000, 2, ",", ".");
    $quando = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format("d/m H:i") : "—";
    $abas = [
        "financas" => "Finanças",
        "tesouro" => "Ministério do Tesouro",
        "enviar" => "Enviar Recursos",
        "mercado" => "Mercado",
        "ofertas_globais" => "Ofertas Globais",
        "extrato_governo" => "Extrato do Governo",
        "extrato_colonos" => "Extrato Colonos",
    ];
    $rotuloLedger = [
        'credito' => 'Crédito', 'debito' => 'Débito', 'distribuicao' => 'Distribuição',
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

    {{-- ── Ofertas Globais: o livro do Mercado Central inteiro ── --}}
    @if ($aba === "ofertas_globais")
        <h2 class="secao">Ofertas Globais — o livro do Mercado Central</h2>
        <div class="cartao">
            <p class="mut pequeno">Toda ordem de todo colono, inclusive as do Governo (colônia em branco).</p>
            <form method="GET" action="{{ route('admin.economia', ['aba' => 'ofertas_globais']) }}" class="linha-form" style="margin-top:8px">
                <input type="hidden" name="aba" value="ofertas_globais">
                <div><label>Buscar</label>
                    <input type="text" name="q" value="{{ $filtrosOfertas['q'] }}" placeholder="colônia, recurso, ou 'governo'">
                </div>
                <div style="flex:0"><label>Lado</label>
                    <select name="side">
                        <option value="">todos</option>
                        <option value="buy" @selected($filtrosOfertas['side'] === 'buy')>compra</option>
                        <option value="sell" @selected($filtrosOfertas['side'] === 'sell')>venda</option>
                    </select>
                </div>
                <div style="flex:0"><label>Status</label>
                    <select name="status">
                        <option value="">todos</option>
                        <option value="aberta" @selected($filtrosOfertas['status'] === 'aberta')>aberta</option>
                        <option value="parcial" @selected($filtrosOfertas['status'] === 'parcial')>parcial</option>
                        <option value="executada" @selected($filtrosOfertas['status'] === 'executada')>executada</option>
                        <option value="cancelada" @selected($filtrosOfertas['status'] === 'cancelada')>cancelada</option>
                    </select>
                </div>
                <div style="flex:0"><label>Recurso</label>
                    <select name="recurso">
                        <option value="">todos</option>
                        @foreach ($recursos as $r)<option value="{{ $r->code }}" @selected($filtrosOfertas['recurso'] === $r->code)>{{ $r->nome }}</option>@endforeach
                    </select>
                </div>
                <div style="flex:0"><label>&nbsp;</label><button>Filtrar</button></div>
                <div style="flex:0"><label>&nbsp;</label><a class="leve" href="{{ route('admin.economia', ['aba' => 'ofertas_globais']) }}">Limpar</a></div>
            </form>
            <table style="margin-top:10px">
                <tr><th>#</th><th>Colônia</th><th>Lado</th><th>Recurso</th><th class="num">Preço/un.</th><th class="num">Restante</th><th>Status</th><th>Criada</th></tr>
                @forelse ($ofertasGlobais as $o)
                    <tr data-oferta="{{ $o->id }}">
                        <td class="mut pequeno">{{ $o->id }}</td>
                        <td>{{ $o->colony?->name ?? 'Governo' }}</td>
                        <td class="pequeno">{{ $o->side === 'buy' ? 'compra' : 'venda' }}</td>
                        <td class="pequeno">{{ $o->resource_type }}</td>
                        <td class="num">{{ $fert($o->price_micro) }}</td>
                        <td class="num">{{ number_format($o->qty, 0, ',', '.') }}</td>
                        <td class="pequeno">{{ $o->status }}</td>
                        <td class="mut pequeno">{{ $quando($o->created_at) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="mut pequeno">Nenhuma oferta com esses filtros.</td></tr>
                @endforelse
            </table>
            <div style="margin-top:10px">{{ $ofertasGlobais->links() }}</div>
        </div>
    @endif

    {{-- ── Extrato do Governo: o treasury_ledger ── --}}
    @if ($aba === "extrato_governo")
        <h2 class="secao">Extrato do Governo</h2>
        <div class="cartao">
            <p class="mut pequeno">
                Todo movimento real do caixa do Tesouro — crédito (tributo, venda, tarifa), débito
                (gasto, oferta no Mercado) e distribuição (a aba Enviar Recursos). Positivo entra,
                negativo sai.
            </p>
            <form method="GET" action="{{ route('admin.economia', ['aba' => 'extrato_governo']) }}" class="linha-form" style="margin-top:8px">
                <input type="hidden" name="aba" value="extrato_governo">
                <div><label>Buscar (ref)</label><input type="text" name="q" value="{{ $filtrosGoverno['q'] }}"></div>
                <div style="flex:0"><label>Tipo</label>
                    <select name="tipo">
                        <option value="">todos</option>
                        @foreach ($tiposGoverno as $t)<option value="{{ $t }}" @selected($filtrosGoverno['tipo'] === $t)>{{ $rotuloLedger[$t] ?? $t }}</option>@endforeach
                    </select>
                </div>
                <div style="flex:0"><label>Recurso</label>
                    <select name="recurso">
                        <option value="">todos</option>
                        <option value="fert" @selected($filtrosGoverno['recurso'] === 'fert')>Fert$</option>
                        @foreach ($recursos as $r)<option value="{{ $r->code }}" @selected($filtrosGoverno['recurso'] === $r->code)>{{ $r->nome }}</option>@endforeach
                    </select>
                </div>
                <div style="flex:0"><label>De</label><input type="date" name="de" value="{{ $filtrosGoverno['de'] }}"></div>
                <div style="flex:0"><label>Até</label><input type="date" name="ate" value="{{ $filtrosGoverno['ate'] }}"></div>
                <div style="flex:0"><label>&nbsp;</label><button>Filtrar</button></div>
                <div style="flex:0"><label>&nbsp;</label><a class="leve" href="{{ route('admin.economia', ['aba' => 'extrato_governo']) }}">Limpar</a></div>
            </form>
            <table style="margin-top:10px">
                <tr><th>Quando</th><th>Tipo</th><th>Recurso</th><th class="num">Valor</th><th>Ref</th></tr>
                @forelse ($extratoGoverno as $l)
                    <tr>
                        <td class="mut pequeno">{{ $quando($l->created_at) }}</td>
                        <td class="pequeno">{{ $rotuloLedger[$l->type] ?? $l->type }}</td>
                        <td class="pequeno">{{ $l->resource_type ?? 'Fert$' }}</td>
                        <td class="num">{{ number_format($l->amount, 0, ',', '.') }}</td>
                        <td class="mut pequeno">{{ $l->ref }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="mut pequeno">Nenhum lançamento com esses filtros.</td></tr>
                @endforelse
            </table>
            <div style="margin-top:10px">{{ $extratoGoverno->links() }}</div>
        </div>
    @endif

    {{-- ── Extrato Colonos: o ledger de todas as colônias ── --}}
    @if ($aba === "extrato_colonos")
        <h2 class="secao">Extrato Colonos</h2>
        <div class="cartao">
            <p class="mut pequeno">O ledger de todo jogador, junto — o mesmo que a ficha individual mostra, aqui buscável entre colônias.</p>
            <form method="GET" action="{{ route('admin.economia', ['aba' => 'extrato_colonos']) }}" class="linha-form" style="margin-top:8px">
                <input type="hidden" name="aba" value="extrato_colonos">
                <div><label>Buscar</label><input type="text" name="q" value="{{ $filtrosColonos['q'] }}" placeholder="colônia, ref"></div>
                <div style="flex:0"><label>Tipo</label>
                    <select name="tipo">
                        <option value="">todos</option>
                        @foreach ($tiposColonos as $t)<option value="{{ $t }}" @selected($filtrosColonos['tipo'] === $t)>{{ $t }}</option>@endforeach
                    </select>
                </div>
                <div style="flex:0"><label>Recurso</label>
                    <select name="recurso">
                        <option value="">todos</option>
                        <option value="fert" @selected($filtrosColonos['recurso'] === 'fert')>Fert$</option>
                        @foreach ($recursos as $r)<option value="{{ $r->code }}" @selected($filtrosColonos['recurso'] === $r->code)>{{ $r->nome }}</option>@endforeach
                    </select>
                </div>
                <div style="flex:0"><label>De</label><input type="date" name="de" value="{{ $filtrosColonos['de'] }}"></div>
                <div style="flex:0"><label>Até</label><input type="date" name="ate" value="{{ $filtrosColonos['ate'] }}"></div>
                <div style="flex:0"><label>&nbsp;</label><button>Filtrar</button></div>
                <div style="flex:0"><label>&nbsp;</label><a class="leve" href="{{ route('admin.economia', ['aba' => 'extrato_colonos']) }}">Limpar</a></div>
            </form>
            <table style="margin-top:10px">
                <tr><th>Quando</th><th>Colônia</th><th>Tipo</th><th>Recurso</th><th class="num">Valor</th><th>Ref</th></tr>
                @forelse ($extratoColonos as $l)
                    <tr @if ($l->type === 'ajuste_admin') style="background:rgba(234,174,101,.25)" @endif>
                        <td class="mut pequeno">{{ $quando($l->created_at) }}</td>
                        <td class="pequeno">
                            @if ($l->colony)
                                <a href="{{ route('admin.jogador', $l->colony->user_id) }}">{{ $l->colony->name }}</a>
                            @else
                                —
                            @endif
                        </td>
                        <td class="pequeno">{{ $l->type }}</td>
                        <td class="pequeno">{{ $l->resource_type ?? 'Fert$' }}</td>
                        <td class="num">{{ number_format($l->amount, 0, ',', '.') }}</td>
                        <td class="mut pequeno">{{ $l->ref }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="mut pequeno">Nenhum lançamento com esses filtros.</td></tr>
                @endforelse
            </table>
            <div style="margin-top:10px">{{ $extratoColonos->links() }}</div>
        </div>
    @endif
@endsection
