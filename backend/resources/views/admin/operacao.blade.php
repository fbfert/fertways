@extends("admin.layout")

@php
    $fert = fn ($micro) => number_format(((int) $micro) / 1000000, 2, ",", ".");
    $quando = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format("d/m H:i") : "—";
@endphp

@section("content")
    {{-- ── Resumo ── --}}
    <h2 class="secao">Panorama</h2>
    <div class="grade">
        <div class="tile"><b>{{ $resumo['colonias'] }}</b><span>Colônias</span></div>
        <div class="tile"><b>{{ $resumo['jogadores'] }}</b><span>Jogadores</span></div>
        <div class="tile"><b>{{ $fert($resumo['fert_em_circulacao_micro']) }}</b><span>Fert$ em circulação</span></div>
        <div class="tile"><b>{{ $resumo['suspensos'] }}</b><span>Suspensos</span></div>
        <div class="tile"><b>{{ $resumo['casos_na_equipe'] }}</b><span>Casos na equipe</span></div>
        <div class="tile"><b>{{ $resumo['ordens_abertas'] }}</b><span>Ordens abertas</span></div>
        <div class="tile"><b>{{ $resumo['veiculos_em_rota'] }}/{{ $resumo['veiculos_ociosos'] }}</b><span>Veíc. rota/ociosos</span></div>
        <div class="tile"><b>{{ $resumo['zonas_ocupadas'] }}</b><span>Zonas ocupadas</span></div>
    </div>

    {{-- ── Operação ── --}}
    <h2 class="secao">Operação</h2>
    <div class="cartao">
        <form method="POST" action="{{ route('admin.tick') }}" class="inline">
            @csrf<button>Disparar tick</button>
        </form>
        <span class="mut pequeno">
            O tick avança o mundo: produção, viagens, folha do Ministério, prazos, e as rodadas de
            combate. O cron já o dispara a cada minuto — este botão é para não esperar.
        </span>
    </div>

    {{-- ─────────────────────────────────────────────── realocar UMA colônia ──
         Não há botão de realocar todas, e é decisão do usuário (2026-07-13). Existiu um "Realocar
         founders" que movia o planeta inteiro de uma vez — a ferramenta de uma migração histórica
         (D-51) que ficara pendurada aqui, ao lado do "Disparar tick", como se fosse coisa que se faz.
         Realocar é ato sobre UM jogador escolhido. --}}
    <h2 class="secao">Realocar uma colônia</h2>
    <div class="cartao">
        <p class="mut pequeno">
            Escolha o jogador e o destino. A realocação é <b>pontual</b>: move uma colônia, e só ela.
        </p>

        <p class="mut pequeno" style="margin-top:6px;color:#8a6d00">
            ⚠️ <b>Realocar FORÇA e refaz as viagens</b> em curso a partir da posição nova — não espera
            os veículos voltarem ao pátio. Duas consequências que ninguém desfaz: a <b>energia já gasta
            não é acertada</b> (o veículo pagou pela distância antiga, e o governo come a diferença), e
            os <b>Acordos abertos ficam com o prazo da distância antiga</b>.
        </p>

        <form method="POST" action="{{ route('admin.realocar.manual') }}" style="margin-top:10px">
            @csrf
            <div class="linha-form">
                <div><label>Jogador / colônia</label>
                    <select name="colony_id" required>
                        @forelse ($colonias as $c)
                            <option value="{{ $c->id }}">
                                {{ $c->user?->nickname ?? '—' }} · {{ $c->name }} — hoje em ({{ $c->x }}, {{ $c->y }})
                            </option>
                        @empty
                            <option disabled>Nenhuma colônia fundada.</option>
                        @endforelse
                    </select>
                </div>
                <div style="flex:0"><label>X</label><input type="number" name="x" required style="width:80px"></div>
                <div style="flex:0"><label>Y</label><input type="number" name="y" required style="width:80px"></div>
            </div>
            <div class="linha-form" style="margin-top:8px">
                <div><label>Motivo (fica na auditoria)</label>
                    <input type="text" name="motivo" maxlength="255" required
                           placeholder="por que esta colônia está sendo movida">
                </div>
                <div style="flex:0"><label>Escreva REALOCAR</label>
                    <input type="text" name="confirmacao" placeholder="REALOCAR" required>
                </div>
                <div style="flex:0"><label>&nbsp;</label><button class="perigo">Realocar</button></div>
            </div>
        </form>
    </div>

@endsection
