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
@endphp

@section('content')

    <h2 class="secao">Mapa</h2>
    <p class="mut pequeno">
        O planeta {{ $lado }}×{{ $lado }} inteiro, sem névoa — {{ $colonias->count() }} colônia(s) e
        {{ $zonas->count() }} zonas neutras. Arraste para mover, role o mouse ou use os botões para o
        zoom. Só leitura: nenhuma ação parte daqui.
    </p>

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

            {{-- As colônias: círculo, clicável — abre a ficha rápida (modal), sem sair do mapa. --}}
            @foreach ($colonias as $c)
                <circle cx="{{ $px($c->x) }}" cy="{{ $py($c->y) }}" r="0.55" fill="var(--ink)"
                        data-abrir-colonia="{{ $c->id }}" style="cursor:pointer">
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

    {{-- O modal da ficha rápida (D-145): overlay + caixa, escondido até um clique numa colônia. --}}
    <div id="mapa-modal" style="display:none;position:fixed;inset:0;z-index:50;background:rgba(30,28,23,.55);align-items:center;justify-content:center">
        <div class="cartao" style="max-width:420px;width:92%;max-height:80vh;overflow-y:auto;position:relative">
            <button type="button" id="mapa-modal-fechar" title="Fechar"
                    style="position:absolute;right:8px;top:8px;width:26px;height:26px;padding:0;line-height:1;background:transparent;color:var(--ink-soft);border:1px solid rgba(180,69,11,.3)">×</button>
            <div id="mapa-modal-conteudo"></div>
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
    </ul>

    <script>
        // Zoom e arraste do mapa admin (D-145): não há SPA neste painel, então é o primeiro
        // <script> vanilla da tela — só manipula o viewBox, o SVG acima já traz TODO o desenho
        // pronto do servidor (sem dado nenhum reconstruído aqui).
        (function () {
            var svg = document.getElementById('mapa-svg');
            var coordBox = document.getElementById('mapa-coord');
            var lado = {{ $lado }};
            var meia = Math.floor(lado / 2);
            var W_MIN = 6, W_MAX = lado;
            var state = { x: 0, y: 0, w: lado };

            function aplicar() {
                svg.setAttribute('viewBox', state.x + ' ' + state.y + ' ' + state.w + ' ' + state.w);
            }

            function clampW(w) { return Math.min(W_MAX, Math.max(W_MIN, w)); }
            function clampXY(v, w) { return Math.min(lado - w, Math.max(0, v)); }

            function zoomEm(px, py, fator) {
                var novoW = clampW(state.w * fator);
                if (novoW === state.w) return;
                var fx = (px - state.x) / state.w;
                var fy = (py - state.y) / state.w;
                state.x = clampXY(px - fx * novoW, novoW);
                state.y = clampXY(py - fy * novoW, novoW);
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
                var p = pontoNoSvg(e.clientX, e.clientY);
                if (!p) return;
                var x = Math.round(p.x - meia - 0.5);
                var y = Math.round(lado - meia - 0.5 - p.y);
                if (Math.abs(x) > meia || Math.abs(y) > meia) {
                    coordBox.style.display = 'none';
                    return;
                }
                coordBox.style.display = 'block';
                coordBox.textContent = '(' + x + ', ' + y + ')';
            });

            svg.addEventListener('pointerleave', function () {
                coordBox.style.display = 'none';
            });

            aplicar();

            // A ficha rápida (D-145): clonar o <template> da colônia clicada pra dentro do modal —
            // sem requisição nenhuma, os dados já vieram todos no carregamento da página.
            var modal = document.getElementById('mapa-modal');
            var modalConteudo = document.getElementById('mapa-modal-conteudo');

            function fecharModal() {
                modal.style.display = 'none';
            }

            document.querySelectorAll('[data-abrir-colonia]').forEach(function (circulo) {
                circulo.addEventListener('click', function () {
                    var tpl = document.getElementById('ficha-colonia-' + circulo.getAttribute('data-abrir-colonia'));
                    if (!tpl) return;
                    modalConteudo.innerHTML = '';
                    modalConteudo.appendChild(tpl.content.cloneNode(true));
                    modal.style.display = 'flex';
                });
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
