@extends('admin.layout')

@php
    $fert = fn ($micro) => number_format(((int) $micro) / 1000000, 2, ',', '.');
    $quando = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format('d/m/Y H:i') : '—';
    $dono = auth('admin')->user()->ehDono();
@endphp

@section('content')

    <h2 class="secao">
        <a href="{{ route('admin.jogadores') }}">Jogadores</a> › {{ $jogador->nickname }}
    </h2>

    @if ($suspenso)
        <div class="flash erro" data-suspenso>
            <b>Suspenso</b> desde {{ $quando($jogador->suspenso_em) }} —
            {{ $jogador->suspenso_ate ? 'até '.$quando($jogador->suspenso_ate) : 'por tempo indeterminado' }}.
            <div style="font-weight:400;margin-top:4px">Motivo: {{ $jogador->suspenso_motivo }}</div>
            <div style="font-weight:400;margin-top:4px" class="pequeno">
                Ele não entra no jogo, e <b>nenhuma carga sai da colônia dele</b>. A colônia continua
                produzindo e os veículos em rota chegam — o mundo não para.
            </div>
        </div>
    @endif

    {{-- ─────────────────────────────────────────── Identidade --}}
    <h2 class="secao">Identidade</h2>
    <div class="cartao">
        <form method="POST" action="{{ route('admin.jogador.dados', $jogador) }}" class="linha-form">
            @csrf
            <div><label>Nome</label><input type="text" name="name" value="{{ $jogador->name }}" required></div>
            <div><label>Nickname</label><input type="text" name="nickname" value="{{ $jogador->nickname }}" required></div>
            <div><label>E-mail</label><input type="email" name="email" value="{{ $jogador->email }}" required></div>
            <div style="flex:0"><button>Salvar</button></div>
        </form>

        <form method="POST" action="{{ route('admin.jogador.senha', $jogador) }}" class="linha-form">
            @csrf
            <div><label>Nova senha (mín. 8)</label><input type="password" name="password" required></div>
            <div style="flex:0"><button class="leve">Redefinir senha</button></div>
        </form>
        <p class="mut pequeno">
            Redefinir a senha <b>revoga todos os tokens dele</b>. Sem isso não se recupera conta
            nenhuma: quem tivesse roubado o token continuaria entrando com ele, porque token do
            Sanctum não expira.
        </p>

        <table style="margin-top:10px">
            <tr><th>Cadastrado</th><td>{{ $quando($jogador->created_at) }}</td>
                <th>Tutorial</th><td>{{ $quando($jogador->tutorial_completed_at) }}</td></tr>
            <tr><th>Conciliador desde</th><td>{{ $quando($jogador->conciliador_desde) }}</td>
                <th>Reversões</th><td>{{ $jogador->reversoes }}</td></tr>
        </table>
    </div>

    {{-- ─────────────────────────────────────────── Suspensão --}}
    <h2 class="secao">Suspensão</h2>
    <div class="cartao">
        @if ($suspenso)
            <form method="POST" action="{{ route('admin.jogador.reintegrar', $jogador) }}" class="linha-form">
                @csrf
                <div><label>Motivo da reintegração</label><input type="text" name="motivo" required></div>
                <div style="flex:0"><button data-reintegrar>Reintegrar</button></div>
            </form>
        @else
            <form method="POST" action="{{ route('admin.jogador.suspender', $jogador) }}" class="linha-form">
                @csrf
                <div><label>Motivo (obrigatório — o jogador o verá no login)</label>
                    <input type="text" name="motivo" required maxlength="500"></div>
                <div style="flex:0"><label>Dias (vazio = definitiva)</label>
                    <input type="number" name="dias" min="1" max="3650" style="width:110px"></div>
                <div style="flex:0"><button class="perigo" data-suspender>Suspender</button></div>
            </form>
            <p class="mut pequeno">
                Barra o login, revoga os tokens na hora e <b>fecha a saída de carga</b> — reusa a
                restrição comercial do §9.4. A colônia <b>continua produzindo</b>.
            </p>
        @endif
    </div>

    @if ($colonia)
        {{-- ─────────────────────────────────────────── Correção de estado --}}
        <h2 class="secao">Corrigir estado — {{ $colonia->name }}</h2>
        <div class="cartao">
            <p class="mut pequeno">
                <b>Isto cria recurso do nada.</b> Por isso toda correção vira um lançamento
                <code>ajuste_admin</code> no extrato do colono, com o motivo escrito e o admin que
                fez. A auditoria guarda o antes/depois; o ledger guarda o delta. Os valores abaixo são
                <b>saldos absolutos</b>, não somas.
            </p>

            <form method="POST" action="{{ route('admin.jogador.corrigir', $jogador) }}">
                @csrf
                <div class="linha-form">
                    <div><label>Motivo (obrigatório)</label>
                        <input type="text" name="motivo" required maxlength="255"
                               placeholder="ex.: bug do tick duplicou a produção em 12/07"></div>
                    <div style="flex:0"><label>Fert$</label>
                        <input type="number" step="0.000001" min="0" name="fert"
                               value="{{ $colonia->fert_micro / 1000000 }}" style="width:140px"></div>
                </div>

                <b class="pequeno" style="display:block;margin-top:12px">Recursos</b>
                <div class="grade" style="grid-template-columns:repeat(auto-fill,minmax(180px,1fr))">
                    @foreach ($recursos as $r)
                        <div>
                            <label class="pequeno mut">{{ $r->nome }}</label>
                            <input type="number" min="0" name="recursos[{{ $r->code }}]" value="{{ $r->amount }}">
                        </div>
                    @endforeach
                </div>

                <b class="pequeno" style="display:block;margin-top:12px">Índices de reputação (§26.2 — nascem em 500)</b>
                <div class="grade" style="grid-template-columns:repeat(auto-fill,minmax(180px,1fr))">
                    @foreach ($indices as $i)
                        <div>
                            <label class="pequeno mut">{{ str_replace('_', ' ', $i) }}</label>
                            <input type="number" min="0" max="1000" name="indices[{{ $i }}]" value="{{ $jogador->{$i} }}">
                        </div>
                    @endforeach
                </div>

                <button style="margin-top:12px" data-corrigir>Aplicar correção</button>
            </form>
        </div>

        {{-- ─────────────────────────────────────────── Realocar (só o dono) --}}
        <h2 class="secao">Realocar a colônia</h2>
        <div class="cartao">
            @if (! $dono)
                <p class="mut pequeno">Só o <b>dono</b> pode realocar. É a ação mais difícil de desfazer do painel.</p>
            @else
                <p class="mut pequeno">
                    A colônia está em <b>({{ $colonia->x }}, {{ $colonia->y }})</b>. A distância é o eixo
                    de toda a logística (§25.6): mudá-la muda o frete, o tempo e a energia de <b>todo
                    mundo que negocia com ela</b>.
                </p>

                @if ($avisosRealocacao)
                    <div class="flash erro" style="margin:8px 0">
                        <b>O que isto vai mexer:</b>
                        <ul style="margin:6px 0 0;padding-left:18px;font-weight:400">
                            @foreach ($avisosRealocacao as $aviso)
                                <li class="pequeno">{{ $aviso }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <form method="POST" action="{{ route('admin.jogador.realocar', $jogador) }}" class="linha-form">
                    @csrf
                    <div style="flex:0"><label>X</label><input type="number" name="x" required style="width:90px"></div>
                    <div style="flex:0"><label>Y</label><input type="number" name="y" required style="width:90px"></div>
                    <div><label>Motivo</label><input type="text" name="motivo" required maxlength="255"></div>
                    <div style="flex:0"><label>Escreva REALOCAR</label>
                        <input type="text" name="confirmacao" required placeholder="REALOCAR" style="width:130px"></div>
                    <div style="flex:0"><button class="perigo" data-realocar>Realocar</button></div>
                </form>
            @endif
        </div>

        {{-- ─────────────────────────────────────────── O estado do jogo --}}
        <h2 class="secao">Colônia</h2>
        <div class="cartao">
            <b class="pequeno">Construções ({{ $construcoes->count() }} de 21 slots)</b>
            <table>
                <tr><th>Slot</th><th>Construção</th><th class="num">Nível</th></tr>
                @foreach ($construcoes as $b)
                    <tr><td>{{ $b['slot'] }}</td><td>{{ str_replace('_', ' ', $b['type']) }}</td>
                        <td class="num">{{ $b['level'] }}</td></tr>
                @endforeach
            </table>

            <b class="pequeno" style="display:block;margin-top:12px">Frota</b>
            <table>
                <tr><th>Placa</th><th>Tipo</th><th>Situação</th><th class="num">Conservação</th><th class="num">Horas</th></tr>
                @forelse ($frota as $v)
                    <tr>
                        <td><code>{{ $v->plate ?? '—' }}</code></td>
                        <td>{{ str_replace('_', ' ', $v->type) }}</td>
                        <td>{{ $v->status }}</td>
                        <td class="num">
                            {{ number_format($v->conservacao_bps / 100, 0) }}%
                            @if ($conservacao->desempenhoBps($v) > $v->conservacao_bps)
                                <span class="mut pequeno">(anda a {{ number_format($conservacao->desempenhoBps($v) / 100, 0) }}%, no piso)</span>
                            @endif
                        </td>
                        <td class="num">{{ intdiv($v->uso_ativo_seg, 3600) }} h</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="mut pequeno">Sem veículos.</td></tr>
                @endforelse
            </table>
        </div>

        {{-- ─────────────────────────────────────────── Ministério & acordos --}}
        <h2 class="secao">Reputação, punições e negócios</h2>
        <div class="cartao">
            <table>
                <tr>
                    <th>Confiança comercial</th><td class="num">{{ $jogador->confianca_comercial }}</td>
                    <th>Conduta social</th><td class="num">{{ $jogador->conduta_social }}</td>
                    <th>Status cívico</th><td class="num">{{ $jogador->status_civico }}</td>
                    <th>Honra militar</th><td class="num">{{ $jogador->honra_militar_diplomatica }}</td>
                </tr>
            </table>

            @if ($punicoes->isNotEmpty())
                <b class="pequeno" style="display:block;margin-top:12px">Punições</b>
                <table>
                    <tr><th>Tipo</th><th>Até</th><th>Caso</th></tr>
                    @foreach ($punicoes as $p)
                        <tr><td>{{ $p->kind }}</td><td>{{ $quando($p->until) }}</td><td>#{{ $p->report_id }}</td></tr>
                    @endforeach
                </table>
            @endif

            @if ($denuncias->isNotEmpty())
                <b class="pequeno" style="display:block;margin-top:12px">Denúncias (como autor ou réu)</b>
                <table>
                    <tr><th>#</th><th>Situação</th><th>Autor</th><th>Réu</th></tr>
                    @foreach ($denuncias as $d)
                        <tr><td>{{ $d->id }}</td><td>{{ $d->status }}</td>
                            <td>{{ $d->reporter_id === $jogador->id ? 'ele' : '#'.$d->reporter_id }}</td>
                            <td>{{ $d->accused_id === $jogador->id ? 'ele' : '#'.$d->accused_id }}</td></tr>
                    @endforeach
                </table>
            @endif

            @if ($acordos->isNotEmpty())
                <b class="pequeno" style="display:block;margin-top:12px">Acordos de Troca</b>
                <table>
                    <tr><th>#</th><th>Situação</th><th>Prazo</th></tr>
                    @foreach ($acordos as $a)
                        <tr><td>{{ $a->id }}</td><td>{{ $a->status }}</td><td>{{ $quando($a->deadline_at) }}</td></tr>
                    @endforeach
                </table>
            @endif
        </div>

        {{-- ─────────────────────────────────────────── O extrato --}}
        <h2 class="secao">Extrato (ledger)</h2>
        <div class="cartao">
            <p class="mut pequeno">
                É ele que explica de onde veio cada unidade — <b>inclusive os <code>ajuste_admin</code>
                que este painel lança</b>. Append-only.
            </p>
            <div style="max-height:280px;overflow:auto">
                <table>
                    <tr><th>Quando</th><th>Tipo</th><th>Recurso</th><th class="num">Valor</th><th>Ref</th></tr>
                    @foreach ($ledger as $l)
                        <tr @if ($l->type === 'ajuste_admin') style="background:rgba(234,174,101,.25)" @endif>
                            <td class="mut pequeno">{{ $quando($l->created_at) }}</td>
                            <td class="pequeno">{{ $l->type }}</td>
                            <td class="pequeno">{{ $l->resource_type ?? 'Fert$' }}</td>
                            <td class="num">{{ number_format($l->amount, 0, ',', '.') }}</td>
                            <td class="mut pequeno">{{ $l->ref }}</td>
                        </tr>
                    @endforeach
                </table>
            </div>
        </div>
    @else
        <div class="cartao"><p class="mut">Este jogador ainda não fundou colônia.</p></div>
    @endif

    {{-- ─────────────────────────────────────────── O rastro administrativo --}}
    <h2 class="secao">O que o painel já fez com ele</h2>
    <div class="cartao">
        <table data-auditoria-jogador>
            <tr><th>Quando</th><th>Quem</th><th>Ação</th><th>O quê</th></tr>
            @forelse ($auditoria as $a)
                <tr>
                    <td class="mut pequeno">{{ $quando($a->created_at) }}</td>
                    <td class="pequeno">{{ $a->admin_email ?? '—' }}</td>
                    <td><span class="pilula alerta">{{ $a->acao }}</span></td>
                    <td class="pequeno">{{ $a->resumo }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="mut pequeno">Nada — o painel nunca tocou nesta conta.</td></tr>
            @endforelse
        </table>
    </div>

@endsection
