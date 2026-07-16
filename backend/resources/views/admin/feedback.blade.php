@extends("admin.layout")

@php
    $quando = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format("d/m/Y H:i") : "—";
    $rotuloTipo = ['bug' => 'Bug', 'melhoria' => 'Melhoria', 'duvida' => 'Dúvida', 'outro' => 'Outro'];
@endphp

@section("content")

    {{-- ─────────────────────────────────────────────── filtros --}}
    <h2 class="secao">Bugs/Melhorias</h2>
    <div class="cartao">
        <p class="mut pequeno">
            O que os jogadores mandam pelo jogo — bugs, sugestões, dúvidas. Responder avisa o
            jogador pelo rádio (remetente "Capital"), e marca a mensagem como lida.
        </p>
        <form method="GET" action="{{ route('admin.feedback') }}" class="linha-form" style="margin-top:8px">
            <div>
                <label>Buscar</label>
                <input type="text" name="q" value="{{ $filtros['q'] }}" placeholder="assunto, mensagem, nick, colônia">
            </div>
            <div style="flex:0">
                <label>Estado</label>
                <select name="estado">
                    <option value="">todos</option>
                    <option value="nao_lida" @selected($filtros['estado'] === 'nao_lida')>não lida</option>
                    <option value="lida" @selected($filtros['estado'] === 'lida')>lida</option>
                    <option value="respondida" @selected($filtros['estado'] === 'respondida')>respondida</option>
                    <option value="pendente" @selected($filtros['estado'] === 'pendente')>pendente (não feita)</option>
                    <option value="feita" @selected($filtros['estado'] === 'feita')>feita</option>
                </select>
            </div>
            <div style="flex:0">
                <label>Tipo</label>
                <select name="tipo">
                    <option value="">todos</option>
                    @foreach ($tipos as $t)
                        <option value="{{ $t }}" @selected($filtros['tipo'] === $t)>{{ $rotuloTipo[$t] ?? $t }}</option>
                    @endforeach
                </select>
            </div>
            <div style="flex:0"><label>&nbsp;</label><button>Filtrar</button></div>
            <div style="flex:0"><label>&nbsp;</label><a class="leve" href="{{ route('admin.feedback') }}">Limpar</a></div>
        </form>
    </div>

    {{-- ─────────────────────────────────────────────── a lista --}}
    <div class="cartao">
        @forelse ($feedback as $f)
            <div style="border-bottom:1px solid rgba(180,69,11,.12);padding:10px 0{{ $f->lida() ? '' : ';background:rgba(180,69,11,.04)' }}">
                <div>
                    <b>{{ $f->assunto }}</b>
                    <span class="pequeno">· {{ $rotuloTipo[$f->tipo] ?? $f->tipo }}</span>
                    @unless ($f->lida())
                        <span class="pequeno" style="color:#b4450b;font-weight:bold">· NÃO LIDA</span>
                    @endunless
                    @if ($f->feita())
                        <span class="pequeno" style="color:#1f7a34;font-weight:bold">· FEITO</span>
                    @endif
                    <span class="mut pequeno">
                        — {{ $f->nickname }} ({{ $f->colony_name ?? 'sem colônia' }}) · {{ $f->email }}
                        · {{ $quando($f->created_at) }} · #{{ $f->id }}
                    </span>
                </div>

                <p class="pequeno" style="margin:4px 0 8px">{{ $f->mensagem }}</p>

                @if ($f->respondida())
                    <div class="pequeno" style="margin:4px 0 8px;padding:6px;background:rgba(31,122,52,.08)">
                        <b>Resposta ({{ $quando($f->respondida_at) }}):</b> {{ $f->resposta }}
                    </div>
                @endif

                <div class="linha-form" style="gap:6px">
                    <form method="POST" action="{{ route('admin.feedback.lida', $f) }}" class="inline">
                        @csrf
                        <button class="leve">{{ $f->lida() ? 'Marcar não lida' : 'Marcar lida' }}</button>
                    </form>

                    <button class="leve" type="button"
                            onclick="document.getElementById('responder-{{ $f->id }}').style.display =
                                     document.getElementById('responder-{{ $f->id }}').style.display === 'none' ? 'block' : 'none'">
                        {{ $f->respondida() ? 'Responder de novo' : 'Responder' }}
                    </button>

                    <form method="POST" action="{{ route('admin.feedback.feito', $f) }}" class="inline">
                        @csrf
                        <button class="leve">{{ $f->feita() ? 'Desmarcar feito' : 'Marcar como FEITO' }}</button>
                    </form>
                </div>

                <form id="responder-{{ $f->id }}" method="POST" action="{{ route('admin.feedback.responder', $f) }}"
                      style="display:none;margin-top:8px;padding:8px;background:rgba(180,69,11,.04)">
                    @csrf
                    <label class="pequeno mut">Resposta — o jogador recebe isto pelo rádio, de "Capital"</label>
                    <textarea name="resposta" rows="3" required>{{ $f->resposta }}</textarea>
                    <div style="margin-top:8px"><button>Enviar resposta</button></div>
                </form>
            </div>
        @empty
            <p class="mut pequeno">
                @if (array_filter($filtros))
                    Nenhuma mensagem com esses filtros.
                @else
                    Nada por aqui ainda. Quando um jogador mandar um bug ou sugestão, aparece aqui.
                @endif
            </p>
        @endforelse

        <div style="margin-top:10px">{{ $feedback->links() }}</div>
    </div>

@endsection
