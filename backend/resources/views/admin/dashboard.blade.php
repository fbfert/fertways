@extends('admin.layout')

@php
    $fert = fn ($micro) => number_format(((int) $micro) / 1000000, 2, ',', '.');
    $quando = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format('d/m H:i') : '—';
@endphp

@section('content')

    {{-- ── Resumo ── --}}
    <h2 class="secao">Panorama</h2>
    <div class="grade">
        <div class="tile"><b>{{ $resumo['colonias'] }}</b><span>Colônias</span></div>
        <div class="tile"><b>{{ $resumo['jogadores'] }}</b><span>Jogadores</span></div>
        <div class="tile"><b>{{ $fert($resumo['fert_em_circulacao_micro']) }}</b><span>Fert$ em circulação</span></div>
        <div class="tile"><b>{{ $fert($resumo['tesouro_fert_micro']) }}</b><span>Tesouro (Fert$)</span></div>
        <div class="tile"><b>{{ $resumo['casos_na_equipe'] }}</b><span>Casos na equipe</span></div>
        <div class="tile"><b>{{ $resumo['ordens_abertas'] }}</b><span>Ordens abertas</span></div>
        <div class="tile"><b>{{ $resumo['veiculos_em_rota'] }}/{{ $resumo['veiculos_ociosos'] }}</b><span>Veíc. rota/ociosos</span></div>
        <div class="tile"><b>{{ $resumo['zonas_ocupadas'] }}</b><span>Zonas ocupadas</span></div>
    </div>

    {{-- ── Ministério ── --}}
    <h2 class="secao">Ministério — casos com a equipe</h2>
    <div class="cartao">
        @forelse ($filaEquipe as $r)
            <div style="border-bottom:1px solid rgba(180,69,11,.12);padding:8px 0">
                <div>
                    <b>#{{ $r->id }}</b> — {{ $r->violation }}
                    @if ($r->grave)<span class="pilula alerta">grave</span>@endif
                    <span class="pilula" style="background:var(--sand)">{{ $r->status }}</span>
                </div>
                <div class="mut pequeno">{{ $r->reporter?->name }} → {{ $r->accused?->name }} · pena tabelada: {{ \App\Domain\Ministry\PunicaoSpecs::violacao($r->violation)['indice'] ?? '—' }}</div>
                <div class="linha-form">
                    @if ($r->status === 'na_equipe')
                        <form method="POST" action="{{ route('admin.julgar', $r) }}" class="inline">@csrf
                            <input type="hidden" name="procedente" value="1"><button>Procedente</button></form>
                        <form method="POST" action="{{ route('admin.julgar', $r) }}" class="inline">@csrf
                            <input type="hidden" name="procedente" value="0"><button class="leve">Improcedente</button></form>
                    @elseif ($r->status === 'apelado')
                        <form method="POST" action="{{ route('admin.apelacao', $r) }}" class="inline">@csrf
                            <input type="hidden" name="decisao" value="manter"><button>Manter</button></form>
                        <form method="POST" action="{{ route('admin.apelacao', $r) }}" class="inline"
                              onsubmit="return confirm('Reverter estorna a pena e conta uma reversão ao conciliador. Confirmar?')">@csrf
                            <input type="hidden" name="decisao" value="reverter"><button class="perigo">Reverter</button></form>
                    @endif
                </div>
            </div>
        @empty
            <p class="mut pequeno">Nenhum caso aguardando a equipe.</p>
        @endforelse
    </div>

    @if ($atribuidos->isNotEmpty() || $emApelacao->isNotEmpty())
    <div class="cartao">
        <table>
            <tr><th>Caso</th><th>Situação</th><th>Prazo</th><th>Conciliador</th></tr>
            @foreach ($atribuidos as $r)
                <tr><td>#{{ $r->id }} {{ $r->violation }}</td><td>atribuído</td><td>{{ $quando($r->deadline_at) }}</td><td>{{ $r->conciliator?->nickname ?? '—' }}</td></tr>
            @endforeach
            @foreach ($emApelacao as $r)
                <tr><td>#{{ $r->id }} {{ $r->violation }}</td><td>decidido (janela de apelação)</td><td>{{ $quando($r->appeal_until) }}</td><td>—</td></tr>
            @endforeach
        </table>
    </div>
    @endif

    {{-- ── Conciliadores ── --}}
    <h2 class="secao">Conciliadores</h2>
    <div class="cartao">
        <table>
            <tr><th>Colono</th><th>Reversões</th><th>Situação</th><th>Ações</th></tr>
            @forelse ($conciliadores as $u)
                <tr>
                    <td>{{ $u->nickname }}</td>
                    <td>{{ $u->reversoes }}/{{ \App\Domain\Ministry\PunicaoSpecs::LIMITE_REVERSOES }}</td>
                    <td>{{ $u->conciliador_suspenso_em ? 'suspenso' : 'ativo' }}</td>
                    <td>
                        @foreach (['reintegrar' => 'leve', 'suspender' => 'leve', 'demitir' => 'perigo'] as $acao => $cls)
                            <form method="POST" action="{{ route('admin.conciliador.gerir', $u) }}" class="inline"
                                  @if ($acao === 'demitir') onsubmit="return confirm('Demitir {{ $u->nickname }}?')" @endif>
                                @csrf<input type="hidden" name="acao" value="{{ $acao }}">
                                <button class="{{ $cls }}">{{ ucfirst($acao) }}</button>
                            </form>
                        @endforeach
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" class="mut pequeno">Nenhum conciliador. Todo caso sobe à equipe.</td></tr>
            @endforelse
        </table>
        <form method="POST" action="{{ route('admin.conciliador.nomear') }}" class="linha-form">
            @csrf
            <div><label>Nomear (nickname)</label><input type="text" name="nickname" required></div>
            <div style="flex:0"><button>Nomear</button></div>
        </form>
    </div>

    {{-- ── Finanças ── --}}
    <h2 class="secao">Finanças — intervenções de preço</h2>
    <div class="cartao">
        <table>
            <tr><th>Recurso</th><th>Piso</th><th>Teto</th><th>Motivo</th><th>Expira</th><th></th></tr>
            @forelse ($intervencoes as $i)
                <tr>
                    <td>{{ $i->resource_type }}</td>
                    <td class="num">{{ $i->floor_micro !== null ? $fert($i->floor_micro) : '—' }}</td>
                    <td class="num">{{ $i->ceil_micro !== null ? $fert($i->ceil_micro) : '—' }}</td>
                    <td>{{ $i->reason }}</td>
                    <td>{{ $quando($i->expires_at) }}</td>
                    <td>
                        <form method="POST" action="{{ route('admin.intervencao.revogar') }}" class="inline">
                            @csrf<input type="hidden" name="resource_type" value="{{ $i->resource_type }}">
                            <button class="leve">Revogar</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="mut pequeno">Nenhuma intervenção vigente — o Mercado está livre.</td></tr>
            @endforelse
        </table>
        <form method="POST" action="{{ route('admin.intervencao') }}" class="linha-form">
            @csrf
            <div><label>Recurso</label>
                <select name="resource_type">
                    @foreach ($recursos as $r)<option value="{{ $r->code }}">{{ $r->nome }} ({{ $r->tax_class }})</option>@endforeach
                </select>
            </div>
            <div><label>Piso (Fert$)</label><input type="number" step="0.0001" min="0" name="piso"></div>
            <div><label>Teto (Fert$)</label><input type="number" step="0.0001" min="0" name="teto"></div>
            <div><label>Motivo</label><input type="text" name="motivo" required></div>
            <div style="flex:0"><label>Dias</label><input type="number" min="1" name="dias" value="7" style="width:70px"></div>
            <div style="flex:0"><button>Declarar</button></div>
        </form>
    </div>

    {{-- ── Ministério do Tesouro ── --}}
    <h2 class="secao">Ministério do Tesouro</h2>
    <div class="cartao">
        <p class="mut pequeno">A reserva do governo — o tributo do comércio entra aqui. Envie parte a um colono.</p>
        <div style="max-height:220px;overflow:auto;margin-top:8px">
            <table>
                <tr><th>Recurso</th><th class="num">Saldo</th></tr>
                <tr><td><b>Fert$</b></td><td class="num" data-tesouro-fert>{{ $fert($tesouroFert) }}</td></tr>
                @foreach ($tesouro as $h)
                    <tr><td>{{ $h->nome }}</td><td class="num">{{ number_format($h->amount, 0, ',', '.') }}</td></tr>
                @endforeach
            </table>
        </div>
        <form method="POST" action="{{ route('admin.tesouro.distribuir') }}" class="linha-form">
            @csrf
            <div><label>Colônia</label>
                <select name="colony_id">
                    @foreach ($colonias as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach
                </select>
            </div>
            <div><label>Recurso</label>
                <select name="recurso">
                    <option value="{{ $FERT }}">Fert$</option>
                    @foreach ($recursos as $r)<option value="{{ $r->code }}">{{ $r->nome }}</option>@endforeach
                </select>
            </div>
            <div style="flex:0"><label>Quantidade</label><input type="number" step="0.0001" min="0.0001" name="quantidade" required></div>
            <div style="flex:0"><button>Enviar</button></div>
        </form>
    </div>

    {{-- ── Notícias ── --}}
    <h2 class="secao">Central de Notícias</h2>
    <div class="cartao">
        @forelse ($noticias as $n)
            <div style="border-bottom:1px solid rgba(180,69,11,.12);padding:6px 0">
                <b>{{ $n->title }}</b> <span class="mut pequeno">— {{ $n->author }} · {{ $quando($n->published_at) }}</span>
                <form method="POST" action="{{ route('admin.noticia.remover', $n) }}" class="inline"
                      onsubmit="return confirm('Remover este comunicado?')">
                    @csrf<button class="leve">Remover</button>
                </form>
            </div>
        @empty
            <p class="mut pequeno">Mural vazio.</p>
        @endforelse
        <form method="POST" action="{{ route('admin.noticia') }}" style="margin-top:10px">
            @csrf
            <div class="linha-form">
                <div><label>Título</label><input type="text" name="titulo" maxlength="140" required></div>
                <div style="flex:0"><label>Autor</label><input type="text" name="autor" placeholder="Administração Pública"></div>
            </div>
            <div style="margin-top:8px"><label class="pequeno mut">Corpo</label><textarea name="corpo" rows="3" required></textarea></div>
            <div style="margin-top:8px"><button>Publicar comunicado</button></div>
        </form>
    </div>

    {{-- ── Operação ── --}}
    <h2 class="secao">Operação</h2>
    <div class="cartao">
        <form method="POST" action="{{ route('admin.tick') }}" class="inline">
            @csrf<button>Disparar tick</button>
        </form>
        <form method="POST" action="{{ route('admin.realocar') }}" class="inline"
              onsubmit="return confirm('Realocar as colônias para slots de founder? Aborta se algum veículo estiver em rota.')">
            @csrf<button class="perigo">Realocar founders</button>
        </form>
        <span class="mut pequeno">O tick avança o mundo (produção, viagens, folha, prazos). A realocação é rara e guardada.</span>
    </div>

    {{-- ── Colônias & Jogadores ── --}}
    <h2 class="secao">Colônias</h2>
    <div class="cartao">
        <table>
            <tr><th>#</th><th>Nome</th><th>Colono</th><th>Posição</th><th class="num">Fert$</th></tr>
            @foreach ($colonias as $c)
                <tr><td>{{ $c->id }}</td><td>{{ $c->name }}</td><td>{{ $c->user?->nickname ?? '—' }}</td>
                    <td>({{ $c->x }}, {{ $c->y }})</td><td class="num">{{ $fert($c->fert_micro) }}</td></tr>
            @endforeach
        </table>
    </div>

    <h2 class="secao">Jogadores</h2>
    <div class="cartao">
        <table>
            <tr><th>Nickname</th><th>E-mail</th><th class="num">Com.</th><th class="num">Soc.</th><th class="num">Cív.</th><th class="num">Mil.</th><th>Conciliador</th></tr>
            @foreach ($jogadores as $u)
                <tr>
                    <td>{{ $u->nickname }}</td><td class="mut pequeno">{{ $u->email }}</td>
                    <td class="num">{{ $u->confianca_comercial }}</td><td class="num">{{ $u->conduta_social }}</td>
                    <td class="num">{{ $u->status_civico }}</td><td class="num">{{ $u->honra_militar_diplomatica }}</td>
                    <td>{{ $u->conciliador_desde ? ($u->conciliador_suspenso_em ? 'suspenso' : 'sim') : '—' }}</td>
                </tr>
            @endforeach
        </table>
    </div>

    {{-- ── Logística ── --}}
    @if ($obras->isNotEmpty() || $zonas->isNotEmpty())
    <h2 class="secao">Logística</h2>
    <div class="cartao">
        @if ($obras->isNotEmpty())
            <b class="pequeno">Fila de obras</b>
            <table>
                <tr><th>Colônia</th><th>Nível-alvo</th><th>Situação</th><th>Conclui</th></tr>
                @foreach ($obras as $o)
                    <tr><td>{{ $o->colony?->name }}</td><td>{{ $o->target_level }}</td><td>{{ $o->status }}</td><td>{{ $quando($o->finishes_at) }}</td></tr>
                @endforeach
            </table>
        @endif
        @if ($zonas->isNotEmpty())
            <b class="pequeno" style="display:block;margin-top:10px">Zonas ocupadas</b>
            <table>
                <tr><th>Distrito</th><th>Mineral</th><th>Dono</th><th>Situação</th><th class="num">Depósito</th></tr>
                @foreach ($zonas as $z)
                    <tr><td>{{ $z->district }}</td><td>{{ $z->mineral }}</td><td>{{ $z->owner?->name ?? '—' }}</td><td>{{ $z->status }}</td><td class="num">{{ $z->deposit_amount }}</td></tr>
                @endforeach
            </table>
        @endif
    </div>
    @endif

@endsection
