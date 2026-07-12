@extends("admin.layout")

@section("content")

    {{-- ─────────────────────────────────────────────── as categorias --}}
    <h2 class="secao">Imagens das construções</h2>
    <div class="cartao">
        <p class="mut pequeno">
            A arte de cada construção, veículo e unidade. <b>Quem não tem imagem continua sendo um
            hexágono colorido</b> — nada quebra por falta de arte, e você pode ir preenchendo aos poucos.
        </p>
        <p class="mut pequeno" style="margin-top:6px">
            <b>{{ $totalVinculavel - $semArte }}</b> de <b>{{ $totalVinculavel }}</b> coisas do jogo já
            têm imagem. Faltam <b>{{ $semArte }}</b>.
        </p>

        <nav class="abas" style="background:transparent;padding:0;margin-top:10px">
            @foreach ($categorias as $slug => $rotulo)
                <a href="{{ route('admin.imagens', ['cat' => $slug]) }}"
                   data-cat="{{ $slug }}"
                   style="color:{{ $categoria === $slug ? 'var(--rust)' : 'var(--ink-soft)' }};
                          background:{{ $categoria === $slug ? 'var(--sand-light)' : 'transparent' }};
                          border:1px solid rgba(180,69,11,.2)">
                    {{ $rotulo }}
                    <span class="mut">({{ $contagem[$slug] ?? 0 }})</span>
                </a>
            @endforeach
        </nav>
    </div>

    {{-- ─────────────────────────────────────────────── a biblioteca --}}
    <h2 class="secao">Biblioteca — {{ $categorias[$categoria] }}</h2>
    <div class="cartao">
        <form method="POST" action="{{ route('admin.imagem.enviar') }}"
              enctype="multipart/form-data" class="linha-form">
            @csrf
            <input type="hidden" name="categoria" value="{{ $categoria }}">
            <div>
                <label>Enviar PNG (até 8 MB)</label>
                <input type="file" name="arquivo" accept="image/png" required>
            </div>
            <div style="flex:0"><label>&nbsp;</label><button>Enviar</button></div>
        </form>
        <p class="mut pequeno" style="margin-top:4px">
            Só PNG. O sprite precisa de <b>fundo transparente</b>, e JPEG não tem — viraria um quadrado
            branco em cima do hexágono.
        </p>

        @if ($imagens->isEmpty())
            <p class="mut pequeno" style="margin-top:12px">Nenhuma imagem nesta categoria ainda.</p>
        @else
            <div style="display:grid;gap:10px;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));margin-top:14px">
                @foreach ($imagens as $img)
                    @php $usos = $vinculos->filter(fn ($v) => $v->media_asset_id === $img->id); @endphp
                    <div style="border:1px solid rgba(180,69,11,.2);background:var(--sand);padding:8px;text-align:center"
                         data-imagem="{{ $img->id }}">
                        <img src="{{ $img->url() }}" alt="{{ $img->filename }}"
                             style="width:100%;height:auto;image-rendering:auto">
                        <div class="pequeno" style="word-break:break-all;margin-top:4px">{{ $img->filename }}</div>

                        <div class="pequeno mut" style="margin-top:2px">
                            @if ($usos->isEmpty())
                                não usada
                            @else
                                em uso: {{ $usos->count() }}
                            @endif
                        </div>

                        {{-- Apagar diz ANTES quais construções perdem a arte. Sem isso, alguém apagaria
                             uma imagem, três prédios voltariam ao hexágono, e ninguém relacionaria as
                             duas coisas semanas depois. --}}
                        <form method="POST" action="{{ route('admin.imagem.apagar', $img) }}" class="inline"
                              onsubmit="return confirm('{{ $usos->isEmpty()
                                  ? 'Apagar esta imagem? Ela não está em uso.'
                                  : 'Apagar. '.$usos->count().' construção(ões) voltam ao hexágono colorido. Confirmar?' }}')">
                            @csrf
                            <button class="leve perigo" style="margin-top:6px;font-size:.66rem">Apagar</button>
                        </form>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    {{-- ─────────────────────────────────────────────── os vínculos --}}
    @if ($grupo)
        <h2 class="secao">{{ $grupo['titulo'] }}</h2>
        <div class="cartao">
            <p class="mut pequeno">
                Escolha a arte de cada uma. <b>O seletor lista TODAS as imagens da biblioteca</b>, não
                só as desta categoria — se a melhor arte para a Oficina estiver na pasta das
                especializações, use-a; a categoria é arrumação, não trava.
            </p>
            <p class="mut pequeno" style="margin-top:4px">
                Escolher <b>«— sem imagem —»</b> devolve a construção ao hexágono colorido.
            </p>

            <table style="margin-top:10px">
                <tr><th style="width:70px"></th><th>Construção</th><th>Imagem</th></tr>
                @foreach ($grupo['itens'] as $chave => $nome)
                    @php $atual = $vinculos->get($chave); @endphp
                    <tr data-vinculo="{{ $chave }}">
                        <td>
                            @if ($atual?->asset)
                                <img src="{{ $atual->asset->url() }}" alt=""
                                     style="width:60px;height:60px;object-fit:contain">
                            @else
                                <div style="width:60px;height:60px;border:1px dashed rgba(180,69,11,.3);
                                            display:flex;align-items:center;justify-content:center"
                                     class="mut pequeno">hex</div>
                            @endif
                        </td>
                        <td>
                            <b>{{ $nome }}</b>
                            <div class="mut pequeno">{{ $chave }}</div>
                        </td>
                        <td>
                            <form method="POST" action="{{ route('admin.imagem.vincular') }}" class="linha-form"
                                  style="margin:0">
                                @csrf
                                <input type="hidden" name="entity_key" value="{{ $chave }}">
                                <div>
                                    <select name="media_asset_id" data-select="{{ $chave }}">
                                        <option value="">— sem imagem (hexágono) —</option>
                                        @foreach ($todasAsImagens as $cat => $lista)
                                            <optgroup label="{{ $categorias[$cat] ?? $cat }}">
                                                @foreach ($lista as $img)
                                                    <option value="{{ $img->id }}"
                                                        @selected($atual?->media_asset_id === $img->id)>
                                                        {{ $img->filename }}
                                                    </option>
                                                @endforeach
                                            </optgroup>
                                        @endforeach
                                    </select>
                                </div>
                                <div style="flex:0"><button class="leve">Aplicar</button></div>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </table>
        </div>
    @else
        <div class="cartao">
            <p class="mut pequeno">
                Esta categoria não veste nenhuma construção diretamente — a arte dela é usada pelas
                <b>áreas da Capital</b> (aba <i>Capital</i>) ou ainda não tem destino no jogo.
                As imagens ficam na biblioteca e podem ser escolhidas de qualquer aba.
            </p>
        </div>
    @endif

@endsection
