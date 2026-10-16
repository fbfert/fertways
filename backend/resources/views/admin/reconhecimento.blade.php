@extends("admin.layout")

@php
    $quando = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format("d/m H:i") : "—";
    $fases = \App\Domain\Reconhecimento\Fases::NOMES;
@endphp

@section("content")
    <h2 class="secao">Telescópio Gagarin — Missões de Reconhecimento (D-282)</h2>
    <div class="cartao">
        <p class="mut pequeno">
            O aditivo v3.4 §2 publica o pipeline inteiro. O Gagarin ativa com <b>{{ $config->limiar_jogadores }}
            jogadores ou {{ $config->limiar_dias }} dias de servidor</b>, publica um boletim a cada
            {{ $config->boletim_intervalo_horas }} h e abre sozinho a leitura das oito luas, uma de cada vez.
            A missão <b>planetária</b> é sua: o GDD não diz que planeta ela descobre, e manda que o resultado seja
            escrito e <b>lacrado antes</b> da fase pública. Você pausa por manutenção; <b>não edita resultado</b>.
        </p>
        <p style="margin-top:8px" data-gagarin-estado>
            @if ($ativo)
                <b>Em operação</b> desde {{ $quando($config->ativado_em) }}.
                Próximo boletim: {{ $quando($proximoBoletim) }}.
                Boletins desde a última rota nova: <b>{{ $config->boletins_desde_a_ultima_rota }}</b> (pede 3).
                Última rota nova: {{ $quando($ultimaRota) }} (a próxima, 14 dias depois).
            @else
                <b>Inativo.</b> {{ $contagem['jogadores'] }} de {{ $contagem['limiar_jogadores'] }} jogadores;
                {{ $contagem['dias'] }} de {{ $contagem['limiar_dias'] }} dias desde a abertura
                ({{ $quando($abertura) }}).
            @endif
            @if ($pausado)
                <b style="color:var(--rust)">Pausado desde {{ $quando($config->pausado_em) }}.</b>
            @endif
        </p>

        <form method="POST" action="{{ route('admin.reconhecimento.config') }}" style="margin-top:12px">
            @csrf
            <div class="linha-form">
                <div style="flex:0"><label>Abertura do servidor</label>
                    <input type="date" name="servidor_aberto_em" value="{{ $config->servidor_aberto_em ? substr($config->servidor_aberto_em, 0, 10) : '' }}"></div>
                <div style="flex:0"><label>Jogadores p/ ativar</label>
                    <input type="number" min="1" name="limiar_jogadores" value="{{ $config->limiar_jogadores }}" style="width:80px"></div>
                <div style="flex:0"><label>Dias p/ ativar</label>
                    <input type="number" min="0" name="limiar_dias" value="{{ $config->limiar_dias }}" style="width:70px"></div>
                <div style="flex:0"><label>Boletim (h, 48–96)</label>
                    <input type="number" min="48" max="96" name="boletim_intervalo_horas" value="{{ $config->boletim_intervalo_horas }}" style="width:70px"></div>
                <div style="flex:0"><label>Tarefas/dia</label>
                    <input type="number" min="1" max="10" name="tarefas_por_dia" value="{{ $config->tarefas_por_dia }}" style="width:60px"></div>
                <div style="flex:0"><label>XP por tarefa</label>
                    <input type="number" min="0" name="xp_por_tarefa" value="{{ $config->xp_por_tarefa }}" style="width:80px"></div>
                <div style="flex:0"><label>Meta de tarefas</label>
                    <input type="number" min="0" name="meta_de_tarefas" value="{{ $config->meta_de_tarefas }}" style="width:80px"></div>
                <div style="flex:0"><label>Viagens na abertura</label>
                    <input type="number" min="1" max="20" name="viagens_na_abertura" value="{{ $config->viagens_na_abertura }}" style="width:60px"></div>
                <div style="flex:0"><label>&nbsp;</label><button>Salvar</button></div>
            </div>
            <p class="mut pequeno">
                Vazia, a abertura é a fundação da primeira colônia. A meta é o mínimo de tarefas para a
                triangulação (ou a leitura orbital) terminar, além das 48 h de calendário. "Viagens na abertura" é o
                limite de viagens por colônia nas primeiras 48 h de uma rota nova (§2.2).
            </p>
        </form>

        <form method="POST" action="{{ route('admin.reconhecimento.pausa') }}" style="margin-top:12px" class="linha-form">
            @csrf
            <div><label>Justificativa (vai para o registro público)</label>
                <input type="text" name="justificativa" minlength="5" maxlength="300" required style="width:100%"></div>
            <div style="flex:0"><label>&nbsp;</label><button>{{ $pausado ? 'Retomar' : 'Pausar por manutenção' }}</button></div>
        </form>
    </div>

    <h2 class="secao">As oito luas (§2.4)</h2>
    <div class="cartao">
        <table>
            <tr><th>#</th><th>Lua</th><th>Recurso</th><th>Primeira revelação</th><th>Revelada</th></tr>
            @foreach ($luas as $l)
                <tr><td class="num">{{ $l->ordem }}</td><td>{{ $l->nome }}</td><td>{{ $l->recurso }}</td>
                    <td>{{ $l->primeira_revelacao }}</td><td>{{ $quando($l->revelada_em) }}</td></tr>
            @endforeach
        </table>
    </div>

    <h2 class="secao">Missões</h2>
    <div class="cartao">
        @if ($missoes->isEmpty())
            <p class="mut">Nenhuma missão ainda.</p>
        @else
            <table>
                <tr><th>#</th><th>Tipo</th><th>Alvo</th><th>Setor</th><th>Fase</th><th>Prazo</th><th class="num">Tarefas</th><th>Assinatura</th><th></th></tr>
                @foreach ($missoes as $m)
                    <tr data-missao="{{ $m->id }}">
                        <td class="num">{{ $m->id }}</td><td>{{ $m->tipo }}</td>
                        <td>{{ $m->resultado['nome'] ?? $m->alvo }}</td><td>{{ $m->setor }}</td>
                        <td>{{ $fases[$m->fase] ?? $m->fase }}</td><td>{{ $quando($m->fase_ate) }}</td>
                        <td class="num">{{ $m->tarefas }} / {{ $m->meta_de_tarefas }}</td>
                        <td><code style="font-size:.7rem">{{ substr($m->assinatura, 0, 16) }}…</code></td>
                        <td>
                            @if (! $m->concluida_em)
                                <form method="POST" action="{{ route('admin.reconhecimento.reabrir', $m) }}" style="display:flex;gap:4px">
                                    @csrf
                                    <input type="text" name="justificativa" placeholder="justificativa" minlength="10" required style="width:140px">
                                    <button>Reabrir fase</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </table>
        @endif
    </div>

    @if (auth('admin')->user()->ehDono())
        <h2 class="secao">Abrir uma missão planetária</h2>
        <div class="cartao">
            <p class="mut pequeno">
                O planeta inteiro é escrito <b>agora</b> e lacrado com SHA-256 (§2.5); o nome só aparece aos
                colonos no fim da validação. Pede o Gagarin em operação, nenhuma outra planetária em curso,
                <b>três boletins</b> e <b>14 dias</b> desde a última rota nova. Os preços seguem a regra do D-279:
                nenhuma compra acima do que o Governo cobra. Faixas: Próxima 4–6 h, Média 7–12, Distante 13–18,
                Remota 19–31 de voo da sonda.
            </p>
            <form method="POST" action="{{ route('admin.reconhecimento.planetaria') }}">
                @csrf
                <div class="linha-form">
                    <div><label>Setor (o que a pista revela)</label><input type="text" name="setor" maxlength="60" required value="{{ old('setor') }}"></div>
                    <div style="flex:0"><label>Anomalia</label>
                        <select name="anomalia"><option value="gravitacional">gravitacional</option><option value="energetica">energética</option><option value="espectral">espectral</option></select></div>
                    <div style="flex:0"><label>Faixa</label>
                        <select name="faixa">@foreach ($faixas as $f => [$min, $max])<option value="{{ $f }}">{{ $f }} ({{ $min }}–{{ $max }} h)</option>@endforeach</select></div>
                    <div style="flex:0"><label>Voo (h)</label><input type="number" name="voo_horas" min="1" max="31" required style="width:60px" value="{{ old('voo_horas') }}"></div>
                </div>
                <div class="linha-form">
                    <div style="flex:0"><label>Chave</label><input type="text" name="chave" maxlength="30" required style="width:120px" value="{{ old('chave') }}"></div>
                    <div style="flex:0"><label>Nome</label><input type="text" name="nome" maxlength="40" required style="width:140px" value="{{ old('nome') }}"></div>
                    <div style="flex:0"><label>Viagem (min)</label><input type="number" name="distancia_minutos" min="1" required style="width:90px" value="{{ old('distancia_minutos') }}"></div>
                    <div style="flex:0"><label>Risco</label>
                        <select name="risco"><option>nenhum</option><option>baixo</option><option>alto</option></select></div>
                    <div style="flex:0"><label>Sinistro (%)</label><input type="number" step="0.01" name="risco_chance" min="0" max="100" required style="width:70px" value="{{ old('risco_chance', 0) }}"></div>
                    <div style="flex:0"><label>Perda (%)</label><input type="number" step="0.01" name="risco_perda" min="0" max="100" required style="width:70px" value="{{ old('risco_perda', 0) }}"></div>
                </div>
                <div class="linha-form">
                    <div><label>Exporta</label><input type="text" name="exporta" maxlength="200" required value="{{ old('exporta') }}"></div>
                    <div><label>Importa</label><input type="text" name="importa" maxlength="200" required value="{{ old('importa') }}"></div>
                </div>
                <label>Preços (recurso:compra:venda, em Fert$ por unidade)</label>
                <textarea name="precos" rows="5" required style="width:100%">{{ old('precos') }}</textarea>
                <div style="margin-top:8px"><button data-abrir-planetaria>Lacrar e abrir a missão</button></div>
            </form>
        </div>
    @endif

    <h2 class="secao">Registro público</h2>
    <div class="cartao">
        <table>
            <tr><th>Quando</th><th>Missão</th><th>Evento</th><th>Detalhe</th></tr>
            @foreach ($registro as $r)
                <tr><td>{{ $quando($r->created_at) }}</td><td class="num">#{{ $r->missao_id }}</td><td>{{ $r->evento }}</td>
                    <td style="font-size:.8rem;word-break:break-all">{{ $r->detalhe }}</td></tr>
            @endforeach
        </table>
    </div>
@endsection
