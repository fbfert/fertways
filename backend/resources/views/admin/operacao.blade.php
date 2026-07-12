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

    {{-- ─────────────────────────────────────────────── realocar founders ──
         Era UM botão que aplicava direto. Você clicava e ou movia todas as colônias do jogo, ou levava
         um "abortado" que não dizia POR QUÊ. Agora a tela mostra o plano, diz quais veículos impedem —
         com placa e hora de chegada, para se saber quanto esperar — e exige a palavra REALOCAR. --}}
    <h2 class="secao">Realocar para slots de founder</h2>
    <div class="cartao">
        <p class="mut pequeno">
            Move <b>todas as colônias</b> para os slots de founder do disco central (D-51). A designação
            é determinística e não é escolha de ninguém: a colônia <b>mais antiga</b> leva o primeiro
            slot livre, na ordem canônica. Para mover <b>uma</b> colônia para <b>um</b> lugar, use o
            card abaixo.
        </p>

        @if ($bloqueios->isNotEmpty())
            <p style="color:#b4450b;font-weight:bold;margin-top:10px">
                Bloqueado: {{ $bloqueios->count() }} veículo(s) fora do pátio.
            </p>
            <p class="mut pequeno">
                Realocar agora quebraria a viagem deles — ela guardou a distância no despacho, e mudar
                a origem por baixo dela deixaria a conta apontando para um número que já não existe.
                Espere chegarem.
            </p>
            <table style="margin-top:8px">
                <tr><th>Colônia</th><th>Veículo</th><th>Placa</th><th>Situação</th><th>Chega</th></tr>
                @foreach ($bloqueios as $b)
                    <tr>
                        <td>{{ $b['colonia'] }}</td>
                        <td>#{{ $b['veiculo'] }}</td>
                        <td>{{ $b['placa'] ?? '—' }}</td>
                        <td>{{ $b['status'] }}</td>
                        <td>{{ $b['chega_at'] ?? '—' }}</td>
                    </tr>
                @endforeach
            </table>

        @elseif ($semSlot > 0)
            <p style="color:#b4450b;font-weight:bold;margin-top:10px">
                Bloqueado: {{ $semSlot }} colônia(s) não cabem nos slots populáveis do disco.
            </p>

        @elseif ($plano->isEmpty())
            <p class="mut pequeno" style="margin-top:10px">
                <b>Nada a fazer.</b> Todas as colônias já estão no seu slot de founder.
            </p>

        @else
            <p style="margin-top:10px"><b>O plano</b> — confira antes de aplicar:</p>
            <table style="margin-top:6px">
                <tr><th>Colônia</th><th>De</th><th>Para</th></tr>
                @foreach ($plano as $p)
                    <tr>
                        <td>{{ $p['colony']->name }} <span class="mut pequeno">#{{ $p['colony']->id }}</span></td>
                        <td>({{ $p['colony']->x }}, {{ $p['colony']->y }})</td>
                        <td><b>({{ $p['x'] }}, {{ $p['y'] }})</b></td>
                    </tr>
                @endforeach
            </table>

            <form method="POST" action="{{ route('admin.realocar') }}" class="linha-form" style="margin-top:10px">
                @csrf
                <div style="flex:0">
                    <label>Escreva REALOCAR para confirmar</label>
                    <input type="text" name="confirmacao" placeholder="REALOCAR" required>
                </div>
                <div style="flex:0"><label>&nbsp;</label><button class="perigo">Aplicar o plano</button></div>
            </form>
            <p class="mut pequeno" style="margin-top:6px">
                A guarda de verdade é a do comando, que reconfere na hora de aplicar: entre esta página
                carregar e o botão ser clicado, um veículo pode ter saído do pátio.
            </p>
        @endif
    </div>

    {{-- ─────────────────────────────────────────────── realocação manual ── --}}
    <h2 class="secao">Realocar uma colônia</h2>
    <div class="cartao">
        <p class="mut pequeno">
            Escolha a colônia e o destino. O plano automático acima é determinístico e não serve quando
            se quer mover <b>uma</b> colônia para <b>um</b> lugar.
        </p>

        <p class="mut pequeno" style="margin-top:6px;color:#8a6d00">
            ⚠️ <b>Realocar FORÇA e refaz as viagens</b> em curso a partir da posição nova — não aborta
            como o plano acima. Duas consequências que ninguém desfaz: a <b>energia já gasta não é
            acertada</b> (o veículo pagou pela distância antiga, e o governo come a diferença), e os
            <b>Acordos abertos ficam com o prazo da distância antiga</b>.
        </p>

        <form method="POST" action="{{ route('admin.realocar.manual') }}" style="margin-top:10px">
            @csrf
            <div class="linha-form">
                <div><label>Colônia</label>
                    <select name="colony_id" required>
                        @foreach ($colonias as $c)
                            <option value="{{ $c->id }}">{{ $c->name }} — hoje em ({{ $c->x }}, {{ $c->y }})</option>
                        @endforeach
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
