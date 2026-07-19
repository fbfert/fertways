@extends("admin.layout")

@php
    $pct = fn ($bps) => number_format(((int) $bps) / 100, 2, ",", ".") . "%";
    $fert = fn ($micro) => number_format(((int) $micro) / 1000000, 3, ",", ".");
    $quando = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format("d/m H:i") : "—";
@endphp

@section("content")
    {{-- ── A guerra agora (§27, §28.10; D-70) ── --}}
    <h2 class="secao">A guerra, agora</h2>

    <div class="cartao">
        <table>
            <tr><th>Exército do planeta</th><th class="num">Unidades</th></tr>
            <tr><td>Sentinelas <span class="mut">(a única que ataca — e a única que defende de verdade)</span></td><td class="num" data-exercito="sentinela">{{ $exercito['sentinela'] ?? 0 }}</td></tr>
            <tr><td>Robôs Mineradores <span class="mut">(extraem; em combate valem 25% de uma Sentinela)</span></td><td class="num" data-exercito="robo_minerador">{{ $exercito['robo_minerador'] ?? 0 }}</td></tr>
            <tr><td>Infiltradores <span class="mut">(sabotagem)</span></td><td class="num" data-exercito="infiltrador">{{ $exercito['infiltrador'] ?? 0 }}</td></tr>
            <tr><td>Predadores <span class="mut">(apreensão de módulos)</span></td><td class="num" data-exercito="predador">{{ $exercito['predador'] ?? 0 }}</td></tr>
            <tr><td><b>Nióbio nas colônias</b></td><td class="num" data-niobio-total>{{ number_format($niobio, 0, ",", ".") }}</td></tr>
        </table>
        <p class="mut pequeno" style="margin-top:6px">
            <b>Nada em Fertways produz Nióbio</b>, e a Sentinela custa 3. O estoque acima é o teto do
            exército que ainda pode nascer — e ele só cresce quando o governo vende, pelo preço que
            está lá embaixo. Zerar o preço torna a guerra gratuita; subi-lo demais a congela.
        </p>
    </div>

    {{-- ── Os olhos do planeta (D-74): a guerra de informação ── --}}
    <h2 class="secao">Drones de Exploração</h2>
    <div class="cartao">
        @if ($drones->isEmpty())
            <p class="mut">Nenhum Drone fabricado no planeta. A névoa do D-74 está intacta:
            ninguém vê o interior de zona alheia.</p>
        @else
            <table>
                <tr><th>Placa</th><th>Dono</th><th class="num">Nível</th><th>Estado</th><th>Volta/termina</th></tr>
                @foreach ($drones as $d)
                    <tr data-drone="{{ $d->id }}">
                        <td>{{ $d->plate }}</td>
                        <td>{{ $d->colony->name ?? "—" }}</td>
                        <td class="num">{{ $d->level }}</td>
                        <td>
                            @if ($d->leg === null) no hangar
                            @elseif ($d->leg === "ida") voando (missão: {{ $d->trip_purpose }})
                            @elseif ($d->leg === "vigia") <b>sobrevoando a zona #{{ $d->destination_id }}</b>
                            @else voltando
                            @endif
                        </td>
                        <td>{{ $quando($d->arrives_at) }}</td>
                    </tr>
                @endforeach
            </table>
        @endif
        <p class="mut pequeno" style="margin-top:6px">
            Fotos de reconhecimento tiradas até hoje: <b data-fotos>{{ $fotos }}</b>. A guarnição e o
            depósito de zona alheia são <b>névoa</b> desde o D-74 — o Drone é o único olho que a
            atravessa, e esta tabela é a guerra de informação em curso.
        </p>
    </div>

    {{-- ── Cercos: o único relógio que corre contra alguém ── --}}
    <h2 class="secao">Zonas cercadas</h2>
    <div class="cartao">
        @if ($cercadas->isEmpty())
            <p class="mut">Nenhuma zona sob cerco.</p>
        @else
            <p class="mut pequeno">
                Cercada, a zona não recebe nem despacha nada — <b>nem tropa</b>. O dono tem 48 h para
                <b>romper o cerco</b> (mandando Sentinelas a campo aberto) ou render-se e entregar 30%
                do estoque exposto. É o único ataque com prazo.
            </p>
            <table style="margin-top:8px">
                <tr><th>Zona</th><th>Dono</th><th>Cercada desde</th></tr>
                @foreach ($cercadas as $z)
                    <tr data-cercada="{{ $z->id }}">
                        <td>#{{ $z->id }} ({{ $z->x }}, {{ $z->y }})</td>
                        <td>{{ $z->owner->name ?? "—" }}</td>
                        <td>{{ $quando($z->sieged_at) }}</td>
                    </tr>
                @endforeach
            </table>
        @endif
    </div>

    {{-- ── Batalhas ── --}}
    <h2 class="secao">Batalhas em curso</h2>
    <div class="cartao">
        @if ($combates->isEmpty())
            <p class="mut">Nenhuma batalha em curso no planeta.</p>
        @else
            <table>
                <tr><th>#</th><th>Tipo</th><th>Zona</th><th>Atacante</th><th>Defensor</th><th class="num">Rodada</th><th>Chega</th><th>Prazo</th></tr>
                @foreach ($combates as $c)
                    <tr data-combate="{{ $c->id }}">
                        <td>{{ $c->id }}</td>
                        <td>
                            {{ $c->tipo }}
                            @if ($c->tipo === "ruptura")
                                <span class="mut pequeno">(o sitiado saiu a campo)</span>
                            @endif
                        </td>
                        <td>#{{ $c->zone_id }} ({{ $c->zone->x ?? "?" }}, {{ $c->zone->y ?? "?" }})</td>
                        <td>{{ $c->attacker->name ?? "—" }}</td>
                        <td>{{ $c->defender->name ?? "—" }}</td>
                        <td class="num">{{ $c->status === "marchando" ? "—" : $c->rodada }}</td>
                        <td>{{ $quando($c->chega_at) }}</td>
                        <td>{{ $quando($c->prazo_at) }}</td>
                    </tr>
                @endforeach
            </table>
            <p class="mut pequeno" style="margin-top:6px">
                A rodada é de 10 minutos e corre <b>no tick</b>. A força e o dano dos dois lados são
                <b>congelados na chegada</b> — mudar um parâmetro aqui embaixo <b>não altera</b> uma
                batalha que já começou.
            </p>
        @endif
    </div>

    {{-- ── Os dez números ── --}}
    <h2 class="secao">Parâmetros da guerra</h2>
    <div class="cartao">
        <p class="mut pequeno">
            O §27.3 escreve os bônus defensivos como <b>"(valores configuráveis)"</b> e o §28.10 manda
            comparar níveis <b>sem publicar a conta</b>. Quando o GDD manda alguém declarar um número e
            não o publica, ele é <b>do operador</b> — e este é o formulário. O jogo obedece ao que
            estiver aqui, não ao que o seeder escreveu. <b>Até hoje só se mudavam por SQL.</b>
        </p>

        <form method="POST" action="{{ route('admin.guerra.parametros') }}">
            @csrf

            <h3 style="margin-top:14px">Bônus defensivos <span class="mut pequeno">— por nível da construção, em bps (100 bps = 1%)</span></h3>
            <div class="linha-form">
                <div style="flex:0">
                    <label>Muralha</label>
                    <input type="number" min="0" max="10000" name="muralha_bonus_bps"
                           value="{{ $guerra->muralha_bonus_bps }}" data-p="muralha" required>
                    <span class="mut pequeno">{{ $pct($guerra->muralha_bonus_bps) }}/nível</span>
                </div>
                <div style="flex:0">
                    <label>Torre de Vigia</label>
                    <input type="number" min="0" max="10000" name="torre_bonus_bps"
                           value="{{ $guerra->torre_bonus_bps }}" data-p="torre" required>
                    <span class="mut pequeno">{{ $pct($guerra->torre_bonus_bps) }}/nível</span>
                </div>
                <div style="flex:0">
                    <label>Bastião</label>
                    <input type="number" min="0" max="10000" name="bastiao_bonus_bps"
                           value="{{ $guerra->bastiao_bonus_bps }}" data-p="bastiao" required>
                    <span class="mut pequeno">{{ $pct($guerra->bastiao_bonus_bps) }}/nível</span>
                </div>
            </div>
            <p class="mut pequeno">
                Somam-se e multiplicam a Força Defensiva da zona. <b>Zerar os três torna as três
                construções decorativas</b> — quem as ergueu terá pago por nada.
            </p>

            <h3 style="margin-top:14px">A Torre de Vigia <span class="mut pequeno">— ela faz duas coisas diferentes</span></h3>
            <div class="linha-form">
                <div style="flex:0">
                    <label>Detecção do Infiltrador (bps/nível)</label>
                    <input type="number" min="0" max="10000" name="torre_deteccao_bps_por_nivel"
                           value="{{ $guerra->torre_deteccao_bps_por_nivel }}" data-p="deteccao" required>
                    <span class="mut pequeno">{{ $pct($guerra->torre_deteccao_bps_por_nivel) }}/nível, a cada rodada</span>
                </div>
                <div style="flex:0">
                    <label>Antecedência do aviso (min/nível)</label>
                    <input type="number" min="0" max="120" name="torre_aviso_minutos_por_nivel"
                           value="{{ $guerra->torre_aviso_minutos_por_nivel }}" data-p="aviso" required>
                    <span class="mut pequeno">nível 5 = {{ 5 * $guerra->torre_aviso_minutos_por_nivel }} min antes</span>
                </div>
            </div>
            <p class="mut pequeno">
                <b>Sem Torre, o defensor só vê o inimigo quando ele chega.</b> É esta segunda linha que
                dá sentido ao combate de ~2 h do §27.5 ("tempo suficiente para o defensor receber
                notificação, recrutar reforços e despachá-los"): <b>zerá-la mata o reforço</b>, porque
                ninguém socorre o que não vê chegando.
            </p>

            <h3 style="margin-top:14px">Apreensão de módulos <span class="mut pequeno">— o Predador contra o Abrigo de Robôs (§28.10)</span></h3>
            <div class="linha-form">
                <div style="flex:0">
                    <label>Base, no empate</label>
                    <input type="number" min="0" max="10000" name="predador_base_bps"
                           value="{{ $guerra->predador_base_bps }}" data-p="pred-base" required>
                    <span class="mut pequeno">{{ $pct($guerra->predador_base_bps) }}</span>
                </div>
                <div style="flex:0">
                    <label>Por nível de diferença</label>
                    <input type="number" min="0" max="10000" name="predador_por_nivel_bps"
                           value="{{ $guerra->predador_por_nivel_bps }}" data-p="pred-nivel" required>
                    <span class="mut pequeno">±{{ $pct($guerra->predador_por_nivel_bps) }}</span>
                </div>
                <div style="flex:0">
                    <label>Piso</label>
                    <input type="number" min="0" max="10000" name="predador_min_bps"
                           value="{{ $guerra->predador_min_bps }}" data-p="pred-min" required>
                    <span class="mut pequeno">{{ $pct($guerra->predador_min_bps) }}</span>
                </div>
                <div style="flex:0">
                    <label>Teto</label>
                    <input type="number" min="0" max="10000" name="predador_max_bps"
                           value="{{ $guerra->predador_max_bps }}" data-p="pred-max" required>
                    <span class="mut pequeno">{{ $pct($guerra->predador_max_bps) }}</span>
                </div>
            </div>
            <p class="mut pequeno">
                O GDD manda "comparar níveis" e não publica a conta. A nossa: <b>base ± (nível do
                Predador − nível do Abrigo) × por-nível</b>, presa entre o piso e o teto — <b>nunca há
                certeza nos dois sentidos</b>. O teto não pode ficar abaixo do piso, e o formulário
                recusa.
            </p>

            <h3 style="margin-top:14px">Reparo de módulos <span class="mut pequeno">— Sabotagem e resgate antecipado de Apreensão (§28.10)</span></h3>
            <div class="linha-form">
                <div style="flex:0">
                    <label>Custo, fração do preço de construção</label>
                    <input type="number" min="0" max="10000" name="reparo_bps_do_custo"
                           value="{{ $guerra->reparo_bps_do_custo }}" data-p="reparo" required>
                    <span class="mut pequeno">{{ $pct($guerra->reparo_bps_do_custo) }} do custo de construção do nível atual</span>
                </div>
            </div>
            <p class="mut pequeno">
                O §28.10 manda "reparar ou pagar o resgate" e não publica custo nenhum. Fração do
                custo de CONSTRUÇÃO da estrutura (não número novo) — mesmo padrão da manutenção de
                veículos do Ministério dos Transportes. A Apreensão também repara sozinha em 24h,
                sem custo nenhum; isto só paga para reaver antes do prazo, ou para a Sabotagem, que
                não tem prazo automático.
            </p>

            <h3 style="margin-top:14px">O Nióbio Alienígena <span class="mut pequeno">— o freio de todo o exército</span></h3>
            <div class="linha-form">
                <div style="flex:0">
                    <label>Preço, em micro-Fert$</label>
                    <input type="number" min="0" name="niobio_preco_micro"
                           value="{{ $guerra->niobio_preco_micro }}" data-p="niobio" required>
                    <span class="mut pequeno">= {{ $fert($guerra->niobio_preco_micro) }} Fert$ a unidade</span>
                </div>
                <button type="submit" data-salvar-guerra>Salvar parâmetros</button>
            </div>
            <p class="mut pequeno">
                O governo o vende do <b>caixa do Tesouro</b> — é a <b>única</b> fonte no planeta, e o
                caixa pode secar. Este é o número que decide se a guerra é cara ou barata, e ele é a
                válvula mais direta que o operador tem sobre o ritmo dela.
            </p>
        </form>
    </div>
@endsection
