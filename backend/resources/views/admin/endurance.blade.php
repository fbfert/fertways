@extends("admin.layout")

@php
    $fert = fn ($micro) => number_format(((int) $micro) / 1000000, 6, ",", ".");
    // O textarea de efeitos edita no MESMO formato que ele lê ao salvar — tipo_efeito:valor_bps,
    // ou tipo_efeito:alvo:valor_bps quando o tipo exige alvo (mesmo espírito do
    // "recurso:quantidade" de recompensa_recursos em Missões).
    $paraTexto = fn ($efeitos) => $efeitos->map(
        fn ($e) => $e->alvo ? "{$e->tipo_efeito}:{$e->alvo}:{$e->valor_bps}" : "{$e->tipo_efeito}:{$e->valor_bps}"
    )->implode("\n");
    $rotuloEfeito = fn ($e) => match ($e->tipo_efeito) {
        'desconto_tributo' => "Desconto de tributo: {$e->valor_bps} bps",
        'producao_bonus' => "+{$e->valor_bps} bps de produção em «{$e->alvo}»",
        'velocidade_veiculo' => "+{$e->valor_bps} bps de velocidade em «{$e->alvo}»",
        'capacidade_veiculo' => "+{$e->valor_bps} bps de capacidade em «{$e->alvo}»",
        'drone_raio' => "+{$e->valor_bps} bps de raio de drone",
        'drone_bateria' => "+{$e->valor_bps} bps de bateria de drone",
        default => "{$e->tipo_efeito}: {$e->valor_bps} bps ({$e->alvo})",
    };
@endphp

@section("content")

    <p class="mut pequeno">
        A Loja de Peças da Endurance (§05, D-135): catálogo DINÂMICO — crie, edite e apague itens à
        vontade, cada um com efeitos empilháveis de verdade (produção, veículo, drone, tributo). O
        GDD nunca publica preço, marco nem efeito — os valores abaixo são arbitragem sua.
    </p>

    <p class="mut pequeno">
        A imagem de cada item <b>não se edita aqui</b>: é por SEÇÃO (todos os itens de uma mesma
        seção compartilham a mesma arte do destroço). Para trocar, use
        <a href="{{ route('admin.imagens', ['cat' => 'destrocos-da-endurance']) }}">Imagens → Destroços da Endurance</a>.
    </p>

    <nav class="abas" style="background:transparent;padding:0;margin-bottom:16px">
        <a href="{{ route('admin.endurance', ['secao' => 'manual']) }}"
           data-aba-endurance="manual"
           style="color:{{ $secao === 'manual' ? 'var(--rust)' : 'var(--ink-soft)' }};
                  background:{{ $secao === 'manual' ? 'var(--sand-light)' : 'transparent' }};
                  border:1px solid rgba(180,69,11,.2);font-weight:600">
            📖 Manual
        </a>
        @foreach ($secoes as $slug => $rotulo)
            <a href="{{ route('admin.endurance', ['secao' => $slug]) }}"
               data-aba-endurance="{{ $slug }}"
               style="color:{{ $secao === $slug ? 'var(--rust)' : 'var(--ink-soft)' }};
                      background:{{ $secao === $slug ? 'var(--sand-light)' : 'transparent' }};
                      border:1px solid rgba(180,69,11,.2)">
                {{ $rotulo }}
            </a>
        @endforeach
    </nav>

    @if ($secao === 'manual')
        @include('admin.endurance-manual')
    @else

    <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px">
        @if ($imagemDaSecao)
            <img src="{{ $imagemDaSecao->url() }}" alt="" style="width:40px;height:40px;object-fit:contain">
        @endif
        <h2 class="secao" style="margin:0">{{ $secoes[$secao] }}</h2>
    </div>

    {{-- ─────────────────────────────────────────────── Catálogo da seção ── --}}
    <div class="cartao">
        <table style="margin-top:4px">
            <tr>
                <th>Item</th><th>Tipo</th><th class="num">Estoque</th><th class="num">Preço (Fert$)</th>
                <th class="num">Marco</th><th>Leilão</th><th>Efeitos</th><th>Posse</th><th></th>
            </tr>
            @forelse ($itens as $item)
                @php $posse = $possePorItem[$item->id] ?? null; @endphp
                <tr data-item-endurance="{{ $item->item_key }}">
                    <td class="pequeno">
                        <b>{{ $item->nome }}</b>
                        <div class="mut" style="font-size:.58rem">{{ $item->item_key }}</div>
                    </td>
                    <td class="pequeno">{{ ucfirst($item->tipo) }}</td>
                    <td class="num">{{ $item->quantidade_vendida }}/{{ $item->quantidade_total }}</td>
                    <td class="num">{{ $fert($item->preco_micro) }}</td>
                    <td class="num">{{ $item->marco_minimo ?? '—' }}</td>
                    <td class="pequeno">{{ $item->vendavel_em_leilao ? 'Sim' : 'Não' }}</td>
                    <td class="pequeno">
                        @forelse ($item->efeitos as $e)
                            <div style="font-size:.6rem">{{ $rotuloEfeito($e) }}</div>
                        @empty
                            <span class="mut">nenhum</span>
                        @endforelse
                    </td>
                    <td class="pequeno">
                        {{ $posse ? "{$posse->colonias} colônia(s), {$posse->unidades} unidade(s)" : '—' }}
                    </td>
                    <td>
                        <div class="linha-form" style="gap:6px">
                            <button class="leve" type="button"
                                    onclick="document.getElementById('editar-item-{{ $item->id }}').style.display =
                                             document.getElementById('editar-item-{{ $item->id }}').style.display === 'none' ? 'block' : 'none'">
                                Editar
                            </button>
                            @if (! $posse)
                                <form method="POST" action="{{ route('admin.endurance.item.apagar', $item) }}" class="inline"
                                      onsubmit="return confirm('Apagar «{{ $item->nome }}»? Nenhuma colônia o possui.')">
                                    @csrf<button class="perigo leve">Apagar</button>
                                </form>
                            @else
                                <span class="mut pequeno" title="Alguma colônia já possui — apagar arrancaria o efeito dela sem aviso.">
                                    (possuído: só editar)
                                </span>
                            @endif
                        </div>
                    </td>
                </tr>
                <tr>
                    <td colspan="9" style="padding:0">
                        <form id="editar-item-{{ $item->id }}" method="POST"
                              action="{{ route('admin.endurance.item.editar', $item) }}"
                              style="display:none;margin:4px 0 10px;padding:8px;background:rgba(180,69,11,.04)">
                            @csrf
                            <input type="hidden" name="secao" value="{{ $secao }}">
                            <div class="linha-form">
                                <div style="flex:0">
                                    <label>Item Id (chave)</label>
                                    <input type="text" name="item_key" value="{{ $item->item_key }}" maxlength="60" required style="width:170px">
                                </div>
                                <div style="flex:0">
                                    <label>Tipo</label>
                                    <select name="tipo" required>
                                        @foreach (\App\Models\EnduranceItem::TIPOS as $t)
                                            <option value="{{ $t }}" @selected($item->tipo === $t)>{{ ucfirst($t) }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label>Nome</label>
                                    <input type="text" name="nome" value="{{ $item->nome }}" maxlength="120" required style="width:100%">
                                </div>
                            </div>
                            <div class="linha-form" style="margin-top:8px">
                                <div style="flex:0">
                                    <label>Quantidade total (estoque do servidor)</label>
                                    <input type="number" name="quantidade_total" min="1" max="1000000"
                                           value="{{ $item->quantidade_total }}" style="width:110px">
                                </div>
                                <div style="flex:0">
                                    <label>Custo em Fert$</label>
                                    <input type="number" name="preco" min="0.000001" step="0.000001"
                                           value="{{ $item->preco_micro / 1000000 }}" style="width:130px">
                                </div>
                                <div style="flex:0">
                                    <label>Marco mínimo (vazio = sem exigência)</label>
                                    <input type="number" name="marco" min="1" max="100" value="{{ $item->marco_minimo }}" style="width:90px">
                                </div>
                                <div style="flex:0;align-self:flex-end">
                                    <label><input type="checkbox" name="vendavel_em_leilao" value="1" @checked($item->vendavel_em_leilao)>
                                        Vendável no Mercado Central em Leilões</label>
                                </div>
                            </div>
                            <div style="margin-top:8px">
                                <label class="pequeno mut">Descrição do item</label>
                                <input type="text" name="descricao" value="{{ $item->descricao }}" maxlength="2000" style="width:100%">
                            </div>
                            <div style="margin-top:8px">
                                <label class="pequeno mut">
                                    Benefícios — uma linha por efeito, <code>tipo_efeito:valor_bps</code> ou
                                    <code>tipo_efeito:alvo:valor_bps</code> (100 bps = 1%). Tipos:
                                    {{ implode(', ', $tiposEfeito) }}.
                                </label>
                                <textarea name="efeitos" rows="4" style="width:100%">{{ $paraTexto($item->efeitos) }}</textarea>
                            </div>
                            <div style="margin-top:8px"><button data-salvar-item="{{ $item->item_key }}">Salvar</button></div>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="9" class="mut pequeno">Nenhum item nesta seção ainda — crie um abaixo.</td></tr>
            @endforelse
        </table>
    </div>

    {{-- ─────────────────────────────────────────────── Criar um item ── --}}
    <h2 class="secao" style="margin-top:20px">Criar um item em {{ $secoes[$secao] }}</h2>
    <div class="cartao">
        <form method="POST" action="{{ route('admin.endurance.item.criar') }}">
            @csrf
            <input type="hidden" name="secao" value="{{ $secao }}">
            <div class="linha-form">
                <div style="flex:0">
                    <label>Item Id (chave única, sem espaço)</label>
                    <input type="text" name="item_key" maxlength="60" placeholder="reator_experimental" required
                           pattern="[A-Za-z0-9_-]+" style="width:190px">
                </div>
                <div style="flex:0">
                    <label>Tipo</label>
                    <select name="tipo" required>
                        @foreach (\App\Models\EnduranceItem::TIPOS as $t)
                            <option value="{{ $t }}">{{ ucfirst($t) }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label>Nome</label>
                    <input type="text" name="nome" maxlength="120" required style="width:100%">
                </div>
            </div>
            <div class="linha-form" style="margin-top:8px">
                <div style="flex:0">
                    <label>Quantidade total (estoque do servidor — únicos travam em 1)</label>
                    <input type="number" name="quantidade_total" min="1" max="1000000" value="1" style="width:110px">
                </div>
                <div style="flex:0">
                    <label>Custo em Fert$</label>
                    <input type="number" name="preco" min="0.000001" step="0.000001" value="1" required style="width:130px">
                </div>
                <div style="flex:0">
                    <label>Marco mínimo (vazio = sem exigência)</label>
                    <input type="number" name="marco" min="1" max="100" style="width:90px">
                </div>
                <div style="flex:0;align-self:flex-end">
                    <label><input type="checkbox" name="vendavel_em_leilao" value="1"> Vendável no Mercado Central em Leilões</label>
                </div>
            </div>
            <div style="margin-top:8px">
                <label class="pequeno mut">Descrição do item</label>
                <input type="text" name="descricao" maxlength="2000" style="width:100%">
            </div>
            <div style="margin-top:8px">
                <label class="pequeno mut">
                    Benefícios — uma linha por efeito, <code>tipo_efeito:valor_bps</code> ou
                    <code>tipo_efeito:alvo:valor_bps</code> (100 bps = 1%, ex.: <code>producao_bonus:mina_local:2000</code>
                    dá +20% de produção na Mina Local; <code>velocidade_veiculo:todos:1000</code> dá +10% de velocidade
                    em qualquer veículo). Deixe em branco para nenhum efeito. Tipos: {{ implode(', ', $tiposEfeito) }}.
                </label>
                <textarea name="efeitos" rows="3" placeholder="producao_bonus:mina_local:2000" style="width:100%"></textarea>
            </div>
            <div style="margin-top:8px"><button data-criar-item-endurance>Criar item</button></div>
        </form>
    </div>

    @endif

@endsection
