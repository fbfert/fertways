@extends("admin.layout")

@php
    $quando = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format("d/m H:i") : "—";
    $fert = fn ($micro) => number_format(((int) $micro) / 1000000, 2, ",", ".");

    /*
     * O rótulo do modificador em português, e o que o número dele SIGNIFICA — não o bps cru.
     * A lição é a mesma do preview do `artisan`: "−9500 bps" não se lê, "6.000 XP passam a 300" sim.
     */
    $rotulo = [
        "producao" => "Produção",
        "consumo" => "Consumo",
        "guerra_declaracao" => "Portão da guerra",
        "guerra_custo" => "Custo de guerra",
        "ocupacao_marco" => "Portão de território (XP)",
        "ocupacao_populacao" => "Portão de território (colonos)",
    ];

    $leitura = function ($e) use ($ocupacao) {
        // ⚠️ "só entrega cesta" tinha de ser condicional à cesta EXISTIR (D-233). Sem a guarda, um
        // evento sem modificador e sem recompensa — que a validação impede hoje, mas o banco não —
        // se anunciaria como um presente que não existe.
        if ($e->modificador === null) {
            return $e->temCesta() ? "só entrega cesta" : "não faz nada";
        }
        $mult = max(0, 10000 + (int) $e->efeito_bps);
        return match ($e->modificador) {
            "ocupacao_marco" => intdiv($ocupacao["xp_normal"] * $mult, 10000) . " XP em vez de "
                . number_format($ocupacao["xp_normal"], 0, ",", "."),
            "ocupacao_populacao" => intdiv($ocupacao["operadores_normal"] * $mult, 10000)
                . " colono(s) em vez de " . $ocupacao["operadores_normal"],
            "guerra_declaracao" => (int) $e->efeito_bps <= -10000
                ? "TRÉGUA — ninguém declara" : "não fecha o portão (só −100% fecha)",
            "guerra_custo" => "declarar custa " . ($mult / 100) . "% do normal",
            default => "uma taxa de 200/h vira " . intdiv(200 * $mult, 10000) . "/h",
        };
    };
@endphp

@section("content")

    <h2 class="secao">Motor de Eventos (A2.8)</h2>

    <div class="cartao">
        <p class="mut pequeno">
            Um evento é <b>uma linha de tabela no lugar de um <code>if</code> no tick</b>. Ele faz
            até duas coisas, e as duas obedecem a regras opostas de propósito:
        </p>
        <ul class="mut pequeno" style="margin:6px 0 0 18px">
            <li><b>O modificador</b> muda uma <b>taxa</b> e <b>nunca</b> escreve no ledger — quem
                credita continua sendo o tick.</li>
            <li><b>A cesta</b> entrega <b>uma vez</b> e <b>sempre</b> escreve no ledger, como
                <code>presente_evento</code>. É emissão do Governo: nada foi arrecadado.</li>
        </ul>
        <p class="mut pequeno" style="margin-top:8px">
            ⚠️ <b>Cancelar encerra o futuro e preserva o passado.</b> O evento para de valer dali em
            diante e continua valendo para trás — é o que deixa o «Desde sua última visita» explicar
            por que a produção caiu. <b>Cesta já entregue não volta</b>: o ledger é append-only.
        </p>
        <p class="mut pequeno">
            O <code>artisan fertways:evento</code> continua existindo e é o caminho canônico para o
            que é secreto de verdade.
        </p>
    </div>

    {{-- ── O retrato do território, que é contra o que se dosa um evento de ocupação ── --}}
    <h2 class="secao">O portão de território, agora</h2>
    <div class="cartao">
        <table>
            <tr>
                <th>Régua</th><th class="num">Normal</th><th class="num">Valendo agora</th>
            </tr>
            <tr>
                <td>XP para ocupar zona neutra (marco 20, Desbravador)</td>
                <td class="num">{{ number_format($ocupacao["xp_normal"], 0, ",", ".") }}</td>
                <td class="num" @if($ocupacao["xp_exigido"] < $ocupacao["xp_normal"]) style="color:var(--rust);font-weight:700" @endif>
                    {{ number_format($ocupacao["xp_exigido"], 0, ",", ".") }}
                </td>
            </tr>
            <tr>
                <td>Colonos livres para ocupar</td>
                <td class="num">{{ $ocupacao["operadores_normal"] }}</td>
                <td class="num" @if($ocupacao["operadores_exigidos"] < $ocupacao["operadores_normal"]) style="color:var(--rust);font-weight:700" @endif>
                    {{ $ocupacao["operadores_exigidos"] }}
                </td>
            </tr>
        </table>
        <p class="mut pequeno" style="margin-top:8px">
            <b>{{ $ocupacao["com_xp"] }}</b> de {{ $ocupacao["colonias"] }} colônias têm XP suficiente
            para o portão de hoje, e <b>{{ $ocupacao["podem"] }}</b> conseguem ocupar de fato —
            contando recurso, Fert$, colonos e teto de zonas. Há
            <b>{{ $ocupacao["zonas_livres"] }}</b> zonas neutras livres.
        </p>
        <p class="mut pequeno">
            ⚠️ A diferença entre os dois números é o que o evento <em>não</em> resolve. Baixar o
            portão do XP não dá recurso a ninguém, e isentar colonos não constrói habitação.
        </p>
    </div>

    {{-- ── Vivos ── --}}
    <h2 class="secao">Vigentes agora ({{ $vivos->count() }})</h2>
    <div class="cartao">
        @forelse ($vivos as $e)
            <div data-evento="{{ $e->slug }}" style="padding:10px 0;border-bottom:1px solid rgba(180,69,11,.12)">
                <div style="display:flex;gap:12px;align-items:baseline;flex-wrap:wrap">
                    <b>{{ $e->nome }}</b>
                    <code class="mut pequeno">{{ $e->slug }}</code>
                    @if ($e->status === "cancelado")
                        <span class="pequeno" style="color:var(--rust)">cancelado {{ $quando($e->cancelado_em) }}</span>
                    @endif
                    @if ($e->segredo || $e->visibilidade === "secreto")
                        <span class="pequeno" style="color:var(--rust)">· invisível ao jogador</span>
                    @elseif ($e->visibilidade === "parcial")
                        <span class="pequeno mut">· parcial</span>
                    @endif
                </div>
                <div class="mut pequeno" style="margin-top:3px">
                    {{ $e->modificador ? $rotulo[$e->modificador] ?? $e->modificador : "Sem modificador" }}
                    — <b>{{ $leitura($e) }}</b>
                    @if ($e->resource_type) · só {{ $e->resource_type }} @endif
                    · {{ $e->escopo === "colonia" ? "colônia " . ($e->colony?->name ?? $e->colony_id) : "MUNDO" }}
                    · até {{ $quando($e->termina_em) }}
                </div>
                @if ($e->temCesta())
                    <div class="mut pequeno" style="margin-top:3px">
                        Cesta entregue a <b>{{ $e->entregas_count }}</b> colônia(s):
                        @foreach ($e->recompensas as $r => $q)
                            {{ $r === $FERT ? $fert($q) . " Fert$" : number_format($q, 0, ",", ".") . " " . $r }}@if(!$loop->last);@endif
                        @endforeach
                    </div>
                @endif
                @if ($e->notas_internas)
                    <div class="mut pequeno" style="margin-top:3px;font-style:italic">
                        Nota interna (o jogador nunca vê): {{ $e->notas_internas }}
                    </div>
                @endif
                <div style="margin-top:6px;display:flex;gap:8px">
                    @if ($e->temCesta() && $e->status !== "cancelado")
                        <form method="POST" action="{{ route('admin.evento.entregar', $e) }}">
                            @csrf<button class="pequeno">Entregar aos que faltam</button>
                        </form>
                    @endif
                    @if ($e->cancelado_em === null)
                        <form method="POST" action="{{ route('admin.evento.cancelar', $e) }}"
                              onsubmit="return confirm('Cancelar «{{ $e->nome }}»? O passado é preservado; cesta entregue não volta.')">
                            @csrf<button class="pequeno perigo">Cancelar</button>
                        </form>
                    @endif
                </div>
            </div>
        @empty
            <p class="mut pequeno">Nenhum evento vigente. O mundo está no ritmo normal.</p>
        @endforelse
    </div>

    {{-- ── Rascunhos ── --}}
    <h2 class="secao">Rascunhos ({{ $rascunhos->count() }})</h2>
    <div class="cartao">
        <p class="mut pequeno">
            Rascunho <b>não vale nada no mundo</b>. Confira o que ele diz que vai fazer, e só então ative.
        </p>
        @forelse ($rascunhos as $e)
            <div data-rascunho="{{ $e->slug }}" style="padding:10px 0;border-bottom:1px solid rgba(180,69,11,.12)">
                <div style="display:flex;gap:12px;align-items:baseline;flex-wrap:wrap">
                    <b>{{ $e->nome }}</b><code class="mut pequeno">{{ $e->slug }}</code>
                </div>
                <div class="mut pequeno" style="margin-top:3px">
                    {{ $e->modificador ? $rotulo[$e->modificador] ?? $e->modificador : "Sem modificador" }}
                    — <b>{{ $leitura($e) }}</b>
                    · {{ $e->escopo === "colonia" ? "colônia " . ($e->colony?->name ?? $e->colony_id) : "MUNDO" }}
                    · {{ $quando($e->comeca_em) }} → {{ $quando($e->termina_em) }}
                </div>
                @if ($e->temCesta())
                    <div class="mut pequeno" style="margin-top:3px">
                        Cesta, por colônia:
                        @foreach ($e->recompensas as $r => $q)
                            {{ $r === $FERT ? $fert($q) . " Fert$" : number_format($q, 0, ",", ".") . " " . $r }}@if(!$loop->last);@endif
                        @endforeach
                    </div>
                @endif
                <form method="POST" action="{{ route('admin.evento.ativar', $e) }}" style="margin-top:6px"
                      onsubmit="return confirm('Ativar «{{ $e->nome }}»? Passa a valer no mundo{{ $e->temCesta() ? ', e a cesta sai agora' : '' }}.')">
                    @csrf<button class="pequeno">Ativar</button>
                </form>
            </div>
        @empty
            <p class="mut pequeno">Nenhum rascunho.</p>
        @endforelse
    </div>

    {{-- ── Criar ── --}}
    <h2 class="secao">Criar um evento</h2>
    <div class="cartao">
        <form method="POST" action="{{ route('admin.evento.criar') }}">
            @csrf
            <div class="linha-form">
                <div style="flex:0">
                    <label>Slug (único, sem espaço)</label>
                    <input type="text" name="slug" maxlength="60" required pattern="[a-z0-9_-]+"
                           placeholder="cesta_de_presente" style="width:200px">
                </div>
                <div>
                    <label>Nome (o que o jogador lê)</label>
                    <input type="text" name="nome" maxlength="120" required>
                </div>
                <div style="flex:0">
                    <label>Dura (dias)</label>
                    <input type="number" name="dias" min="1" max="365" value="30" required style="width:90px">
                </div>
            </div>

            <div style="margin-top:8px">
                <label class="pequeno mut">Mensagem pública (a voz do mundo)</label>
                <input type="text" name="mensagem_publica" maxlength="500" style="width:100%">
            </div>
            <div style="margin-top:8px">
                <label class="pequeno mut">Notas internas (o jogador NUNCA vê)</label>
                <input type="text" name="notas_internas" maxlength="1000" style="width:100%">
            </div>

            <div class="linha-form" style="margin-top:12px">
                <div>
                    <label>Modificador</label>
                    <select name="modificador">
                        <option value="">— nenhum (só entrega cesta) —</option>
                        @foreach ($modificadores as $m)
                            <option value="{{ $m }}">{{ $rotulo[$m] ?? $m }} ({{ $m }})</option>
                        @endforeach
                    </select>
                </div>
                <div style="flex:0">
                    <label>Efeito (bps)</label>
                    <input type="number" name="efeito_bps" step="100" min="-10000" max="100000"
                           placeholder="-9500" style="width:120px">
                </div>
                <div style="flex:0">
                    <label>Só um recurso</label>
                    <select name="resource_type">
                        <option value="">todos</option>
                        @foreach ($recursos as $r)<option value="{{ $r->code }}">{{ $r->nome }}</option>@endforeach
                    </select>
                </div>
                <div style="flex:0">
                    <label>Visibilidade</label>
                    <select name="visibilidade">
                        <option value="anunciado">anunciado</option>
                        <option value="parcial">parcial</option>
                        <option value="secreto">secreto</option>
                    </select>
                </div>
            </div>
            <p class="mut pequeno" style="margin-top:6px">
                bps: <code>-2000</code> = −20%, <code>-9500</code> = −95%, <code>500</code> = +5%.
                O sinal é a direção. Para o <b>portão da guerra</b> só <code>-10000</code> fecha;
                para o <b>portão de colonos</b>, <code>-10000</code> isenta.
                O recurso e o efeito só valem para <code>producao</code> e <code>consumo</code>.
            </p>

            <div class="linha-form" style="margin-top:12px">
                <div style="flex:0">
                    <label>Começa em (vazio = agora)</label>
                    <input type="datetime-local" name="comeca_em">
                </div>
                <div style="flex:0">
                    <label>Só uma colônia (o ensaio em escala de 1)</label>
                    <select name="colony_id">
                        <option value="">MUNDO — todas</option>
                        @foreach ($colonias as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach
                    </select>
                </div>
                <div style="flex:0;align-self:flex-end;padding-bottom:6px">
                    <label style="white-space:nowrap">
                        <input type="checkbox" name="segredo" value="1"> segredo (nem o nome aparece)
                    </label>
                </div>
            </div>

            <h3 class="secao" style="margin-top:18px">A cesta (opcional) — por colônia</h3>
            <p class="mut pequeno">
                Entregue <b>uma vez por colônia</b>, e também a quem fundar durante a janela. É
                <b>emissão</b>: não sai do Tesouro, e por isso não há saldo que a limite — o que há é
                o ledger, que registra cada unidade como <code>presente_evento</code>.
            </p>
            <div style="overflow-x:auto;margin-top:8px">
                <table>
                    <tr><th>Recurso</th><th class="num">Quantidade por colônia</th></tr>
                    <tr>
                        <td><b>Fert$</b></td>
                        <td class="num">
                            <input type="number" step="0.0001" min="0" name="cesta[{{ $FERT }}]"
                                   style="width:120px;text-align:right">
                        </td>
                    </tr>
                    @foreach ($recursos as $r)
                        <tr>
                            <td>{{ $r->nome }}</td>
                            <td class="num">
                                <input type="number" min="0" name="cesta[{{ $r->code }}]"
                                       style="width:120px;text-align:right">
                            </td>
                        </tr>
                    @endforeach
                </table>
            </div>

            <div style="margin-top:12px">
                <button>Gravar como rascunho</button>
                <span class="mut pequeno" style="margin-left:8px">
                    Nada valerá no mundo até você ativar.
                </span>
            </div>
        </form>
    </div>

    {{-- ── Encerrados ── --}}
    <h2 class="secao">Encerrados ({{ $encerrados->count() }})</h2>
    <div class="cartao">
        @forelse ($encerrados as $e)
            <div class="pequeno" style="padding:5px 0;border-bottom:1px solid rgba(180,69,11,.08)">
                <b>{{ $e->nome }}</b> <code class="mut">{{ $e->slug }}</code>
                <span class="mut">
                    · {{ $e->modificador ? ($rotulo[$e->modificador] ?? $e->modificador) : "cesta" }}
                    · {{ $quando($e->comeca_em) }} → {{ $quando($e->termina_em) }}
                    @if ($e->cancelado_em) · cancelado {{ $quando($e->cancelado_em) }} @endif
                    @if ($e->entregas_count) · {{ $e->entregas_count }} cesta(s) entregue(s) @endif
                </span>
            </div>
        @empty
            <p class="mut pequeno">Nenhum evento encerrado.</p>
        @endforelse
    </div>

@endsection
