@extends('admin.layout')

@php
    $fert = fn ($micro) => number_format(((int) $micro) / 1000000, 2, ',', '.');
    $quando = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format('d/m H:i') : '—';
@endphp

@section('content')

    {{-- ── Resumo ── --}}
    <h2 class="secao">Panorama</h2>
    <div class="grade">
        <div class="tile"><b>{{ $resumo['colonias'] }}</b><span>Colônias</span></div>
        <div class="tile"><b>{{ $resumo['jogadores'] }}</b><span>Jogadores</span></div>
        <div class="tile"><b>{{ $resumo['suspensos'] }}</b><span>Suspensos</span></div>
        <div class="tile"><b>{{ $resumo['admins'] }}</b><span>Admins ativos</span></div>
        <div class="tile"><b>{{ $fert($resumo['fert_em_circulacao_micro']) }}</b><span>Fert$ em circulação</span></div>
        <div class="tile"><b>{{ $resumo['casos_na_equipe'] }}</b><span>Casos na equipe</span></div>
        <div class="tile"><b>{{ $resumo['ordens_abertas'] }}</b><span>Ordens abertas</span></div>
        <div class="tile"><b>{{ $resumo['veiculos_em_rota'] }}/{{ $resumo['veiculos_ociosos'] }}</b><span>Veíc. rota/ociosos</span></div>
        <div class="tile"><b>{{ $resumo['zonas_ocupadas'] }}</b><span>Zonas ocupadas</span></div>
    </div>

    {{--
        Bugs/Melhorias (D-95): aparece só quando há mensagem não lida — mesmo princípio do card
        do Mercado do Governo, abaixo: nenhum card vazio "está tudo bem" ocupando espaço.
    --}}
    @if ($resumo['feedback_nao_lido'] > 0)
        <h2 class="secao">Bugs/Melhorias — mensagens novas</h2>
        <div class="cartao" data-alerta-feedback>
            <p class="mut pequeno">
                <b>{{ $resumo['feedback_nao_lido'] }}</b> mensagem(ns) não lida(s) de jogadores.
            </p>
            <a href="{{ route('admin.feedback', ['estado' => 'nao_lida']) }}" class="pequeno">Ver as mensagens →</a>
        </div>
    @endif

    {{--
        O Mercado do Governo (D-87): quem não está à venda agora. Aparece só quando há algo a
        fazer — nenhum card vazio "está tudo bem" ocupando espaço.
    --}}
    @if ($recursosSemOfertaDoGoverno->isNotEmpty())
        <h2 class="secao">Mercado do Governo — sem oferta</h2>
        <div class="cartao" data-alerta-mercado-governo>
            <p class="mut pequeno">
                <b>{{ $recursosSemOfertaDoGoverno->count() }}</b> recurso(s) sem oferta ativa no
                Mercado Central: {{ $recursosSemOfertaDoGoverno->implode(', ') }}.
            </p>
            <a href="{{ route('admin.economia', ['aba' => 'mercado']) }}" class="pequeno">Preencher no Mercado →</a>
        </div>
    @endif


    {{--
        Os últimos atos do painel (D-61).

        Vem aqui, na visão geral, e não só na aba da auditoria, porque é o PRIMEIRO lugar onde se
        olha quando alguma coisa parece errada — "o que foi que mexeram?". Antes do D-61 esta
        pergunta não tinha resposta: o painel não deixava rastro nenhum.
    --}}
    <h2 class="secao">Últimos atos do painel</h2>
    <div class="cartao">
        <table data-ultimos-atos>
            <tr><th>Quando</th><th>Quem</th><th>Ação</th><th>O quê</th></tr>
            @forelse ($ultimosAtos as $a)
                <tr>
                    <td class="mut pequeno">{{ $quando($a->created_at) }}</td>
                    <td class="pequeno">{{ $a->admin_email ?? '—' }}</td>
                    <td><span class="pilula alerta">{{ $a->acao }}</span></td>
                    <td class="pequeno">{{ $a->resumo }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="mut pequeno">Nada ainda.</td></tr>
            @endforelse
        </table>
        <p class="mut pequeno" style="margin-top:8px">
            <a href="{{ route('admin.auditoria') }}">Ver o log completo →</a>
            O log é <b>append-only</b>: nem o admin apaga uma linha dele.
        </p>
    </div>

    {{-- ── Colônias & Jogadores ── --}}
    <h2 class="secao">Colônias</h2>
    <div class="cartao">
        <table>
            <tr><th>#</th><th>Nome</th><th>Colono</th><th>Posição</th><th class="num">Fert$</th></tr>
            @foreach ($colonias as $c)
                <tr><td>{{ $c->id }}</td><td>{{ $c->name }}</td><td>{{ $c->user?->nickname ?? '—' }}</td>
                    <td>({{ $c->x }}, {{ $c->y }})</td><td class="num">{{ $fert($c->fert_micro) }}</td></tr>
            @endforeach
        </table>
    </div>

    {{-- ── Logística ── --}}
    @if ($obras->isNotEmpty() || $zonas->isNotEmpty())
    <h2 class="secao">Logística</h2>
    <div class="cartao">
        @if ($obras->isNotEmpty())
            <b class="pequeno">Fila de obras</b>
            <table>
                <tr><th>Colônia</th><th>Nível-alvo</th><th>Situação</th><th>Conclui</th></tr>
                @foreach ($obras as $o)
                    <tr><td>{{ $o->colony?->name }}</td><td>{{ $o->target_level }}</td><td>{{ $o->status }}</td><td>{{ $quando($o->finishes_at) }}</td></tr>
                @endforeach
            </table>
        @endif
        @if ($zonas->isNotEmpty())
            <b class="pequeno" style="display:block;margin-top:10px">Zonas ocupadas</b>
            <table>
                <tr><th>Distrito</th><th>Mineral</th><th>Dono</th><th>Situação</th><th class="num">Depósito</th></tr>
                @foreach ($zonas as $z)
                    <tr><td>{{ $z->district }}</td><td>{{ $z->mineral }}</td><td>{{ $z->owner?->name ?? '—' }}</td><td>{{ $z->status }}</td><td class="num">{{ $z->deposit_amount }}</td></tr>
                @endforeach
            </table>
        @endif
    </div>
    @endif


@endsection
