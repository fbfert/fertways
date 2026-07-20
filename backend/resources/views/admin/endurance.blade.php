@extends("admin.layout")

@section("content")

    <p class="mut pequeno">
        A Loja de Peças da Endurance (§05, D-132/D-133): 8 seções do casco × 4 camadas, cada uma
        liberada por um marco e comprada com Fert$. O GDD nunca publica preço, marco nem efeito —
        os valores abaixo são arbitragem, editável aqui em vez de escondida em código. O "efeito"
        de cada peça é o desconto de tributo que ela soma (com teto agregado de 30% por colônia).
    </p>

    <p class="mut pequeno">
        A imagem de cada peça <b>não se edita aqui</b>: é por seção, não por camada — as 4 camadas
        de uma mesma seção compartilham a mesma arte. Para trocar, use
        <a href="{{ route('admin.imagens', ['cat' => 'destrocos-da-endurance']) }}">Imagens → Destroços da Endurance</a>.
    </p>

    <div class="cartao" style="margin-top:12px">
        <form method="POST" action="{{ route('admin.endurance.parametros') }}">
            @csrf
            @foreach ($grupos as $secaoChave => $pecas)
                @php $img = $imagemPorSecao[$secaoChave] ?? null; @endphp
                <div style="display:flex;align-items:center;gap:10px;margin:18px 0 6px">
                    @if ($img)
                        <img src="{{ $img->url() }}" alt=""
                             style="width:40px;height:40px;object-fit:contain">
                    @endif
                    <h3 style="margin:0">{{ $pecas->first()->secao_nome }}</h3>
                </div>

                <div style="overflow-x:auto">
                    <table>
                        <tr>
                            <th>Camada</th>
                            <th>Nome</th>
                            <th class="num">Marco mínimo</th>
                            <th class="num">Preço (Fert$)</th>
                            <th class="num">Desconto de tributo (bps)</th>
                        </tr>
                        @foreach ($pecas as $p)
                            <tr data-linha-peca="{{ $p->peca_key }}">
                                <td class="pequeno">{{ $p->camada }}</td>
                                <td class="pequeno">{{ $p->nome }}</td>
                                <td class="num">
                                    <input type="number" min="1" max="100"
                                           name="marco[{{ $p->peca_key }}]"
                                           value="{{ $p->marco_minimo }}"
                                           style="width:70px;text-align:right">
                                </td>
                                <td class="num">
                                    <input type="number" min="0.000001" step="0.000001"
                                           name="preco[{{ $p->peca_key }}]"
                                           value="{{ $p->preco_micro / 1000000 }}"
                                           style="width:100px;text-align:right">
                                </td>
                                <td class="num">
                                    <input type="number" min="0" max="10000"
                                           name="desconto[{{ $p->peca_key }}]"
                                           value="{{ $p->desconto_tributo_bps }}"
                                           style="width:90px;text-align:right">
                                    <span class="mut pequeno">= {{ number_format($p->desconto_tributo_bps / 100, 1) }}%</span>
                                </td>
                            </tr>
                        @endforeach
                    </table>
                </div>
            @endforeach

            <div style="margin-top:14px"><button>Salvar a Loja de Peças</button></div>
        </form>
    </div>

@endsection
