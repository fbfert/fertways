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
            ({{ \App\Domain\Marco\Curva::BASE }} × N² de XP acumulado — marco 5 =
            {{ number_format(\App\Domain\Marco\Curva::xpDoMarco(5), 0, ',', '.') }}, marco 20 =
            {{ number_format(\App\Domain\Marco\Curva::xpDoMarco(20), 0, ',', '.') }}; a base caiu de
            50 no D-223): mudá-la reescalaria o marco de todo mundo, e isso é arbitragem, não
            balanceamento. Mudanças valem para atos <b>novos</b> — o ledger de XP nunca é reescrito.
            Os gates vivos: <b>marco 10</b> fabrica Drone nível 2+; <b>marco 20</b> ocupa zona neutra
            (dobrável por evento, D-232). O Mercado <b>não</b> tem gate (contradição consciente com o
            §05 — o §03 promete o primeiro lote ao recém-chegado).
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
            <div style="flex:0">
                <label>Mercado: vezes por dia</label>
                <input type="number" min="0" max="1000" name="xp_mercado_teto_diario"
                       value="{{ $marco->xp_mercado_teto_diario }}" required>
            </div>
            <div style="flex:0"><label>&nbsp;</label><button data-salvar-marco>Salvar</button></div>
        </form>
        <p class="mut pequeno">
            Zerar um valor <b>desliga</b> aquela fonte — menos o <b>teto</b> do Mercado, onde zero
            quer dizer "sem limite". O Acordo rende acima do piso de 5 Fert$ (o anti-farm do D-43,
            revisto no D-117). O <b>Mercado não tem mais piso de valor</b> (D-241): 100% das 13.551
            execuções da produção ficavam abaixo dele, e o preço é das partes, então piso nenhum
            detém dois cúmplices. Quem detém é o teto por dia, que vale por dia de missão (07h→07h).
            ⚠️ O retroativo do <code>artisan fertways:marco --aplicar</code> usa o XP por execução e
            <b>ignora o teto</b> — ele conta vendas de todo o histórico, não de um dia.
        </p>
    </div>

    {{-- ── O kit inicial (D-85), editável pelo admin (D-92) ── --}}
    <h2 class="secao">Kit inicial</h2>
    <div class="cartao">
        <p class="mut pequeno">
            O que toda colônia nova recebe ao fundar: Fert$, um valor por recurso do catálogo, e a
            frota. <b>Só vale para quem funda DEPOIS de salvar</b> — colônias que já existem não
            são tocadas, sem backfill.
        </p>

        <form method="POST" action="{{ route('admin.kit_inicial.parametros') }}" style="margin-top:10px">
            @csrf

            <div class="linha-form">
                <div style="flex:0">
                    <label>Fert$ inicial</label>
                    <input type="number" step="0.000001" min="0" name="fert"
                           value="{{ number_format($kitSettings->fert_micro / 1000000, 6, '.', '') }}" required>
                </div>
                @foreach ($kitVeiculos as $tipo => $capacidade)
                    <div style="flex:0">
                        <label>{{ $tipo === 'caminhao_de_carga' ? 'Caminhões de Carga' : 'Furgões de Comércio' }}</label>
                        <input type="number" min="0" max="255"
                               name="{{ $tipo === 'caminhao_de_carga' ? 'caminhoes' : 'furgoes' }}"
                               value="{{ $tipo === 'caminhao_de_carga' ? $kitSettings->caminhoes : $kitSettings->furgoes }}"
                               required style="width:80px">
                    </div>
                @endforeach
            </div>

            <table style="margin-top:12px">
                <tr><th>Recurso</th><th class="num">Quantidade</th></tr>
                @foreach ($kitRecursos as $r)
                    <tr data-linha-kit="{{ $r->code }}">
                        <td>
                            {{ $r->nome }}
                            @if ($r->code === 'niobio_alienigena')
                                <span class="mut pequeno" style="color:#8a6d00">
                                    — {{ $kitMuroNiobio }}+ reabre Torre de Defesa + Quartel sem negociar (D-85)
                                </span>
                            @elseif ($r->code === 'quartzo_piezoeletrico')
                                <span class="mut pequeno" style="color:#8a6d00">
                                    — {{ $kitMuroQuartzo }}+ reabre Refinaria Química + Antena de Comunicação sem negociar (D-85)
                                </span>
                            @endif
                        </td>
                        <td class="num">
                            <input type="number" min="0" name="recursos[{{ $r->code }}]"
                                   value="{{ $r->amount }}" style="width:100px;text-align:right">
                        </td>
                    </tr>
                @endforeach
            </table>

            <div style="margin-top:10px"><button>Salvar kit inicial</button></div>
        </form>
    </div>

    {{--
        ── D-258: três tabelas de parâmetros que o jogo dizia "do painel" e que não tinham tela ──
        Os números, sim. As CHAVES de liga/desliga, não: ligar a População ou o teto de estoque
        exige um passo antes (a migração de colonos, o grandfather do estoque), e por isso têm
        comando próprio. Aqui elas aparecem só para leitura.
    --}}
    @php
        $chave = fn ($ligada) => $ligada
            ? '<b style="color:#2f5e1e">ligada</b>'
            : '<b style="color:#8a2f08">desligada</b>';
        $operadoresZona = collect(json_decode($populacao->zona_operadores_por_nivel ?? '{}', true) ?: [])
            ->map(fn ($q, $n) => "{$n}:{$q}")->implode("\n");
    @endphp

    <h2 class="secao">População</h2>
    <div class="cartao" data-parametros-populacao>
        <p class="mut pequeno">
            A regra está {!! $chave($populacao->ativo) !!}. O teto é a Estrutura de Sobrevivência:
            base no nível 1, multiplicada pelo fator a cada nível (§13.1 do GDD). O fator não é a
            curva de custo do jogo (D-239) — e abaixo de 1× o teto encolheria a cada nível, e o
            formulário recusa.
        </p>
        <form method="POST" action="{{ route('admin.populacao.parametros') }}" style="margin-top:8px">
            @csrf
            <div class="linha-form">
                @foreach ([
                    'capacidade_base' => 'Teto no nível 1 (colonos)',
                    'capacidade_fator_milesimos' => 'Fator por nível (milésimos)',
                    'crescimento_bps_hora' => 'Crescimento (bps/h)',
                    'crescimento_min_suprimento_bps' => 'Suprimento mínimo p/ crescer (bps)',
                    'escassez_eficiencia_bps' => 'Piso de eficiência na escassez (bps)',
                ] as $campo => $rotulo)
                    <div style="flex:0">
                        <label>{{ $rotulo }}</label>
                        <input type="number" min="0" name="{{ $campo }}" value="{{ $populacao->{$campo} }}" required style="width:110px">
                    </div>
                @endforeach
            </div>
            <div class="linha-form" style="margin-top:8px">
                @foreach ([
                    'agua_milli_por_colono_hora' => 'Água',
                    'oxigenio_milli_por_colono_hora' => 'Oxigênio',
                    'biomassa_milli_por_colono_hora' => 'Biomassa',
                    'energia_milli_por_colono_hora' => 'Energia',
                ] as $campo => $rotulo)
                    <div style="flex:0">
                        <label>{{ $rotulo }} por colono/h (milésimos)</label>
                        <input type="number" min="0" name="{{ $campo }}" value="{{ $populacao->{$campo} }}" required style="width:110px">
                    </div>
                @endforeach
                <div style="flex:0">
                    <label>Operadores por nível de zona (nível:quantidade)</label>
                    <textarea name="zona_operadores_por_nivel" rows="4" style="width:140px">{{ $operadoresZona }}</textarea>
                </div>
            </div>
            <div style="margin-top:10px"><button data-salvar-populacao>Salvar população</button></div>
        </form>
    </div>

    <h2 class="secao">Teto de estoque</h2>
    <div class="cartao" data-parametros-estoque>
        <p class="mut pequeno">
            O teto está {!! $chave($estoque->ativo) !!}. ⚠️ <b>Ligá-lo não é deste formulário</b>:
            antes é preciso gravar o piso pessoal de quem já passou da curva
            (<code>fertways:estoque-grandfather --aplicar</code>), ou as colônias param de produzir na
            hora (D-191, D-192). A folga só conta no próximo grandfather.
        </p>
        <form method="POST" action="{{ route('admin.estoque.parametros') }}" class="linha-form" style="margin-top:8px">
            @csrf
            <div style="flex:0">
                <label>Cabe, por recurso, no nível 1</label>
                <input type="number" min="1" name="estoque_capacidade_base" value="{{ $estoque->capacidade_base }}" required style="width:120px">
            </div>
            <div style="flex:0">
                <label>Fator por nível (milésimos)</label>
                <input type="number" min="1000" max="5000" name="estoque_capacidade_fator_milesimos" value="{{ $estoque->capacidade_fator_milesimos }}" required style="width:110px">
            </div>
            <div style="flex:0">
                <label>Folga do piso pessoal (bps)</label>
                <input type="number" min="0" max="10000" name="estoque_grandfather_folga_bps" value="{{ $estoque->grandfather_folga_bps }}" required style="width:110px">
            </div>
            <div style="flex:0"><label>&nbsp;</label><button data-salvar-estoque>Salvar</button></div>
        </form>
    </div>

    <h2 class="secao">Vagas de pesquisa</h2>
    <div class="cartao" data-parametros-pesquisa>
        <p class="mut pequeno">
            A pesquisa está {!! $chave($pesquisa->ativo) !!}. O Laboratório dá a base mais uma vaga a
            cada N níveis; o Observatório soma pela ficha do v35 (D-256); o teto vale para a soma.
        </p>
        <form method="POST" action="{{ route('admin.pesquisa.parametros') }}" class="linha-form" style="margin-top:8px">
            @csrf
            <div style="flex:0">
                <label>Vaga base do Laboratório</label>
                <input type="number" min="0" max="10" name="vagas_base" value="{{ $pesquisa->vagas_base }}" required style="width:80px">
            </div>
            <div style="flex:0">
                <label>Uma vaga a cada N níveis</label>
                <input type="number" min="1" max="10" name="vagas_por_niveis_de_laboratorio" value="{{ $pesquisa->vagas_por_niveis_de_laboratorio }}" required style="width:80px">
            </div>
            <div style="flex:0">
                <label>Teto da soma</label>
                <input type="number" min="1" max="20" name="vagas_teto" value="{{ $pesquisa->vagas_teto }}" required style="width:80px">
            </div>
            <div style="flex:0"><label>&nbsp;</label><button data-salvar-pesquisa>Salvar</button></div>
        </form>
    </div>

@endsection
