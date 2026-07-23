@extends('admin.layout')

@php
    /**
     * A mesma projeção célula→ponto de `frontend/src/ui/geometria.ts` (`projecaoDoPlaneta`), só que
     * aqui 1 unidade de SVG = 1 célula — sem a calha fracionária das réguas, que o jogador precisa
     * e o admin não (D-145): não há zoom decimal fino nem régua que não pode escorregar, só um
     * viewBox em unidades de célula que o navegador já escala sozinho.
     */
    $meia = intdiv($lado, 2);
    $px = fn (int $x) => $x + $meia + 0.5;
    $py = fn (int $y) => $lado - $meia - 0.5 - $y;
    $dono = auth('admin')->user()->ehDono();
@endphp

@section('content')

    <h2 class="secao">Mapa</h2>
    <p class="mut pequeno">
        O planeta {{ $lado }}×{{ $lado }} inteiro, sem névoa — {{ $colonias->count() }} colônia(s) e
        {{ $zonas->count() }} zonas neutras. Arraste para mover, role o mouse ou use os botões para o
        zoom.
        @if ($dono)
            As únicas ações daqui são mover colônia e liberar fundação — o resto é leitura.
        @else
            Só leitura: nenhuma ação parte daqui.
        @endif
    </p>

    @if ($dono)
        {{--
            Mover Colônias (D-146) e Liberar Fundação (D-147): os dois botões ligam modos
            mutuamente exclusivos — um desliga o outro. "Mover" é um-de-cada-vez com confirmação
            (mexe em colônia já existente); "Liberar" alterna na hora, sem confirmação (só decide
            onde um jogador NOVO poderá fundar, reversível com um segundo clique).
        --}}
        <div class="cartao" style="margin-bottom:10px;display:flex;align-items:center;gap:14px;flex-wrap:wrap">
            <button type="button" class="leve" id="mapa-mover-toggle" data-mover-colonias>Mover Colônias</button>
            <button type="button" class="leve" id="mapa-fundacao-toggle" data-marcar-fundacao>Liberar Fundação</button>
            <span id="mapa-mover-instrucao" class="mut pequeno" style="min-height:1.2em"></span>
        </div>
    @endif

    <div class="cartao" style="padding:0;position:relative;overflow:hidden">
        <div style="position:absolute;right:8px;top:8px;z-index:2;display:flex;flex-direction:column;gap:4px">
            <button type="button" class="leve" id="mapa-zoom-in" title="Aproximar" style="width:32px;height:32px;padding:0;font-size:1.1rem">+</button>
            <button type="button" class="leve" id="mapa-zoom-out" title="Afastar" style="width:32px;height:32px;padding:0;font-size:1.1rem">−</button>
            <button type="button" class="leve" id="mapa-reset" title="Ver o planeta inteiro" style="width:32px;height:32px;padding:0;font-size:.7rem">tudo</button>
        </div>

        <div id="mapa-coord" data-coordenada-cursor
             style="display:none;position:absolute;left:8px;bottom:8px;z-index:2;background:var(--sand-light);border:1px solid rgba(180,69,11,.25);padding:2px 8px;font-size:.7rem"></div>

        <svg id="mapa-svg" data-mapa-admin viewBox="0 0 {{ $lado }} {{ $lado }}"
             style="display:block;width:100%;height:70vh;background:var(--sand-light);cursor:grab;touch-action:none">

            <rect x="0" y="0" width="{{ $lado }}" height="{{ $lado }}" fill="var(--sand-light)"
                  stroke="var(--rust)" stroke-opacity="0.35" stroke-width="1" vector-effect="non-scaling-stroke" />

            {{-- O disco de founders e o anel livre (D-51/D-145): aproximação contínua da faixa exata
                 de `MapaFertways::faixaDe` — panorama, não precisão célula a célula. --}}
            <circle cx="{{ $px(0) }}" cy="{{ $py(0) }}" r="{{ $raioAnel }}" fill="var(--ink-soft)" fill-opacity="0.06" />
            <circle cx="{{ $px(0) }}" cy="{{ $py(0) }}" r="{{ $raioFounder }}" fill="var(--rust)" fill-opacity="0.12" />

            {{-- A grade: uma linha por borda de célula, sempre todas — 204 traços não pesam nada, e
                 o traço não-escalável mantém 1px na tela em qualquer zoom. --}}
            <g stroke="var(--rust)" stroke-opacity="0.15" vector-effect="non-scaling-stroke">
                @for ($i = 0; $i <= $lado; $i++)
                    <line x1="{{ $i }}" y1="0" x2="{{ $i }}" y2="{{ $lado }}" vector-effect="non-scaling-stroke" />
                    <line x1="0" y1="{{ $i }}" x2="{{ $lado }}" y2="{{ $i }}" vector-effect="non-scaling-stroke" />
                @endfor
            </g>
            {{-- Os dois eixos da Capital, mais fortes — a referência do sinal das coordenadas. --}}
            <g stroke="var(--rust)" stroke-opacity="0.45" vector-effect="non-scaling-stroke">
                <line x1="{{ $px(0) }}" y1="0" x2="{{ $px(0) }}" y2="{{ $lado }}" vector-effect="non-scaling-stroke" />
                <line x1="0" y1="{{ $py(0) }}" x2="{{ $lado }}" y2="{{ $py(0) }}" vector-effect="non-scaling-stroke" />
            </g>

            {{--
                As células de periferia já liberadas para fundação (D-147) — sempre visíveis, não
                só com o modo "Liberar Fundação" ligado. Criadas/removidas também em JS, na hora,
                quando o admin alterna uma célula sem recarregar a página.
            --}}
            <g id="mapa-camada-fundacao">
                @foreach ($celulasDeFundacao as $cel)
                    <circle data-celula-fundacao="{{ $cel->x }}:{{ $cel->y }}"
                            cx="{{ $px($cel->x) }}" cy="{{ $py($cel->y) }}" r="0.3" fill="var(--ember)">
                        <title>Fundação liberada ({{ $cel->x }}, {{ $cel->y }})</title>
                    </circle>
                @endforeach
            </g>

            {{-- As 120 zonas neutras: quadradinhos, cinza se livres, rust se ocupadas. --}}
            @foreach ($zonas as $z)
                <rect data-zona="{{ $z->id }}"
                      x="{{ $px($z->x) - 0.5 }}" y="{{ $py($z->y) - 0.5 }}" width="1" height="1"
                      fill="{{ $z->owner_colony_id ? 'var(--rust)' : 'rgba(30,28,23,.35)' }}">
                    <title>Zona {{ ucfirst($z->district) }} ({{ $z->x }}, {{ $z->y }}) — {{ $z->mineral }}{{ $z->owner ? ' · '.$z->owner->name : ' · livre' }}</title>
                </rect>
            @endforeach

            {{-- A Capital: losango. --}}
            <rect x="{{ $px(0) - 0.6 }}" y="{{ $py(0) - 0.6 }}" width="1.2" height="1.2"
                  transform="rotate(45 {{ $px(0) }} {{ $py(0) }})" fill="var(--rust)">
                <title>Capital — Governo de Fertways</title>
            </rect>

            {{--
                As colônias: círculo, clicável — abre a ficha rápida (modal), sem sair do mapa.
                `data-x`/`data-y`/`data-nome` carregam a posição/nome crus: é o que o modo "Mover
                Colônias" (D-146) usa pra saber, sem reconverter nada, qual célula é "ocupada por
                quem" ao validar o destino no cliente.
            --}}
            @foreach ($colonias as $c)
                <circle cx="{{ $px($c->x) }}" cy="{{ $py($c->y) }}" r="0.55" fill="var(--ink)"
                        data-abrir-colonia="{{ $c->id }}" data-x="{{ $c->x }}" data-y="{{ $c->y }}"
                        data-nome="{{ $c->name }}" style="cursor:pointer">
                    <title>{{ $c->name }} ({{ $c->user->nickname ?? '—' }}) — ({{ $c->x }}, {{ $c->y }}) · ver ficha</title>
                </circle>
            @endforeach
        </svg>

        {{--
            Uma ficha por colônia, pronta desde o carregamento — não há requisição nenhuma no
            clique. Nesta escala (poucas centenas de colônias no máximo) é mais simples que um
            endpoint novo, e o `<template>` não pesa nada enquanto não é clonado.
        --}}
        @foreach ($colonias as $c)
            <template id="ficha-colonia-{{ $c->id }}">
                <h3 style="margin:0 0 4px;color:var(--rust);text-transform:uppercase;letter-spacing:.08em;font-size:.7rem">Jogador</h3>
                <p style="margin:0 0 12px">
                    <b>{{ $c->user->nickname ?? '—' }}</b>
                    <span class="mut">({{ $c->user->name ?? '—' }})</span><br>
                    <span class="mut pequeno">{{ $c->user->email ?? '—' }}</span>
                    @if ($c->user && \App\Domain\Admin\Suspender::estaSuspenso($c->user))
                        <br><span class="pilula alerta" style="margin-top:4px;display:inline-block">Suspenso</span>
                    @endif
                </p>

                <h3 style="margin:0 0 4px;color:var(--rust);text-transform:uppercase;letter-spacing:.08em;font-size:.7rem">Colônia</h3>
                <p style="margin:0 0 12px">
                    <b>{{ $c->name }}</b> — ({{ $c->x }}, {{ $c->y }})<br>
                    <span class="mut pequeno">Fert$ {{ number_format($c->fert_micro / 1000000, 2, ',', '.') }}</span>
                </p>

                <h3 style="margin:0 0 4px;color:var(--rust);text-transform:uppercase;letter-spacing:.08em;font-size:.7rem">Zonas neutras ocupadas</h3>
                @php $zonasDaColonia = $zonas->where('owner_colony_id', $c->id); @endphp
                @if ($zonasDaColonia->isEmpty())
                    <p class="mut pequeno" style="margin:0">Nenhuma zona ocupada.</p>
                @else
                    <table style="margin:0">
                        <tr><th>Distrito</th><th>Mineral</th><th class="num">Depósito</th></tr>
                        @foreach ($zonasDaColonia as $z)
                            <tr>
                                <td>{{ ucfirst($z->district) }}</td>
                                <td>{{ $z->mineral }}</td>
                                <td class="num">{{ $z->deposit_amount }}</td>
                            </tr>
                        @endforeach
                    </table>
                @endif

                @if ($c->user_id)
                    <a href="{{ route('admin.jogador', $c->user_id) }}" target="_blank" class="pequeno"
                       style="display:inline-block;margin-top:14px">Ver ficha completa →</a>
                @endif
            </template>
        @endforeach
    </div>

    {{--
        O modal (D-145/D-146): overlay + caixa, escondido até um clique numa colônia. Dois
        conteúdos possíveis dentro da MESMA caixa — a ficha rápida (`#mapa-modal-conteudo`,
        clonada de um `<template>` por colônia) e a confirmação de mover (`#mapa-form-mover`,
        estática, sempre a mesma) — só um visível de cada vez, pra um não apagar o outro.
    --}}
    <div id="mapa-modal" style="display:none;position:fixed;inset:0;z-index:50;background:rgba(30,28,23,.55);align-items:center;justify-content:center">
        <div class="cartao" style="max-width:420px;width:92%;max-height:80vh;overflow-y:auto;position:relative">
            <button type="button" id="mapa-modal-fechar" title="Fechar"
                    style="position:absolute;right:8px;top:8px;width:26px;height:26px;padding:0;line-height:1;background:transparent;color:var(--ink-soft);border:1px solid rgba(180,69,11,.3)">×</button>
            <div id="mapa-modal-conteudo"></div>

            @if ($dono)
                {{--
                    A confirmação de mover (D-146): mesma trava de sempre — motivo obrigatório e a
                    palavra REALOCAR (`RealocarColonia`/`AcoesController::realocarPeloMapa`). Os
                    três campos ocultos são preenchidos pelo JS na hora de abrir; o resumo
                    (`#mapa-mover-resumo`) usa `textContent`, não HTML — nome de colônia é dado de
                    jogador.
                --}}
                <div id="mapa-form-mover" style="display:none">
                    <h3 style="margin:0 0 8px;color:var(--rust);text-transform:uppercase;letter-spacing:.08em;font-size:.7rem">Mover colônia</h3>
                    <p class="pequeno" id="mapa-mover-resumo" style="margin:0 0 12px"></p>
                    <form method="POST" action="{{ route('admin.mapa.realocar') }}">
                        @csrf
                        <input type="hidden" name="colony_id" id="mapa-mover-colony-id">
                        <input type="hidden" name="x" id="mapa-mover-x">
                        <input type="hidden" name="y" id="mapa-mover-y">
                        <div style="margin-bottom:10px">
                            <label class="pequeno mut">Motivo</label>
                            <input type="text" name="motivo" required maxlength="255">
                        </div>
                        <div style="margin-bottom:10px">
                            <label class="pequeno mut">Escreva REALOCAR pra confirmar</label>
                            <input type="text" name="confirmacao" required placeholder="REALOCAR">
                        </div>
                        <p class="mut pequeno">A viagem de todo veículo em rota será refeita a partir da nova posição.</p>
                        <div style="display:flex;gap:8px;margin-top:8px">
                            <button type="submit" class="perigo" style="flex:1">Mover</button>
                            <button type="button" id="mapa-mover-cancelar" class="leve">Cancelar</button>
                        </div>
                    </form>
                </div>
            @endif
        </div>
    </div>

    <h2 class="secao">Legenda</h2>
    <ul class="mut pequeno" style="list-style:none;padding:0;display:flex;flex-wrap:wrap;gap:18px;margin:0">
        <li><span style="display:inline-block;width:10px;height:10px;background:var(--rust);transform:rotate(45deg);margin-right:6px;vertical-align:middle"></span>Capital</li>
        <li><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:var(--ink);margin-right:6px;vertical-align:middle"></span>Colônia</li>
        <li><span style="display:inline-block;width:10px;height:10px;background:rgba(30,28,23,.35);margin-right:6px;vertical-align:middle"></span>Zona livre</li>
        <li><span style="display:inline-block;width:10px;height:10px;background:var(--rust);margin-right:6px;vertical-align:middle"></span>Zona ocupada</li>
        <li><span style="display:inline-block;width:10px;height:10px;background:var(--rust);opacity:.3;margin-right:6px;vertical-align:middle"></span>Disco de founders</li>
        <li><span style="display:inline-block;width:10px;height:10px;background:var(--ink-soft);opacity:.3;margin-right:6px;vertical-align:middle"></span>Anel livre</li>
        <li><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:var(--ember);margin-right:6px;vertical-align:middle"></span>Fundação liberada</li>
    </ul>

    <script>
        // Zoom e arraste do mapa admin (D-145): não há SPA neste painel, então é o primeiro
        // <script> vanilla da tela — só manipula o viewBox, o SVG acima já traz TODO o desenho
        // pronto do servidor (sem dado nenhum reconstruído aqui).
        (function () {
            var svg = document.getElementById('mapa-svg');
            var coordBox = document.getElementById('mapa-coord');
            var lado = {{ $lado }};
            var raioAnel = {{ $raioAnel }};
            var meia = Math.floor(lado / 2);
            var W_MIN = 6, W_MAX = lado;
            var state = { x: 0, y: 0, w: lado };

            // A mesma projeção do Blade (`$px`/`$py` no topo do arquivo), em JS: célula → unidade
            // de SVG. Usada pra ler a célula sob o cursor, pro destino do Mover e pra desenhar um
            // marcador novo de Fundação sem esperar reload nenhum.
            function px(x) { return x + meia + 0.5; }
            function py(y) { return lado - meia - 0.5 - y; }

            /** A célula (x, y) sob um ponto de tela, ou null se caiu fora do planeta. */
            function celulaEm(clientX, clientY) {
                var p = pontoNoSvg(clientX, clientY);
                if (!p) return null;
                var x = Math.round(p.x - meia - 0.5);
                var y = Math.round(lado - meia - 0.5 - p.y);

                return (Math.abs(x) > meia || Math.abs(y) > meia) ? null : { x: x, y: y };
            }

            function aplicar() {
                svg.setAttribute('viewBox', state.x + ' ' + state.y + ' ' + state.w + ' ' + state.w);
            }

            function clampW(w) { return Math.min(W_MAX, Math.max(W_MIN, w)); }
            function clampXY(v, w) { return Math.min(lado - w, Math.max(0, v)); }

            function zoomEm(cx, cy, fator) {
                var novoW = clampW(state.w * fator);
                if (novoW === state.w) return;
                var fx = (cx - state.x) / state.w;
                var fy = (cy - state.y) / state.w;
                state.x = clampXY(cx - fx * novoW, novoW);
                state.y = clampXY(cy - fy * novoW, novoW);
                state.w = novoW;
                aplicar();
            }

            function pontoNoSvg(clientX, clientY) {
                var ctm = svg.getScreenCTM();
                if (!ctm) return null;
                var p = svg.createSVGPoint();
                p.x = clientX;
                p.y = clientY;
                return p.matrixTransform(ctm.inverse());
            }

            document.getElementById('mapa-zoom-in').addEventListener('click', function () {
                zoomEm(state.x + state.w / 2, state.y + state.w / 2, 1 / 1.5);
            });
            document.getElementById('mapa-zoom-out').addEventListener('click', function () {
                zoomEm(state.x + state.w / 2, state.y + state.w / 2, 1.5);
            });
            document.getElementById('mapa-reset').addEventListener('click', function () {
                state = { x: 0, y: 0, w: lado };
                aplicar();
            });

            svg.addEventListener('wheel', function (e) {
                e.preventDefault();
                var p = pontoNoSvg(e.clientX, e.clientY);
                if (!p) return;
                zoomEm(p.x, p.y, e.deltaY < 0 ? 1 / 1.2 : 1.2);
            }, { passive: false });

            svg.addEventListener('pointerdown', function (e) {
                if (e.button !== 0) return;
                var rect = svg.getBoundingClientRect();
                var inicio = { x: state.x, y: state.y, w: state.w, clientX: e.clientX, clientY: e.clientY };
                svg.style.cursor = 'grabbing';

                function mover(ev) {
                    var dx = (ev.clientX - inicio.clientX) / rect.width * inicio.w;
                    var dy = (ev.clientY - inicio.clientY) / rect.height * inicio.w;
                    state.x = clampXY(inicio.x - dx, inicio.w);
                    state.y = clampXY(inicio.y - dy, inicio.w);
                    aplicar();
                }

                function soltar() {
                    window.removeEventListener('pointermove', mover);
                    window.removeEventListener('pointerup', soltar);
                    svg.style.cursor = 'grab';
                }

                window.addEventListener('pointermove', mover);
                window.addEventListener('pointerup', soltar);
            });

            svg.addEventListener('pointermove', function (e) {
                var c = celulaEm(e.clientX, e.clientY);
                if (!c) {
                    coordBox.style.display = 'none';
                    return;
                }
                coordBox.style.display = 'block';
                coordBox.textContent = '(' + c.x + ', ' + c.y + ')';
            });

            svg.addEventListener('pointerleave', function () {
                coordBox.style.display = 'none';
            });

            aplicar();

            // A ficha rápida (D-145): clonar o <template> da colônia clicada pra dentro do modal —
            // sem requisição nenhuma, os dados já vieram todos no carregamento da página.
            var modal = document.getElementById('mapa-modal');
            var modalConteudo = document.getElementById('mapa-modal-conteudo');
            var formMover = document.getElementById('mapa-form-mover'); // não existe pra quem não é dono

            // Os dois modos do mapa (D-146/D-147) são mutuamente exclusivos: `modo` é
            // `null | 'mover' | 'fundacao'`, e ligar um desliga o outro.
            var modo = null;
            var origem = null; // { id, x, y, nome } — só usado em 'mover'
            var circuloOrigem = null;
            var btnMover = document.getElementById('mapa-mover-toggle');
            var btnFundacao = document.getElementById('mapa-fundacao-toggle');
            var instrucao = document.getElementById('mapa-mover-instrucao');

            function definirInstrucao(texto, ehErro) {
                if (!instrucao) return;
                instrucao.textContent = texto;
                instrucao.style.color = ehErro ? 'var(--rust)' : '';
            }

            function estilizarBotao(btn, ativo) {
                if (!btn) return;
                btn.style.background = ativo ? 'var(--rust)' : '';
                btn.style.color = ativo ? 'var(--sand-light)' : '';
                btn.style.borderColor = ativo ? 'var(--rust)' : '';
            }

            function atualizarBotoes() {
                estilizarBotao(btnMover, modo === 'mover');
                estilizarBotao(btnFundacao, modo === 'fundacao');
            }

            function realcarOrigem(circulo) {
                if (circuloOrigem) {
                    circuloOrigem.setAttribute('fill', 'var(--ink)');
                    circuloOrigem.removeAttribute('stroke');
                    circuloOrigem.removeAttribute('stroke-width');
                }
                circuloOrigem = circulo;
                if (circulo) {
                    circulo.setAttribute('fill', 'var(--rust)');
                    circulo.setAttribute('stroke', 'var(--ink)');
                    circulo.setAttribute('stroke-width', '0.15');
                }
            }

            // Fecha o modal. Se era a confirmação de mover, NÃO cancela a origem — o admin volta a
            // "aguardando destino" pra tentar outra célula, sem escolher a colônia de novo.
            function fecharModal() {
                modal.style.display = 'none';
                if (modo === 'mover' && origem) {
                    definirInstrucao('Mover ' + origem.nome + ': clique no destino.');
                }
            }

            function abrirFicha(circulo) {
                var tpl = document.getElementById('ficha-colonia-' + circulo.getAttribute('data-abrir-colonia'));
                if (!tpl) return;
                modalConteudo.innerHTML = '';
                modalConteudo.appendChild(tpl.content.cloneNode(true));
                modalConteudo.style.display = 'block';
                if (formMover) formMover.style.display = 'none';
                modal.style.display = 'flex';
            }

            function abrirConfirmacaoMover(x, y) {
                document.getElementById('mapa-mover-colony-id').value = origem.id;
                document.getElementById('mapa-mover-x').value = x;
                document.getElementById('mapa-mover-y').value = y;

                // DOM, não string: o nome da colônia é dado de jogador.
                var resumo = document.getElementById('mapa-mover-resumo');
                resumo.textContent = '';
                resumo.appendChild(document.createTextNode('Mover '));
                var b = document.createElement('b');
                b.textContent = origem.nome;
                resumo.appendChild(b);
                resumo.appendChild(document.createTextNode(
                    ' de (' + origem.x + ', ' + origem.y + ') para (' + x + ', ' + y + ')?'
                ));

                modalConteudo.style.display = 'none';
                formMover.style.display = 'block';
                modal.style.display = 'flex';
            }

            if (btnMover) {
                btnMover.addEventListener('click', function () {
                    modo = modo === 'mover' ? null : 'mover';
                    origem = null;
                    realcarOrigem(null);
                    atualizarBotoes();
                    definirInstrucao(modo === 'mover' ? 'Clique na colônia a mover.' : '');
                });
            }

            var btnMoverCancelar = document.getElementById('mapa-mover-cancelar');
            if (btnMoverCancelar) {
                btnMoverCancelar.addEventListener('click', fecharModal);
            }

            // Liberar Fundação (D-147): alterna na hora, sem confirmação — é reversível com um
            // segundo clique na mesma célula.
            var camadaFundacao = document.getElementById('mapa-camada-fundacao');
            var csrfToken = document.querySelector('meta[name=csrf-token]').content;

            function marcadorDeFundacao(x, y) {
                return camadaFundacao.querySelector('[data-celula-fundacao="' + x + ':' + y + '"]');
            }

            function criarMarcadorDeFundacao(x, y) {
                var el = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
                el.setAttribute('data-celula-fundacao', x + ':' + y);
                el.setAttribute('cx', px(x));
                el.setAttribute('cy', py(y));
                el.setAttribute('r', '0.3');
                el.setAttribute('fill', 'var(--ember)');
                camadaFundacao.appendChild(el);
            }

            function alternarFundacao(x, y) {
                fetch('{{ route('admin.mapa.fundacao.alternar') }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                    },
                    body: JSON.stringify({ x: x, y: y }),
                })
                    .then(function (resp) {
                        return resp.json().then(function (corpo) { return { ok: resp.ok, corpo: corpo }; });
                    })
                    .then(function (r) {
                        if (!r.ok) {
                            definirInstrucao(r.corpo.message || 'Não foi possível marcar esta célula.', true);
                            return;
                        }
                        var existente = marcadorDeFundacao(x, y);
                        if (r.corpo.liberada) {
                            if (!existente) criarMarcadorDeFundacao(x, y);
                            definirInstrucao('Célula (' + x + ', ' + y + ') liberada para fundação.');
                        } else {
                            if (existente) existente.remove();
                            definirInstrucao('Célula (' + x + ', ' + y + ') fechada.');
                        }
                    })
                    .catch(function () {
                        definirInstrucao('Falha de rede ao marcar a célula.', true);
                    });
            }

            if (btnFundacao) {
                btnFundacao.addEventListener('click', function () {
                    modo = modo === 'fundacao' ? null : 'fundacao';
                    origem = null;
                    realcarOrigem(null);
                    atualizarBotoes();
                    definirInstrucao(modo === 'fundacao' ? 'Clique numa célula da periferia pra abrir/fechar a fundação.' : '');
                });
            }

            document.querySelectorAll('[data-abrir-colonia]').forEach(function (circulo) {
                circulo.addEventListener('click', function () {
                    if (modo === 'fundacao') {
                        return; // deixa o clique borbulhar pro handler genérico do svg, abaixo
                    }

                    if (modo !== 'mover') {
                        abrirFicha(circulo);
                        return;
                    }

                    var id = circulo.getAttribute('data-abrir-colonia');

                    if (origem && origem.id === id) {
                        // A mesma colônia de novo: cancela a origem, sem sair do modo.
                        origem = null;
                        realcarOrigem(null);
                        definirInstrucao('Clique na colônia a mover.');
                        return;
                    }

                    if (!origem) {
                        origem = {
                            id: id,
                            x: Number(circulo.getAttribute('data-x')),
                            y: Number(circulo.getAttribute('data-y')),
                            nome: circulo.getAttribute('data-nome'),
                        };
                        realcarOrigem(circulo);
                        definirInstrucao('Mover ' + origem.nome + ': clique no destino.');
                        return;
                    }

                    // Já tem origem, e o clique caiu em OUTRA colônia: destino ocupado.
                    definirInstrucao('Destino ocupado por ' + circulo.getAttribute('data-nome') + '.', true);
                });
            });

            // O clique genérico no SVG: destino do Mover, ou a célula a alternar da Fundação.
            svg.addEventListener('click', function (e) {
                if (modo === 'mover') {
                    if (!origem) return;
                    if (e.target.hasAttribute && e.target.hasAttribute('data-abrir-colonia')) return;

                    var c = celulaEm(e.clientX, e.clientY);
                    if (!c) return;

                    if (c.x === 0 && c.y === 0) {
                        definirInstrucao('A Capital não se move.', true);
                        return;
                    }
                    if (c.x === origem.x && c.y === origem.y) {
                        definirInstrucao('Já é a posição atual de ' + origem.nome + '.', true);
                        return;
                    }

                    abrirConfirmacaoMover(c.x, c.y);
                    return;
                }

                if (modo === 'fundacao') {
                    var alvo = celulaEm(e.clientX, e.clientY);
                    if (!alvo) return;

                    // Só o caso barato (distância à Capital) é conferido aqui — cobre Capital,
                    // disco de founders e anel de uma vez. Zona neutra e o resto ficam por conta
                    // da resposta do servidor: replicar o distrito em JS não vale a pena.
                    if (Math.hypot(alvo.x, alvo.y) <= raioAnel) {
                        definirInstrucao('Só a periferia entra nesta lista — o disco de founders segue a regra de sempre.', true);
                        return;
                    }

                    alternarFundacao(alvo.x, alvo.y);
                }
            });

            document.getElementById('mapa-modal-fechar').addEventListener('click', fecharModal);
            // Clicar no fundo escuro fecha; clicar dentro da caixa, não (o alvo não seria o overlay).
            modal.addEventListener('click', function (e) {
                if (e.target === modal) fecharModal();
            });
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') fecharModal();
            });
        })();
    </script>

@endsection
