@extends("admin.layout")

@php
    $quando = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format("d/m H:i") : "—";
@endphp

@section("content")
    {{-- ── O rádio do planeta (§10; D-77) ── --}}
    <h2 class="secao">Chat — os canais públicos</h2>
    <div class="cartao">
        <p class="mut pequeno">
            Global, regiões e vizinhança — as últimas 100. As <b>privadas não aparecem aqui</b>:
            acessá-las exige o formulário lá embaixo, que <b>registra o acesso na auditoria antes de
            mostrar qualquer coisa</b> (§10.3: "todo acesso interno é registrado").
        </p>
        <table style="margin-top:8px">
            <tr><th>Quando</th><th>Canal</th><th>Quem</th><th>Mensagem</th></tr>
            @forelse ($mensagens as $m)
                <tr data-msg="{{ $m->id }}">
                    <td class="mut pequeno" style="white-space:nowrap">{{ $quando($m->created_at) }}</td>
                    <td class="pequeno">{{ $m->channel }}</td>
                    <td class="pequeno"><b>{{ $m->user->nickname ?? "—" }}</b></td>
                    <td class="pequeno">{{ $m->body }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="mut">O rádio está em silêncio — ninguém falou ainda.</td></tr>
            @endforelse
        </table>
    </div>

    {{-- ── Moderação ── --}}
    <h2 class="secao">Moderação</h2>
    <div class="cartao">
        <b class="pequeno">Reincidência do filtro (§10.2)</b>
        <p class="mut pequeno">O filtro bloqueia e conta; quem pune é gente. Silencie pela ficha do jogador.</p>
        @if ($reincidencia->isEmpty())
            <p class="mut pequeno">Nenhuma mensagem barrada até hoje.</p>
        @else
            <table>
                <tr><th>Colono</th><th class="num">Barradas</th><th>Última</th></tr>
                @foreach ($reincidencia as $r)
                    <tr><td>{{ $r->nickname }}</td><td class="num">{{ $r->barradas }}</td><td>{{ $quando($r->ultima) }}</td></tr>
                @endforeach
            </table>
        @endif

        <b class="pequeno" style="display:block;margin-top:12px">Silenciados agora</b>
        @if ($silenciados->isEmpty())
            <p class="mut pequeno">Ninguém em silêncio.</p>
        @else
            <table>
                <tr><th>Colono</th><th>Até</th><th>Origem</th></tr>
                @foreach ($silenciados as $p)
                    <tr>
                        <td>{{ $p->user->nickname ?? "—" }}</td>
                        <td>{{ $quando($p->expires_at) }}</td>
                        <td class="pequeno">{{ $p->report_id ? "Ministério (caso #{$p->report_id})" : "painel" }}</td>
                    </tr>
                @endforeach
            </table>
        @endif
    </div>

    {{-- ── Parâmetros ── --}}
    <h2 class="secao">Parâmetros do chat</h2>
    <div class="cartao">
        <form method="POST" action="{{ route('admin.chat.parametros') }}">
            @csrf
            <div class="linha-form">
                <div style="flex:0">
                    <label>Raio da vizinhança (slots)</label>
                    <input type="number" min="1" max="100" name="vizinhanca_raio_slots"
                           value="{{ $config->vizinhanca_raio_slots }}" required>
                </div>
                <div>
                    <label>Termos vedados (um por linha) — o MESMO filtro vale para o nickname (§03)</label>
                    <textarea name="termos" rows="4" style="width:100%">{{ implode("\n", $config->termos()) }}</textarea>
                </div>
                <div style="flex:0"><label>&nbsp;</label><button data-salvar-chat>Salvar</button></div>
            </div>
        </form>
        <p class="mut pequeno">
            O filtro <b>bloqueia o envio e avisa o autor</b> (D-77) — não censura com asteriscos nem
            publica sinalizando. Vale só nos canais públicos: privadas e federação são por denúncia
            (§10.2). Retenção publicada: global/região 180 dias, vizinhança 90, privadas ficam.
        </p>
    </div>

    {{-- ── A espiada auditada ── --}}
    <h2 class="secao">Acessar uma conversa privada</h2>
    <div class="cartao">
        <p class="mut pequeno">
            <b>Todo acesso fica na auditoria antes de a conversa abrir</b> — é o §10.3, e não tem
            botão que fure isso. Use quando um caso do Ministério pedir o histórico como evidência.
        </p>
        <form method="POST" action="{{ route('admin.chat.espiar') }}" class="linha-form">
            @csrf
            <div style="flex:0"><label>Nickname A</label><input type="text" name="nickname_a" required></div>
            <div style="flex:0"><label>Nickname B</label><input type="text" name="nickname_b" required></div>
            <div><label>Motivo (obrigatório — vai à auditoria)</label><input type="text" name="motivo" required maxlength="255"></div>
            <div style="flex:0"><label>&nbsp;</label><button class="perigo" data-espiar>Abrir com registro</button></div>
        </form>

        @if ($privada->isNotEmpty())
            <table style="margin-top:10px" data-conversa-privada>
                <tr><th>Quando</th><th>Quem</th><th>Mensagem</th></tr>
                @foreach ($privada as $m)
                    <tr>
                        <td class="mut pequeno" style="white-space:nowrap">{{ $quando($m->created_at) }}</td>
                        <td class="pequeno"><b>{{ $m->user->nickname ?? "—" }}</b></td>
                        <td class="pequeno">{{ $m->body }}</td>
                    </tr>
                @endforeach
            </table>
        @endif
    </div>
@endsection
