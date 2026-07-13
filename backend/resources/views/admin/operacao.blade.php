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

    {{-- ── O Marco (§03/§05; D-75): os cinco valores de XP por ato ── --}}
    <h2 class="secao">Marco de colonização</h2>
    <div class="cartao">
        <p class="mut pequeno">
            O Marco anda por <b>atos</b>, e cada ato vale o que está aqui. A <b>curva é fixa</b>
            (50 × N² de XP acumulado — marco 5 = 1.250, marco 20 = 20.000): mudá-la reescalaria o
            marco de todo mundo, e isso é arbitragem, não balanceamento. Mudanças valem para atos
            <b>novos</b> — o ledger de XP nunca é reescrito. Os gates vivos: <b>marco 10</b> fabrica
            Drone nível 2+; <b>marco 20</b> ocupa zona neutra. O Mercado <b>não</b> tem gate
            (contradição consciente com o §05 — o §03 promete o primeiro lote ao recém-chegado).
        </p>

        <form method="POST" action="{{ route('admin.marco.parametros') }}" class="linha-form" style="margin-top:8px">
            @csrf
            <div style="flex:0">
                <label>Obra (XP/nível)</label>
                <input type="number" min="0" max="100000" name="xp_obra_por_nivel"
                       value="{{ $marco->xp_obra_por_nivel }}" required>
            </div>
            <div style="flex:0">
                <label>Zona ocupada</label>
                <input type="number" min="0" max="100000" name="xp_zona_ocupada"
                       value="{{ $marco->xp_zona_ocupada }}" required>
            </div>
            <div style="flex:0">
                <label>Combate vencido</label>
                <input type="number" min="0" max="100000" name="xp_combate_vencido"
                       value="{{ $marco->xp_combate_vencido }}" required>
            </div>
            <div style="flex:0">
                <label>Acordo executado</label>
                <input type="number" min="0" max="100000" name="xp_acordo_executado"
                       value="{{ $marco->xp_acordo_executado }}" required>
            </div>
            <div style="flex:0">
                <label>Mercado executado</label>
                <input type="number" min="0" max="100000" name="xp_mercado_executado"
                       value="{{ $marco->xp_mercado_executado }}" required>
            </div>
            <div style="flex:0"><label>&nbsp;</label><button data-salvar-marco>Salvar</button></div>
        </form>
        <p class="mut pequeno">
            Zerar um valor <b>desliga</b> aquela fonte. Acordo e Mercado só rendem acima do piso de
            500 Fert$ (o anti-farm do D-43, herdado). O retroativo se recalcula com
            <code>artisan fertways:marco --aplicar</code> — os valores acima também valem lá.
        </p>
    </div>

@endsection
