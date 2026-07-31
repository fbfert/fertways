@extends("admin.layout")

@php
    $fert = fn ($micro) => number_format($micro / 1000000, 2, ',', '.');
    $num = fn ($n) => number_format($n, 0, ',', '.');
@endphp

@section("content")

    <h2 class="secao">Métricas de produto</h2>

    <div class="cartao">
        <p class="mut pequeno">
            A fase A2.0 existe para parar de avaliar o jogo pelo “funciona/não funciona”.
            Os números abaixo cobrem os últimos <strong>{{ $dados['dias'] }} dias</strong>
            (desde {{ $dados['desde'] }}).
            Sessão sai da telemetria; economia sai do retrato diário, que deriva do ledger.
            <strong>Nada é recontado de uma segunda fonte.</strong>
        </p>
    </div>

    {{--
        As LACUNAS vêm primeiro, e não no rodapé.

        Um painel que preenchesse com zero o que ninguém instrumentou seria pior do que um painel
        incompleto: zero e “ninguém mediu” são a mesma imagem na tela e coisas opostas na
        realidade. Alguém leria um funil zerado como “ninguém abandona o onboarding”.
    --}}
    @if (count($dados['lacunas']) > 0)
        <h2 class="secao">⚠️ Ainda sem medida</h2>
        <div class="cartao">
            <p class="mut pequeno">
                Estes indicadores estão na lista da A2.0.2 e <strong>não têm de onde sair hoje</strong> —
                o evento que os alimenta ainda não é emitido por ninguém. Não são zeros: são ausências.
            </p>
            <table>
                <thead><tr><th>Indicador</th><th>Evento que falta</th><th>Onde nasceria</th></tr></thead>
                <tbody>
                @foreach ($dados['lacunas'] as $l)
                    <tr>
                        <td>{{ $l['indicador'] }}</td>
                        <td><code>{{ $l['falta'] }}</code></td>
                        <td class="mut pequeno">{{ $l['onde'] }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif

    {{-- ─────────────────────────────────────────────────────────── sessão --}}
    <h2 class="secao">Sessão</h2>
    <div class="cartao">
        <table>
            <tbody>
                <tr><td>DAU (24 h)</td><td><strong>{{ $num($dados['sessao']['dau']) }}</strong></td></tr>
                <tr><td>WAU (7 dias)</td><td><strong>{{ $num($dados['sessao']['wau']) }}</strong></td></tr>
                <tr><td>Logins no período</td><td>{{ $num($dados['sessao']['logins']) }}</td></tr>
                <tr><td>Sessões por dia (média)</td><td>{{ $dados['sessao']['sessoes_por_dia'] }}</td></tr>
                <tr>
                    <td>Duração mediana</td>
                    <td>
                        @if ($dados['sessao']['duracao_mediana_min'] === null)
                            <span class="mut">sem par login→logout ainda</span>
                        @else
                            {{ $dados['sessao']['duracao_mediana_min'] }} min
                        @endif
                    </td>
                </tr>
            </tbody>
        </table>

        {{--
            O viés vai JUNTO do número, e não numa nota de rodapé que ninguém lê.

            A duração sai de pares login→logout, e quem fecha a aba nunca emite logout. Uma mediana
            de 20 min com 15% de cobertura não quer dizer “a sessão típica dura 20 min”; quer dizer
            “das poucas sessões que alguém encerrou de propósito, metade durou menos que isso”.
        --}}
        <p class="mut pequeno" style="margin-top:8px">
            ⚠️ A mediana cobre apenas <strong>{{ $dados['sessao']['cobertura_logout_pct'] }}%</strong>
            dos logins ({{ $num($dados['sessao']['pares_login_logout']) }} pares login→logout).
            Quem fecha a aba não emite logout, e essas sessões não entram na conta — o número
            descreve quem sai pelo botão, não o jogador típico.
        </p>
    </div>

    {{-- ─────────────────────────────────────────────────────────── economia --}}
    <h2 class="secao">Economia</h2>
    <div class="cartao">
        @if (! $dados['economia']['tem_retrato'])
            <p class="mut pequeno">
                O retrato diário está vazio. Isso <strong>não</strong> quer dizer que a economia
                parou — quer dizer que <code>fertways:telemetria-diaria</code> ainda não rodou
                (ele roda às 00h10). São coisas diferentes.
            </p>
        @else
            <table>
                <tbody>
                    <tr>
                        <td>Fert$ emitido</td>
                        <td><strong>{{ $fert($dados['economia']['fert_emitido_micro']) }}</strong></td>
                    </tr>
                    <tr>
                        <td>Fert$ destruído</td>
                        <td><strong>{{ $fert($dados['economia']['fert_destruido_micro']) }}</strong></td>
                    </tr>
                </tbody>
            </table>

            <h3 class="secao" style="margin-top:12px">Recursos gerados e destruídos</h3>
            <table>
                <thead><tr><th>Recurso</th><th>Gerado</th><th>Destruído</th></tr></thead>
                <tbody>
                @forelse ($dados['economia']['recursos'] as $r)
                    <tr>
                        <td>{{ $r->resource_type }}</td>
                        <td>{{ $num($r->gerado) }}</td>
                        <td>{{ $num($r->destruido) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="mut">nenhum movimento no período</td></tr>
                @endforelse
                </tbody>
            </table>

            <p class="mut pequeno" style="margin-top:8px">
                Escrow, transferência entre colônias, estorno e ajuste do operador ficam
                <strong>fora</strong> destas somas: são mudança de lugar ou correção, não criação
                nem destruição de valor (D-163).
            </p>
        @endif
    </div>

    {{-- ─────────────────────────────────────────────────────────── riqueza --}}
    <h2 class="secao">Concentração de riqueza</h2>
    <div class="cartao">
        @if ($dados['riqueza']['topo_10_pct'] === null)
            <p class="mut">Nenhuma colônia fundada ainda.</p>
        @else
            <p>
                As <strong>{{ $dados['riqueza']['topo_10_quantas'] }}</strong> colônias mais ricas
                (10% de {{ $num($dados['riqueza']['colonias']) }}) detêm
                <strong>{{ $dados['riqueza']['topo_10_pct'] }}%</strong>
                dos {{ $fert($dados['riqueza']['total_micro']) }} Fert$ em mãos de colonos.
            </p>
            <p class="mut pequeno">
                Fatia do topo em vez de Gini, de propósito: “os 10% mais ricos têm 60%” diz mais a
                quem vai decidir balanceamento do que “Gini 0,48”.
            </p>
        @endif
    </div>

    {{-- ─────────────────────────────────────────────────────────── mundo --}}
    <h2 class="secao">Mundo</h2>
    <div class="cartao">
        <table>
            <tbody>
                <tr><td>Colônias</td><td>{{ $num($dados['mundo']['colonias']) }}</td></tr>
                <tr><td>Federações</td><td>{{ $num($dados['mundo']['federacoes']) }}</td></tr>
                <tr>
                    <td>Zonas ocupadas</td>
                    <td>{{ $num($dados['mundo']['zonas_ocupadas']) }} de {{ $num($dados['mundo']['zonas_totais']) }}</td>
                </tr>
                <tr><td>Combates registrados</td><td>{{ $num($dados['mundo']['combates']) }}</td></tr>
            </tbody>
        </table>
    </div>

@endsection
