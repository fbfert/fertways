@extends("admin.layout")

@php
    $fert = fn ($micro) => number_format(((int) $micro) / 1000000, 2, ",", ".");
    $quando = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format("d/m H:i") : "—";
    $abas = [
        "ministerio" => "Ministério dos Transportes",
        "garagem" => "Garagem do Governo",
        "frota" => "Frota do Planeta",
    ];
    $colunas = [
        'placa' => 'Placa', 'tipo' => 'Tipo', 'dono' => 'Dono', 'situacao' => 'Situação',
        'conservacao' => 'Conservação', 'teto' => 'Teto', 'manutencao' => 'Manut.', 'uso' => 'Uso',
    ];
    $sortAtual = $sort ?? '';
    $dirAtual = $dir ?? 'asc';
    $ordenarPor = function (string $coluna) use ($sortAtual, $dirAtual) {
        $novaDir = ($sortAtual === $coluna && $dirAtual === 'asc') ? 'desc' : 'asc';

        return request()->fullUrlWithQuery(['aba' => 'frota', 'sort' => $coluna, 'dir' => $novaDir]);
    };
@endphp

@section("content")

    <nav class="abas" style="background:transparent;padding:0;margin-bottom:16px">
        @foreach ($abas as $slug => $rotulo)
            <a href="{{ route('admin.transportes', ['aba' => $slug]) }}"
               data-aba-transportes="{{ $slug }}"
               style="color:{{ $aba === $slug ? 'var(--rust)' : 'var(--ink-soft)' }};
                      background:{{ $aba === $slug ? 'var(--sand-light)' : 'transparent' }};
                      border:1px solid rgba(180,69,11,.2)">
                {{ $rotulo }}
            </a>
        @endforeach
    </nav>

    {{-- ── Ministério dos Transportes (§16, D-60) ── --}}
    @if ($aba === "ministerio")
        <h2 class="secao">Ministério dos Transportes</h2>
        <div class="cartao">
            <p class="mut pequeno">
                O §16 dá a este painel seis atribuições — e <b>quatro delas são configurar números que o
                GDD nunca publica</b>: a curva de depreciação, o limite crítico, a perda de vida útil e o
                teto de revenda. Foi por isso que a depreciação pôde sair da geladeira sem inventar
                constante no código. O jogo obedece ao que estiver aqui.
            </p>

            <table style="margin-top:8px">
                <tr><th>Frota do governo</th><th class="num">Agora</th></tr>
                <tr><td>Caminhões na prateleira (alvo: {{ $frotaGoverno['alvo'] }})</td><td class="num" data-estoque-governo>{{ $frotaGoverno['estoque'] }}</td></tr>
                <tr><td>Na linha de montagem</td><td class="num">{{ $frotaGoverno['fabricando'] }}</td></tr>
            </table>
            <p class="mut pequeno" style="margin-top:6px">
                A reposição consome o caixa do Tesouro (90 Ligas, 25 Componentes, 16 Metal Bruto por
                caminhão). <b>Se o Tesouro secar, a prateleira não se repõe</b> — e ninguém compra
                caminhão.
            </p>

            <table style="margin-top:12px">
                <tr><th>Volume de veículos</th><th class="num">Total</th><th class="num">7 dias</th></tr>
                <tr><td>Registrados (placas vivas)</td><td class="num" data-registrados>{{ $volumeVeiculos['registrados'] }}</td><td class="num">—</td></tr>
                <tr><td>Em rota agora</td><td class="num">{{ $volumeVeiculos['em_rota'] }}</td><td class="num">—</td></tr>
                <tr><td>Anunciados no mercado de usados</td><td class="num">{{ $volumeVeiculos['anunciados'] }}</td><td class="num">—</td></tr>
                <tr><td>Vendidos entre colonos</td><td class="num">{{ $volumeVeiculos['vendidos'] }}</td><td class="num">{{ $volumeVeiculos['vendidos_7d'] }}</td></tr>
                <tr><td>Sucateados</td><td class="num">{{ $volumeVeiculos['sucateados'] }}</td><td class="num">{{ $volumeVeiculos['sucateados_7d'] }}</td></tr>
            </table>

            <form method="POST" action="{{ route('admin.transporte') }}" class="linha-form">
                @csrf
                <div style="flex:0">
                    <label>Desgaste (bps/h)</label>
                    <input type="number" min="0" max="1000" name="desgaste_bps_por_hora"
                           value="{{ $transporte->desgaste_bps_por_hora }}" required>
                </div>
                <div style="flex:0">
                    <label>Piso de desempenho (bps)</label>
                    <input type="number" min="0" max="10000" name="piso_desempenho_bps"
                           value="{{ $transporte->piso_desempenho_bps }}" required>
                </div>
                <div style="flex:0">
                    <label>Manutenção (bps do custo)</label>
                    <input type="number" min="0" max="10000" name="manutencao_bps_do_custo"
                           value="{{ $transporte->manutencao_bps_do_custo }}" required>
                </div>
                <div style="flex:0">
                    <label>Perda de teto (bps)</label>
                    <input type="number" min="0" max="10000" name="perda_de_teto_bps"
                           value="{{ $transporte->perda_de_teto_bps }}" required>
                </div>
                <div style="flex:0">
                    <label>Referência do Furgão (micro-F$)</label>
                    <input type="number" min="1" name="furgao_preco_referencia_micro"
                           value="{{ $transporte->furgao_preco_referencia_micro }}" required>
                </div>
                <div style="flex:0">
                    <label>Frete: base (micro-F$)</label>
                    <input type="number" min="0" name="frete_base_micro"
                           value="{{ $transporte->frete_base_micro }}" required>
                </div>
                <div style="flex:0">
                    <label>Frete: por slot (micro-F$)</label>
                    <input type="number" min="0" name="frete_por_slot_micro"
                           value="{{ $transporte->frete_por_slot_micro }}" required>
                </div>
                <div style="flex:0"><button>Salvar</button></div>
            </form>
            <p class="mut pequeno">
                Em bps (10.000 = 100%). Hoje: <b>{{ $transporte->desgaste_bps_por_hora / 100 }}%</b> de
                desgaste por hora de uso ativo; piso de <b>{{ $transporte->piso_desempenho_bps / 100 }}%</b>
                — abaixo dele o veículo <b>não trava</b>, só continua ruim (contradição deliberada ao
                §16.4, D-60); manutenção a <b>{{ $transporte->manutencao_bps_do_custo / 100 }}%</b> do
                custo do veículo; e o teto de conservação cai
                <b>{{ $transporte->perda_de_teto_bps / 100 }} pontos</b> a cada manutenção.
            </p>
            <p class="mut pequeno">
                A <b>referência do Furgão</b>
                (hoje <b>{{ number_format($transporte->furgao_preco_referencia_micro / 1000000, 2, ',', '.') }} Fert$</b>)
                é a âncora do teto de revenda dele no mercado de usados — teto = referência × conservação
                (D-73). <b>Não é preço de venda</b>: o Ministério continua não vendendo Furgão. Antes dela,
                um Furgão sucateado anunciado por 5.000 Fert$ movia dinheiro limpo entre duas contas do
                mesmo jogador, sem carga e sem tributo. O Caminhão usa o preço de fábrica (300 Fert$).
            </p>
        </div>
    @endif

    {{-- ── A Garagem do Governo (§07; D-76): a frota real do frete público ── --}}
    @if ($aba === "garagem")
        <h2 class="secao">Garagem do Governo — frete público</h2>
        <div class="cartao">
            <p class="mut pequeno">
                O serviço logístico público do §07: o governo busca o lote na doca do Mercado e o leva à
                colônia — <b>com tributo na chegada, como toda entrega física</b> (D-32). Preço:
                <b>{{ number_format($transporte->frete_base_micro / 1000000, 2, ',', '.') }} F$</b> +
                <b>{{ number_format($transporte->frete_por_slot_micro / 1000000, 2, ',', '.') }} F$/slot</b>
                (aba Ministério dos Transportes), direto ao Tesouro. A frota é REAL: caminhão ocupado é frete recusado.
            </p>

            <table style="margin-top:8px">
                <tr><th>Placa</th><th>Situação</th><th>Chega/volta</th></tr>
                @forelse ($garagem as $c)
                    <tr data-garagem="{{ $c->id }}">
                        <td><code>{{ $c->plate }}</code></td>
                        <td>
                            @if ($c->status === 'ocioso') livre, na Capital
                            @elseif ($c->leg === 'ida') <b>em frete</b> → colônia #{{ $c->destination_id }}
                            @else voltando à Garagem
                            @endif
                        </td>
                        <td>{{ $c->arrives_at?->format('d/m H:i') ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="mut">A Garagem está vazia — rode o GaragemSeeder ou encomende abaixo.</td></tr>
                @endforelse
            </table>

            <form method="POST" action="{{ route('admin.garagem') }}" class="linha-form" style="margin-top:10px">
                @csrf
                <div style="flex:0">
                    <button data-encomendar-garagem>Encomendar +1 caminhão para a Garagem</button>
                </div>
                <span class="mut pequeno">Livres agora: <b data-garagem-livres>{{ $garagemLivres }}</b> de {{ $garagem->count() }}.
                A frota inicial são 10 (arbitragem do usuário, D-76); expanda conforme a demanda.</span>
            </form>
        </div>
    @endif

    {{-- ── A frota do planeta, com PLACA ──
         O painel dizia quantos veículos havia e nunca QUAIS. E a placa (§16.3) é o único identificador
         de um veículo que aparece na tela de outro jogador — logo é por ela que uma reclamação chega
         ao operador ("o FW-00007-F entregou a menos"), e era justamente por ela que não se podia
         procurar.

         Inclui os SUCATEADOS. A sucata arquiva e não apaga, de propósito: se apagasse, a placa do
         morto seria reciclada pelo próximo veículo do planeta. Um veículo fora desta lista é um
         veículo que ninguém mais consegue rastrear. --}}
    @if ($aba === "frota")
        <h2 class="secao">Frota do planeta</h2>
        <div class="cartao">
            <form method="GET" action="{{ route("admin.transportes") }}" class="linha-form">
                <input type="hidden" name="aba" value="frota">
                <input type="hidden" name="sort" value="{{ $sort }}">
                <input type="hidden" name="dir" value="{{ $dir }}">
                <div><label>Buscar placa</label>
                    <input type="text" name="placa" value="{{ $placa }}" placeholder="FW-00007-F">
                </div>
                <div><label>Buscar dono</label>
                    <input type="text" name="dono" value="{{ $dono }}" placeholder="nome da colônia">
                </div>
                <div style="flex:0"><label>&nbsp;</label><button>Buscar</button></div>
                @if ($placa !== "" || $dono !== "")
                    <div style="flex:0"><label>&nbsp;</label><a class="leve" href="{{ route("admin.transportes", ['aba' => 'frota']) }}">Limpar</a></div>
                @endif
            </form>

            <table style="margin-top:8px">
                <tr>
                    @foreach ($colunas as $chave => $rotulo)
                        <th class="{{ in_array($chave, ['conservacao', 'teto', 'manutencao', 'uso'], true) ? 'num' : '' }}">
                            <a href="{{ $ordenarPor($chave) }}" style="color:inherit;text-decoration:none">
                                {{ $rotulo }}{{ $sort === $chave ? ($dir === 'asc' ? ' ▲' : ' ▼') : '' }}
                            </a>
                        </th>
                    @endforeach
                </tr>
                @forelse ($veiculos as $v)
                    <tr @if ($v->trashed()) style="opacity:.5" @endif>
                        <td><b>{{ $v->plate ?? "— sem placa —" }}</b></td>
                        <td>{{ str_replace("_", " ", $v->type) }} n{{ $v->level }}</td>
                        <td>
                            {{-- Sem dono é a Frota Governamental do §16.2: o caminhão que o Ministério
                                 fabricou e ainda não vendeu. --}}
                            {{ $v->colony?->name ?? "Frota Governamental" }}
                        </td>
                        <td>
                            @if ($v->trashed())
                                <span class="mut">sucateado {{ $v->deleted_at?->format("d/m/y") }}</span>
                            @else
                                {{ $v->status }}{{ $v->local === "capital" ? " (no Pátio)" : "" }}
                            @endif
                        </td>
                        <td class="num">{{ number_format($v->conservacao_bps / 100, 1, ",", ".") }}%</td>
                        <td class="num">{{ number_format($v->teto_conservacao_bps / 100, 1, ",", ".") }}%</td>
                        <td class="num">{{ $v->manutencoes }}</td>
                        <td class="num">{{ number_format($v->uso_ativo_seg / 3600, 1, ",", ".") }} h</td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="mut pequeno">
                        @if ($placa !== "" || $dono !== "") Nenhum veículo com esses filtros. @else Nenhum veículo no planeta. @endif
                    </td></tr>
                @endforelse
            </table>

            <p class="mut pequeno" style="margin-top:6px">
                A <b>conservação</b> é o que o veículo vale hoje: velocidade e capacidade encolhem junto com
                ela, e o piso é {{ $transporte->piso_desempenho_bps / 100 }}% — nada trava. O <b>teto</b> é o
                máximo que a manutenção ainda consegue devolver, e ele cai a cada serviço.
            </p>

            <div style="margin-top:10px">{{ $veiculos->links() }}</div>
        </div>
    @endif

@endsection
