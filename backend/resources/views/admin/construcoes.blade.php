@extends("admin.layout")

@php
    // O textarea de custo edita no MESMO formato "recurso:quantidade" que `missoes.blade.php`
    // já usa pra recompensa — um parser só, uma convenção só.
    $paraTexto = fn (?array $recursos) => collect($recursos ?? [])
        ->map(fn ($q, $r) => "{$r}:{$q}")->implode("\n");
    $abas = [
        "tempo" => "Tempo de Construções",
        "custo" => "Custo de Construção e Evolução",
        "silo" => "Gestão do Silo",
        "fila" => "Fila",
        "manutencao" => "Manutenção",
    ];
@endphp

@section("content")

    <nav class="abas" style="background:transparent;padding:0;margin-bottom:16px">
        @foreach ($abas as $slug => $rotulo)
            <a href="{{ route('admin.construcoes', ['aba' => $slug]) }}"
               data-aba-construcoes="{{ $slug }}"
               style="color:{{ $aba === $slug ? 'var(--rust)' : 'var(--ink-soft)' }};
                      background:{{ $aba === $slug ? 'var(--sand-light)' : 'transparent' }};
                      border:1px solid rgba(180,69,11,.2)">
                {{ $rotulo }}
            </a>
        @endforeach
    </nav>

    {{-- ─────────────────────────────────────────────── Tempo / Custo ── --}}
    @if ($aba === 'tempo' || $aba === 'custo')
        <p class="mut pequeno">
            @if ($aba === 'tempo')
                Quanto tempo cada construção leva para erguer/evoluir, por nível — o valor do GDD
                aparece ao lado, e o campo já vem preenchido com o ajuste salvo (ou o do GDD, se
                nunca foi ajustado). <b>O nível 1 das que já nascem prontas</b> (as cinco essenciais
                e o Depósito Local/Silo) <b>não tem o que ajustar</b> — não fica na lista.
            @else
                O custo de cada construção, por nível — uma linha <code>recurso:quantidade</code>
                por textarea, mesmo formato das recompensas de missão. O nível 1 das que já nascem
                prontas não entra, pelo mesmo motivo.
            @endif
        </p>

        @foreach ($grupos as $titulo => $itens)
            <h2 class="secao">{{ $titulo }}</h2>
            <div class="cartao">
                <table>
                    <tr><th>Construção</th><th class="num">Níveis</th><th></th></tr>
                    @foreach ($itens as $c)
                        @php $domId = "editar-{$aba}-{$c['tipo']}"; @endphp
                        <tr data-construcao="{{ $c['tipo'] }}">
                            <td class="pequeno"><b>{{ $c['nome'] }}</b></td>
                            <td class="num">{{ count($c['niveis']) }}</td>
                            <td>
                                <button class="leve" type="button"
                                        onclick="document.getElementById('{{ $domId }}').style.display =
                                                 document.getElementById('{{ $domId }}').style.display === 'none' ? 'block' : 'none'">
                                    Editar
                                </button>
                            </td>
                        </tr>
                        <tr>
                            <td colspan="3" style="padding:0">
                                <form id="{{ $domId }}"
                                      method="POST"
                                      action="{{ route($aba === 'tempo' ? 'admin.construcoes.tempo' : 'admin.construcoes.custo') }}"
                                      style="display:none;margin:4px 0 10px;padding:8px;background:rgba(180,69,11,.04)">
                                    @csrf
                                    <input type="hidden" name="building_type" value="{{ $c['tipo'] }}">

                                    @foreach ($c['niveis'] as $n)
                                        @if ($n['nivel'] === 1 && in_array($c['tipo'], $naoConstroi, true))
                                            @continue
                                        @endif

                                        <div class="linha-form" style="margin-top:6px;align-items:flex-start">
                                            <div style="flex:0;width:70px">
                                                <label class="pequeno mut">Nível {{ $n['nivel'] }}</label>
                                            </div>

                                            @if ($aba === 'tempo')
                                                <div style="flex:0;white-space:nowrap">
                                                    <input type="number" min="1" max="100000"
                                                           name="niveis[{{ $n['nivel'] }}]"
                                                           value="{{ $n['tempo_override_min'] ?? $n['tempo_base_min'] }}"
                                                           style="width:90px" required> min
                                                </div>
                                                <div class="mut pequeno">GDD: {{ $n['tempo_base_min'] ?? '—' }} min</div>
                                            @else
                                                <div style="flex:1">
                                                    <textarea name="niveis[{{ $n['nivel'] }}]" rows="2"
                                                              style="width:100%">{{ $paraTexto($n['custo_override'] ?? $n['custo_base']) }}</textarea>
                                                </div>
                                                <div class="mut pequeno" style="flex:1">
                                                    GDD: {{ $paraTexto($n['custo_base']) }}
                                                </div>
                                            @endif
                                        </div>
                                    @endforeach

                                    <div style="margin-top:8px">
                                        <button data-salvar-construcao="{{ $c['tipo'] }}:{{ $aba }}">Salvar</button>
                                    </div>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </table>
            </div>
        @endforeach
    @endif

    {{-- ─────────────────────────────────────────────── Silo ── --}}
    @if ($aba === 'silo')
        <p class="mut pequeno">
            Quanto de cada recurso fica <b>protegido</b> na colônia, por nível do Silo (o Depósito
            Local, estendido — D-107). O que exceder a capacidade fica <b>exposto</b> — hoje isto é
            só a regra e o dado: o saque em si (outro jogador levando o excedente) é uma entrega
            futura, ainda não construída.
        </p>
        <div class="cartao">
            <form method="POST" action="{{ route('admin.construcoes.silo') }}">
                @csrf
                <div style="overflow-x:auto">
                    <table>
                        <tr>
                            <th>Recurso</th>
                            @foreach ($niveisSilo as $nv)
                                <th class="num">Nível {{ $nv }}</th>
                            @endforeach
                        </tr>
                        @foreach ($recursos as $r)
                            <tr data-linha-silo="{{ $r->code }}">
                                <td class="pequeno">{{ $r->nome }}</td>
                                @foreach ($niveisSilo as $nv)
                                    @php
                                        $linha = ($capacidades[$r->code] ?? collect())->firstWhere('level', $nv);
                                    @endphp
                                    <td class="num">
                                        <input type="number" min="0"
                                               name="capacidades[{{ $r->code }}][{{ $nv }}]"
                                               value="{{ $linha->capacidade ?? 10000 }}"
                                               style="width:80px;text-align:right">
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </table>
                </div>
                <div style="margin-top:10px"><button>Salvar capacidades do Silo</button></div>
            </form>
        </div>
    @endif

    {{-- ─────────────────────────────────────────────── Fila ── --}}
    @if ($aba === 'fila')
        <p class="mut pequeno">
            Quantos itens cabem na fila de construção — da colônia (D-13) e da zona neutra (D-67).
            Não compartilham a mesma fila.
        </p>

        <div class="cartao" style="margin-top:12px">
            <h3 style="margin:0 0 6px">Colônia</h3>
            <p class="mut pequeno">
                O onboarding continua valendo: fila dupla nos 5 primeiros dias completos de conta,
                fila única depois — só os NÚMEROS de cada uma são do operador agora.
            </p>
            <form method="POST" action="{{ route('admin.construcoes.fila') }}" class="linha-form">
                @csrf
                <div style="flex:0">
                    <label>Vagas — conta nova (5 primeiros dias)</label>
                    <input type="number" min="1" max="20" name="colonia_vagas_novato"
                           value="{{ $fila->colonia_vagas_novato }}" required style="width:90px">
                </div>
                <div style="flex:0">
                    <label>Vagas — conta padrão</label>
                    <input type="number" min="1" max="20" name="colonia_vagas_padrao"
                           value="{{ $fila->colonia_vagas_padrao }}" required style="width:90px">
                </div>
                <input type="hidden" name="zona_vagas" value="{{ $fila->zona_vagas }}">
                <div style="flex:0"><button>Salvar</button></div>
            </form>
        </div>

        <div class="cartao" style="margin-top:12px">
            <h3 style="margin:0 0 6px">Zona neutra</h3>
            <p class="mut pequeno">
                Quantas obras a zona comporta em curso ao mesmo tempo. Hoje (padrão 1) é o
                comportamento de sempre — "uma obra por vez" (D-67). A zona não tem "esperando o
                antecessor terminar" como a colônia: cada obra só nasce quando o canteiro já tem o
                material, então até este teto, todas as que tiverem material começam na hora.
            </p>
            <form method="POST" action="{{ route('admin.construcoes.fila') }}" class="linha-form">
                @csrf
                <input type="hidden" name="colonia_vagas_novato" value="{{ $fila->colonia_vagas_novato }}">
                <input type="hidden" name="colonia_vagas_padrao" value="{{ $fila->colonia_vagas_padrao }}">
                <div style="flex:0">
                    <label>Obras simultâneas por zona</label>
                    <input type="number" min="1" max="20" name="zona_vagas"
                           value="{{ $fila->zona_vagas }}" required style="width:90px">
                </div>
                <div style="flex:0"><button>Salvar</button></div>
            </form>
        </div>
    @endif

    {{-- ─────────────────────────────────────────────── Manutenção ── --}}
    @if ($aba === 'manutencao')
        <p class="mut pequeno">
            Consumo extra de recursos por hora, por construção — <b>por cima</b> da energia do GDD,
            nunca no lugar dela. Vazio por padrão: nenhuma construção consome nada além de energia
            até você configurar aqui. Só recursos primários e industriais entram — raros ficam de
            fora.
        </p>

        @foreach ($gruposManutencao as $titulo => $itens)
            <h2 class="secao">{{ $titulo }}</h2>
            <div class="cartao">
                <table>
                    <tr><th>Construção</th><th class="num">Recursos configurados</th><th></th></tr>
                    @foreach ($itens as $c)
                        @php $domId = "editar-manutencao-{$c['tipo']}"; @endphp
                        <tr data-construcao="{{ $c['tipo'] }}">
                            <td class="pequeno"><b>{{ $c['nome'] }}</b></td>
                            <td class="num">{{ count($c['recursos']) }}</td>
                            <td>
                                <button class="leve" type="button"
                                        onclick="document.getElementById('{{ $domId }}').style.display =
                                                 document.getElementById('{{ $domId }}').style.display === 'none' ? 'block' : 'none'">
                                    Editar
                                </button>
                            </td>
                        </tr>
                        <tr>
                            <td colspan="3" style="padding:0">
                                <form id="{{ $domId }}"
                                      method="POST"
                                      action="{{ route('admin.construcoes.manutencao') }}"
                                      style="display:none;margin:4px 0 10px;padding:8px;background:rgba(180,69,11,.04)">
                                    @csrf
                                    <input type="hidden" name="building_type" value="{{ $c['tipo'] }}">
                                    <div style="flex:1">
                                        <textarea name="recursos" rows="3"
                                                  style="width:100%">{{ $paraTexto($c['recursos']) }}</textarea>
                                    </div>
                                    <div class="mut pequeno" style="margin-top:4px">
                                        Uma linha <code>recurso:quantidade</code> por linha — apagar
                                        uma linha remove aquele consumo ao salvar. Recursos
                                        aceitos:
                                        {{ $recursosManutencao->pluck('code')->implode(', ') }}.
                                    </div>
                                    <div style="margin-top:8px">
                                        <button data-salvar-construcao="{{ $c['tipo'] }}:manutencao">Salvar</button>
                                    </div>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </table>
            </div>
        @endforeach
    @endif

@endsection
