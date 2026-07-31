@extends("admin.layout")

@php
    $fert = fn ($micro) => number_format(((int) $micro) / 1000000, 2, ",", ".");
    $quando = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format("d/m H:i") : "—";
    // O textarea de recursos edita no MESMO formato "recurso:quantidade" que ele lê ao salvar.
    $paraTexto = fn (?array $recursos) => collect($recursos ?? [])
        ->map(fn ($q, $r) => "{$r}:{$q}")->implode("\n");
    $abas = [
        "catalogo" => "Missões Catálogo",
        "criar" => "Criar um Molde",
        "baralho" => "O Baralho",
    ];
@endphp

@section("content")

    <nav class="abas" style="background:transparent;padding:0;margin-bottom:16px">
        @foreach ($abas as $slug => $rotulo)
            <a href="{{ route('admin.missoes', ['aba' => $slug]) }}"
               data-aba-missoes="{{ $slug }}"
               style="color:{{ $aba === $slug ? 'var(--rust)' : 'var(--ink-soft)' }};
                      background:{{ $aba === $slug ? 'var(--sand-light)' : 'transparent' }};
                      border:1px solid rgba(180,69,11,.2)">
                {{ $rotulo }}
            </a>
        @endforeach
    </nav>

    {{-- ─────────────────────────────────────────────── Missões Catálogo (visão geral) ── --}}
    @if ($aba === "catalogo")
        <h2 class="secao">Missões — o catálogo (§06)</h2>
        <div class="cartao">
            <p class="mut pequeno">
                Tutoria (5, dias 1–3), diárias (3/dia de um pool, com a 1 rejeição do §06), semanais
                (qua 07h → ter 23h59) e eventuais (fora do ciclo — evento, sazonal, sem sorteio
                automático). <b>Recompensa de missão é EMISSÃO</b> — o §06 a lista entre as entradas
                de Fert$, como o salário do conciliador.
            </p>
            <p class="mut pequeno">
                Por molde: quantas vezes foi sorteada e como terminou — é o que diz se um molde
                funciona (concluída na maioria) ou é ignorado (fica vencendo sem ninguém tocar).
            </p>
            <table style="margin-top:8px">
                <tr>
                    <th>Molde</th><th>Categoria</th><th class="num">Sorteada</th>
                    <th class="num">Concluída</th><th class="num">Rejeitada</th>
                    <th class="num">Ativa (vigente)</th><th class="num">Ativa (vencida)</th>
                    <th>Última vez</th>
                </tr>
                @forelse ($catalogo as $linha)
                    @php $t = $linha['template']; @endphp
                    <tr data-catalogo-molde="{{ $t->chave }}" @if(!$t->ativa) style="opacity:.45" @endif>
                        <td class="pequeno">
                            <b>{{ $t->titulo }}</b>
                            <div class="mut" style="font-size:.58rem">{{ $t->chave }}{{ $t->ativa ? '' : ' · desligada' }}</div>
                        </td>
                        <td class="pequeno">
                            {{ $nomeCategoria[$t->categoria] ?? $t->categoria }}
                            @if ($t->obrigatoria)
                                {{-- A2.1: a etapa que o colono não pode pular. --}}
                                <strong style="color:var(--rust)" title="Obrigatória: não pode ser pulada">· obrigatória</strong>
                            @endif
                        </td>
                        <td class="num">{{ $linha['sorteada'] }}</td>
                        <td class="num">{{ $linha['concluida'] }}</td>
                        <td class="num">{{ $linha['rejeitada'] }}</td>
                        <td class="num">{{ $linha['ativa_vigente'] }}</td>
                        <td class="num">{{ $linha['ativa_vencida'] }}</td>
                        <td class="mut pequeno">{{ $quando($linha['ultima_sorteada']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="mut pequeno">Nenhum molde no catálogo ainda — crie um na aba «Criar um Molde».</td></tr>
                @endforelse
            </table>
        </div>
    @endif

    {{-- ─────────────────────────────────────────────── Criar um Molde ── --}}
    @if ($aba === "criar")
        <h2 class="secao">Criar um molde</h2>
        <div class="cartao">
            <p class="mut pequeno">
                ⚠️ <b>Editar <code>ação</code> e <code>meta</code> só vale para missões sorteadas daqui
                em diante</b> — quem já está com a missão na mão guarda o que tinha quando foi sorteada.
                <b>O prêmio é diferente, de propósito:</b> editar Fert$/XP/recursos vale também para
                quem já tem a missão na mão e ainda não completou — é o torniquete valendo hoje, não só
                amanhã.
            </p>
            <form method="POST" action="{{ route('admin.missao.criar') }}">
                @csrf
                <div class="linha-form">
                    <div style="flex:0">
                        <label>Chave (única, sem espaço)</label>
                        <input type="text" name="chave" maxlength="40" placeholder="dia_exemplo_1" required
                               pattern="[A-Za-z0-9_-]+" style="width:170px">
                    </div>
                    <div style="flex:0">
                        <label>Categoria</label>
                        <select name="categoria" required>
                            @foreach ($nomeCategoria as $v => $r)
                                <option value="{{ $v }}">{{ $r }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label>Título</label>
                        <input type="text" name="titulo" maxlength="80" required>
                    </div>
                </div>
                <div style="margin-top:8px">
                    <label class="pequeno mut">Descrição (o que o colono lê na tela)</label>
                    <input type="text" name="descricao" maxlength="200" required style="width:100%">
                </div>
                <div class="linha-form" style="margin-top:8px">
                    <div>
                        <label>Ação escutada</label>
                        <select name="acao" required style="width:100%">
                            @foreach ($acoes as $chave => $rotulo)
                                <option value="{{ $chave }}">{{ $rotulo }} ({{ $chave }})</option>
                            @endforeach
                        </select>
                    </div>
                    <div style="flex:0">
                        <label>Meta (quantas vezes)</label>
                        <input type="number" name="meta" min="1" max="999" value="1" required style="width:90px">
                    </div>
                </div>
                <div class="linha-form" style="margin-top:8px">
                    <div>
                        <label>Requer (só narrativa — vazio = 1º capítulo, ou sem cadeia)</label>
                        <label style="display:flex;gap:6px;align-items:center;font-weight:normal">
                            <input type="checkbox" name="obrigatoria" value="1">
                            Obrigatória (não pode ser pulada)
                        </label>
                        <p class="mut pequeno" style="margin:4px 0 8px">
                            ⚠️ Só faz sentido na <strong>tutoria</strong>. E só marque etapa que o
                            colono conclua <strong>sozinho</strong>: num servidor recém-aberto não há
                            contraparte, e uma etapa obrigatória que dependa de outro jogador prende
                            o iniciante numa porta que não depende dele.
                        </p>
                        <select name="requer_template_id" style="width:100%">
                            <option value="">— nenhum —</option>
                            @foreach ($capitulosNarrativos as $c)
                                <option value="{{ $c->id }}">{{ $c->titulo }} ({{ $c->chave }})</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="linha-form" style="margin-top:8px">
                    <div style="flex:0">
                        <label>Recompensa em Fert$</label>
                        <input type="number" step="0.01" min="0" max="1000" name="recompensa_fert" value="0" style="width:110px">
                    </div>
                    <div style="flex:0">
                        <label>Recompensa em XP</label>
                        <input type="number" min="0" max="100000" name="recompensa_xp" value="0" style="width:110px">
                    </div>
                    <div>
                        <label>Recompensa em recursos — uma linha <code>recurso:quantidade</code></label>
                        <textarea name="recompensa_recursos" rows="2" placeholder="ligas_metalicas:200" style="width:100%"></textarea>
                    </div>
                </div>
                <div style="margin-top:8px"><button data-criar-missao>Criar molde</button></div>
            </form>
        </div>
    @endif

    {{-- ─────────────────────────────────────────────── O Baralho, por categoria ── --}}
    @if ($aba === "baralho")
        <h2 class="secao">O baralho</h2>

        <nav class="abas" style="background:transparent;padding:0;margin-bottom:16px">
            @foreach ($nomeCategoria as $slug => $rotulo)
                <a href="{{ route('admin.missoes', ['aba' => 'baralho', 'cat' => $slug]) }}"
                   data-subaba-baralho="{{ $slug }}"
                   style="color:{{ $catAtual === $slug ? 'var(--rust)' : 'var(--ink-soft)' }};
                          background:{{ $catAtual === $slug ? 'var(--sand-light)' : 'transparent' }};
                          border:1px solid rgba(180,69,11,.2)">
                    {{ $rotulo }}
                </a>
            @endforeach
        </nav>

        <div class="cartao">
            <b class="pequeno">{{ $nomeCategoria[$catAtual] ?? $catAtual }} ({{ $missoes->count() }})</b>
            <table style="margin-top:6px">
                <tr>
                    <th>Molde</th><th>Ação</th><th class="num">Meta</th><th>Paga</th>
                    <th class="num">Sorteada</th><th></th>
                </tr>
                @forelse ($missoes as $m)
                    <tr data-molde="{{ $m->chave }}" @if(!$m->ativa) style="opacity:.45" @endif>
                        <td class="pequeno">
                            <b>{{ $m->titulo }}</b>
                            <div class="mut" style="font-size:.58rem">{{ $m->chave }}</div>
                        </td>
                        <td class="pequeno">{{ $acoes[$m->acao] ?? $m->acao }}</td>
                        <td class="num">{{ $m->meta }}</td>
                        <td class="pequeno">
                            @if ($m->recompensa_fert_micro > 0) {{ $fert($m->recompensa_fert_micro) }} F$ @endif
                            @if ($m->recompensa_xp > 0) {{ $m->recompensa_xp }} XP @endif
                            @foreach ($m->recompensa_recursos ?? [] as $r => $q) {{ $q }} {{ str_replace('_', ' ', $r) }} @endforeach
                        </td>
                        <td class="num">{{ $m->assignments_count }}×</td>
                        <td>
                            <div class="linha-form" style="gap:6px">
                                <button class="leve" type="button"
                                        onclick="document.getElementById('editar-{{ $m->id }}').style.display =
                                                 document.getElementById('editar-{{ $m->id }}').style.display === 'none' ? 'block' : 'none'">
                                    Editar
                                </button>
                                <form method="POST" action="{{ route('admin.missao.alternar', $m) }}" class="inline">
                                    @csrf
                                    <button class="leve" data-alternar="{{ $m->chave }}">{{ $m->ativa ? 'Desligar' : 'Ligar' }}</button>
                                </form>
                                @if ($m->assignments_count === 0)
                                    <form method="POST" action="{{ route('admin.missao.apagar', $m) }}" class="inline"
                                          onsubmit="return confirm('Apagar «{{ $m->titulo }}»? Nunca foi sorteada, então não há histórico a perder.')">
                                        @csrf<button class="perigo leve">Apagar</button>
                                    </form>
                                @else
                                    <span class="mut pequeno" title="Já foi sorteada — apagar destruiria o histórico. Desligue em vez disso.">
                                        (sorteada: só desligar)
                                    </span>
                                @endif
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <td colspan="6" style="padding:0">
                            <form id="editar-{{ $m->id }}" method="POST" action="{{ route('admin.missao.editar', $m) }}"
                                  style="display:none;margin:4px 0 10px;padding:8px;background:rgba(180,69,11,.04)">
                                @csrf
                                <div class="linha-form">
                                    <div style="flex:0">
                                        <label>Chave</label>
                                        <input type="text" name="chave" value="{{ $m->chave }}" maxlength="40" required style="width:170px">
                                    </div>
                                    <div style="flex:0">
                                        <label>Categoria</label>
                                        <select name="categoria" required>
                                            @foreach ($nomeCategoria as $v => $r)
                                                <option value="{{ $v }}" @selected($m->categoria === $v)>{{ $r }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div>
                                        <label>Título</label>
                                        <input type="text" name="titulo" value="{{ $m->titulo }}" maxlength="80" required>
                                    </div>
                                </div>
                                <div style="margin-top:8px">
                                    <label class="pequeno mut">Descrição</label>
                                    <input type="text" name="descricao" value="{{ $m->descricao }}" maxlength="200" required style="width:100%">
                                </div>
                                <div class="linha-form" style="margin-top:8px">
                                    <div>
                                        <label>Ação escutada</label>
                                        <select name="acao" required style="width:100%">
                                            @foreach ($acoes as $chave => $rotulo)
                                                <option value="{{ $chave }}" @selected($m->acao === $chave)>{{ $rotulo }} ({{ $chave }})</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div style="flex:0">
                                        <label>Meta</label>
                                        <input type="number" name="meta" min="1" max="999" value="{{ $m->meta }}" required style="width:90px">
                                    </div>
                                </div>
                                <div class="linha-form" style="margin-top:8px">
                                    <div>
                                        <label>Requer (só narrativa)</label>
                                        <label style="display:flex;gap:6px;align-items:center;font-weight:normal">
                                            <input type="checkbox" name="obrigatoria" value="1" @checked($m->obrigatoria)>
                                            Obrigatória
                                        </label>
                                        <select name="requer_template_id" style="width:100%">
                                            <option value="">— nenhum —</option>
                                            @foreach ($capitulosNarrativos as $c)
                                                @if ($c->id !== $m->id)
                                                    <option value="{{ $c->id }}" @selected($m->requer_template_id === $c->id)>{{ $c->titulo }} ({{ $c->chave }})</option>
                                                @endif
                                            @endforeach
                                        </select>
                                    </div>
                                </div>
                                <div class="linha-form" style="margin-top:8px">
                                    <div style="flex:0">
                                        <label>Fert$</label>
                                        <input type="number" step="0.01" min="0" max="1000" name="recompensa_fert"
                                               value="{{ $m->recompensa_fert_micro / 1000000 }}" style="width:110px">
                                    </div>
                                    <div style="flex:0">
                                        <label>XP</label>
                                        <input type="number" min="0" max="100000" name="recompensa_xp"
                                               value="{{ $m->recompensa_xp }}" style="width:110px">
                                    </div>
                                    <div>
                                        <label>Recursos — <code>recurso:quantidade</code> por linha</label>
                                        <textarea name="recompensa_recursos" rows="2" style="width:100%">{{ $paraTexto($m->recompensa_recursos) }}</textarea>
                                    </div>
                                </div>
                                <div style="margin-top:8px"><button data-salvar-missao="{{ $m->chave }}">Salvar</button></div>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="mut pequeno">Nenhum molde nesta categoria ainda.</td></tr>
                @endforelse
            </table>
        </div>
    @endif

@endsection
