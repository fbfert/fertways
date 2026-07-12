@extends("admin.layout")

@php
    $quando = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format("d/m/Y H:i") : "—";

    // O estado é o que o operador precisa ver antes de qualquer outra coisa: uma notícia no mural e
    // uma notícia inativa são a mesma linha de tabela, e confundi-las é publicar o que não vale mais.
    $cor = [
        'no mural' => 'color:#1f7a34',
        'oculta'   => 'color:#8a6d00',
        'inativa'  => 'color:#999',
        'agendada' => 'color:#2a5db0',
    ];
@endphp

@section("content")

    {{-- ─────────────────────────────────────────────── publicar --}}
    <h2 class="secao">Publicar comunicado</h2>
    <div class="cartao">
        <form method="POST" action="{{ route('admin.noticia') }}">
            @csrf
            <div class="linha-form">
                <div><label>Título</label><input type="text" name="titulo" maxlength="140" required></div>
                <div style="flex:0"><label>Autor</label><input type="text" name="autor" placeholder="Administração Pública"></div>
            </div>
            <div style="margin-top:8px">
                <label class="pequeno mut">Corpo</label>
                <textarea name="corpo" rows="3" required></textarea>
            </div>
            <div style="margin-top:8px"><button>Publicar comunicado</button></div>
        </form>
    </div>

    {{-- ─────────────────────────────────────────────── filtros --}}
    <h2 class="secao">O mural</h2>
    <div class="cartao">
        <form method="GET" action="{{ route('admin.noticias') }}" class="linha-form">
            <div>
                <label>Buscar no título e no corpo</label>
                <input type="text" name="q" value="{{ $filtros['q'] }}" placeholder="palavra do comunicado">
            </div>
            <div style="flex:0">
                <label>Estado</label>
                <select name="estado">
                    <option value="">todos</option>
                    @foreach (['mural' => 'no mural', 'agendada' => 'agendada', 'oculta' => 'oculta', 'inativa' => 'inativa'] as $v => $r)
                        <option value="{{ $v }}" @selected($filtros['estado'] === $v)>{{ $r }}</option>
                    @endforeach
                </select>
            </div>
            <div style="flex:0">
                <label>Tipo</label>
                <select name="kind">
                    <option value="">todos</option>
                    @foreach ($kinds as $k)
                        <option value="{{ $k }}" @selected($filtros['kind'] === $k)>{{ $k }}</option>
                    @endforeach
                </select>
            </div>
            <div style="flex:0"><label>De</label><input type="date" name="de" value="{{ $filtros['de'] }}"></div>
            <div style="flex:0"><label>Até</label><input type="date" name="ate" value="{{ $filtros['ate'] }}"></div>
            <div style="flex:0"><label>&nbsp;</label><button>Filtrar</button></div>
            <div style="flex:0"><label>&nbsp;</label><a class="leve" href="{{ route('admin.noticias') }}">Limpar</a></div>
        </form>
    </div>

    {{-- ─────────────────────────────────────────────── a lista --}}
    <div class="cartao">
        @forelse ($noticias as $n)
            <div style="border-bottom:1px solid rgba(180,69,11,.12);padding:10px 0">
                <div>
                    <b>{{ $n->title }}</b>
                    <span class="pequeno" style="{{ $cor[$n->estado()] ?? '' }}">· {{ $n->estado() }}</span>
                    <span class="mut pequeno">
                        — {{ $n->author }} · {{ $quando($n->published_at) }} · #{{ $n->id }}
                        @if ($n->updated_at)
                            {{-- Um comunicado público reescrito depois de lido: quem confere precisa
                                 saber que foi, e quando. A auditoria guarda o antes/depois. --}}
                            · <b>reescrito {{ $quando($n->updated_at) }}</b>
                        @endif
                    </span>
                </div>

                <p class="pequeno" style="margin:4px 0 8px">{{ $n->body }}</p>

                <div class="linha-form" style="gap:6px">
                    {{-- OCULTAR: administrativo e reversível. Sai do mural agora, volta quando quiser. --}}
                    <form method="POST" action="{{ route('admin.noticia.ocultar', $n) }}" class="inline">
                        @csrf
                        <button class="leve">{{ $n->oculta() ? 'Reexibir' : 'Ocultar' }}</button>
                    </form>

                    {{-- INATIVAR: fim de vida. A notícia envelheceu e não vale mais — mas fica no
                         histórico, marcada, em vez de ser destruída. --}}
                    <form method="POST" action="{{ route('admin.noticia.inativar', $n) }}" class="inline">
                        @csrf
                        <button class="leve">{{ $n->inativa() ? 'Reativar' : 'Inativar' }}</button>
                    </form>

                    <button class="leve" type="button"
                            onclick="document.getElementById('editar-{{ $n->id }}').style.display =
                                     document.getElementById('editar-{{ $n->id }}').style.display === 'none' ? 'block' : 'none'">
                        Editar
                    </button>

                    {{-- Apagar continua existindo e continua sendo a última saída: ela destrói o
                         registro de que a coisa foi dita. Ocultar e inativar existem justamente para
                         que quase nunca seja preciso. --}}
                    <form method="POST" action="{{ route('admin.noticia.remover', $n) }}" class="inline"
                          onsubmit="return confirm('Apagar destrói o registro de que este comunicado existiu. Ocultar ou inativar preservam. Apagar mesmo assim?')">
                        @csrf<button class="perigo leve">Apagar</button>
                    </form>
                </div>

                <form id="editar-{{ $n->id }}" method="POST" action="{{ route('admin.noticia.editar', $n) }}"
                      style="display:none;margin-top:8px;padding:8px;background:rgba(180,69,11,.04)">
                    @csrf
                    <div class="linha-form">
                        <div><label>Título</label><input type="text" name="titulo" value="{{ $n->title }}" maxlength="140" required></div>
                        <div style="flex:0"><label>Autor</label><input type="text" name="autor" value="{{ $n->author }}"></div>
                    </div>
                    <div style="margin-top:8px">
                        <label class="pequeno mut">Corpo</label>
                        <textarea name="corpo" rows="3" required>{{ $n->body }}</textarea>
                    </div>
                    <div style="margin-top:8px"><button>Salvar a reescrita</button></div>
                </form>
            </div>
        @empty
            <p class="mut pequeno">
                @if (array_filter($filtros))
                    Nenhuma notícia com esses filtros.
                @else
                    Mural vazio. Nenhum comunicado foi publicado ainda.
                @endif
            </p>
        @endforelse

        <div style="margin-top:10px">{{ $noticias->links() }}</div>
    </div>

@endsection
