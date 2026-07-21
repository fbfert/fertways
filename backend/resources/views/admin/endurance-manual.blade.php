{{-- Manual dos Benefícios (D-135/D-137) — aba inicial de /central/admin/endurance. Inclusa por
     endurance.blade.php, que já injeta $tiposEfeito no escopo. --}}

<p class="mut pequeno">
    Este manual descreve o campo <b>Benefícios</b> do formulário de item — o texto livre onde cada
    linha vira um efeito empilhável de verdade no motor do jogo. Não há botão "adicionar efeito":
    é uma linha por efeito, no formato abaixo.
</p>

<div class="cartao">
    <h2 class="secao" style="margin-top:0">Formato de cada linha</h2>
    <p class="pequeno">
        <code>tipo_efeito:valor_bps</code> — para os tipos que <b>não</b> exigem alvo (tributo,
        drone).<br>
        <code>tipo_efeito:alvo:valor_bps</code> — para os tipos que exigem alvo (produção,
        velocidade, capacidade).<br>
        Deixe o campo inteiro em branco para um item <b>sem nenhum efeito</b> (só cosmético/posse).
    </p>
    <ul class="pequeno" style="margin:8px 0 0;padding-left:18px">
        <li><b>100 bps = 1%</b>. Então <code>2000</code> bps = 20%, <code>500</code> bps = 5%.</li>
        <li><code>valor_bps</code> é sempre um inteiro positivo (mínimo 1). Não existe efeito negativo.</li>
        <li>Uma linha em branco entre efeitos é ignorada — não precisa ficar tudo colado.</li>
        <li>Tipo desconhecido, ou alvo faltando quando o tipo exige um, e o salvamento inteiro é
            recusado (nenhum item é criado/alterado pela metade).</li>
        <li>Um item pode ter <b>quantas linhas quiser</b> — inclusive repetir o mesmo
            <code>tipo_efeito</code> com alvos diferentes (ex.: um bônus na Mina Local e outro,
            numa linha à parte, na Fazenda).</li>
    </ul>
    <p class="pequeno" style="margin-top:8px">
        Exemplo de um item com dois efeitos empilhados:
    </p>
    <pre class="pequeno" style="background:rgba(180,69,11,.05);padding:8px;border-radius:4px">producao_bonus:mina_local:2000
velocidade_veiculo:todos:1000</pre>
</div>

<div class="cartao">
    <h2 class="secao" style="margin-top:0">Como o bônus empilha por colônia</h2>
    <p class="pequeno">
        Uma colônia pode possuir <b>mais de uma unidade</b> de um item não-único (enquanto o
        estoque global aguentar) e pode possuir <b>vários itens diferentes</b> ao mesmo tempo. O
        motor soma <code>valor_bps × quantidade possuída</code> de <u>todo efeito do mesmo
        tipo</u> — de todos os itens da colônia — antes de aplicar um teto agregado por tipo. O
        teto é por <b>tipo de efeito</b>, não por item: não adianta empilhar itens além do teto,
        o excedente é descartado (não é um erro, só não soma mais nada).
    </p>
    <table style="margin-top:4px">
        <tr><th>Tipo de efeito</th><th class="num">Teto agregado</th></tr>
        <tr><td class="pequeno"><code>desconto_tributo</code></td><td class="num">3000 bps (30%)</td></tr>
        <tr><td class="pequeno"><code>producao_bonus</code></td><td class="num">5000 bps (50%)</td></tr>
        <tr><td class="pequeno"><code>velocidade_veiculo</code></td><td class="num">5000 bps (50%)</td></tr>
        <tr><td class="pequeno"><code>capacidade_veiculo</code></td><td class="num">5000 bps (50%)</td></tr>
        <tr><td class="pequeno"><code>drone_raio</code></td><td class="num">10000 bps (100%)</td></tr>
        <tr><td class="pequeno"><code>drone_bateria</code></td><td class="num">10000 bps (100%)</td></tr>
    </table>
    <p class="mut pequeno" style="margin-top:6px">
        Exemplo: uma colônia com 3 unidades de um item <code>producao_bonus:mina_local:2000</code>
        somaria 3 × 2000 = 6000 bps (60%) — mas o teto de 5000 corta o bônus real em 50%, não 60%.
    </p>
</div>

<div class="cartao">
    <h2 class="secao" style="margin-top:0">Os 6 tipos de efeito, um a um</h2>

    <h3 class="pequeno" style="margin-bottom:2px"><code>desconto_tributo</code> — sem alvo</h3>
    <p class="pequeno" style="margin-top:0">
        Formato: <code>desconto_tributo:valor_bps</code>. Reduz o tributo cobrado nos dois pontos
        onde ele incide: o frete entre colônia e Mercado Central (transporte), e a alíquota de
        toda ordem executada no próprio Mercado Central. O desconto se aplica sobre a alíquota
        cheia — <code>desconto_tributo:1000</code> tira 10 pontos percentuais de uma taxa de 25%,
        deixando 15%, não 10% dela.
    </p>

    <h3 class="pequeno" style="margin-bottom:2px"><code>producao_bonus</code> — exige alvo</h3>
    <p class="pequeno" style="margin-top:0">
        Formato: <code>producao_bonus:alvo:valor_bps</code>. O <code>alvo</code> é o
        <code>building_type</code> exato de uma construção, ou a palavra <code>global</code> para
        bonificar todas de uma vez. Só tem efeito real em <b>9 construções</b> — as demais (Quartel,
        Laboratório, torres, etc.) não produzem recurso por hora, então um bônus nelas não faz
        nada visível:
    </p>
    <table style="margin-top:4px">
        <tr><th>Grupo</th><th>Construções (<code>alvo</code>)</th><th>Como o bônus age</th></tr>
        <tr>
            <td class="pequeno">Sem insumo<br><span class="mut">(produzem do zero)</span></td>
            <td class="pequeno"><code>mina_local</code>, <code>fazenda</code>,
                <code>captacao_de_agua</code>, <code>gerador_de_atmosfera</code>,
                <code>reator_de_energia</code></td>
            <td class="pequeno">Bônus <b>de graça</b>: só aumenta a saída por hora, não consome
                nada a mais (não há insumo a consumir).</td>
        </tr>
        <tr>
            <td class="pequeno">De conversão<br><span class="mut">(convertem um insumo)</span></td>
            <td class="pequeno"><code>destilaria</code>, <code>industria_siderurgica</code>,
                <code>refinaria_quimica</code>, <code>oficina</code></td>
            <td class="pequeno"><b>Throughput</b>: acelera o processamento — mais saída por hora,
                mas consome o insumo (Biomassa/Energia, Metal Bruto, etc.) proporcionalmente mais
                rápido também. Não é bônus grátis.</td>
        </tr>
    </table>
    <p class="pequeno" style="margin-top:6px">
        <code>global</code> soma-se ao bônus específico da construção (os dois juntos, capados uma
        vez só pelo teto de 5000 bps acima) — então <code>producao_bonus:global:1000</code> dá
        +10% em <b>todas</b> as 9 construções da tabela ao mesmo tempo, cada uma somando esse
        +10% com qualquer bônus específico que também tenha.
    </p>

    <h3 class="pequeno" style="margin-bottom:2px"><code>velocidade_veiculo</code> — exige alvo</h3>
    <p class="pequeno" style="margin-top:0">
        Formato: <code>velocidade_veiculo:alvo:valor_bps</code>. <code>alvo</code> é
        <code>furgao_de_comercio</code>, <code>caminhao_de_carga</code>, ou <code>todos</code> para
        os dois. Aumenta a velocidade de viagem do veículo — trechos de frete/comércio duram menos
        tempo.
    </p>

    <h3 class="pequeno" style="margin-bottom:2px"><code>capacidade_veiculo</code> — exige alvo</h3>
    <p class="pequeno" style="margin-top:0">
        Formato: <code>capacidade_veiculo:alvo:valor_bps</code>. Mesmos alvos de
        <code>velocidade_veiculo</code> (<code>furgao_de_comercio</code>,
        <code>caminhao_de_carga</code>, <code>todos</code>). Aumenta quanto o veículo carrega por
        viagem.
    </p>

    <h3 class="pequeno" style="margin-bottom:2px"><code>drone_raio</code> — sem alvo</h3>
    <p class="pequeno" style="margin-top:0">
        Formato: <code>drone_raio:valor_bps</code>. Aumenta o raio de vigia do Drone de Exploração
        — mais território avistado por sobrevoo.
    </p>

    <h3 class="pequeno" style="margin-bottom:2px"><code>drone_bateria</code> — sem alvo</h3>
    <p class="pequeno" style="margin-top:0">
        Formato: <code>drone_bateria:valor_bps</code>. Aumenta a duração da bateria do Drone —
        missões mais longas antes de precisar recarregar.
    </p>
</div>

<div class="cartao">
    <h2 class="secao" style="margin-top:0">Exemplos completos</h2>
    <table style="margin-top:4px">
        <tr><th>Linha</th><th>Efeito em português</th></tr>
        <tr>
            <td class="pequeno"><code>producao_bonus:mina_local:2000</code></td>
            <td class="pequeno">+20% de produção na Mina Local (de graça — não tem insumo)</td>
        </tr>
        <tr>
            <td class="pequeno"><code>producao_bonus:industria_siderurgica:1500</code></td>
            <td class="pequeno">+15% de processamento na Indústria Siderúrgica (throughput —
                consome Metal Bruto 15% mais rápido também)</td>
        </tr>
        <tr>
            <td class="pequeno"><code>producao_bonus:global:500</code></td>
            <td class="pequeno">+5% em todas as 9 construções produtoras da colônia</td>
        </tr>
        <tr>
            <td class="pequeno"><code>velocidade_veiculo:todos:1000</code></td>
            <td class="pequeno">+10% de velocidade em Furgão de Comércio e Caminhão de Carga</td>
        </tr>
        <tr>
            <td class="pequeno"><code>capacidade_veiculo:furgao_de_comercio:2500</code></td>
            <td class="pequeno">+25% de capacidade de carga só no Furgão de Comércio</td>
        </tr>
        <tr>
            <td class="pequeno"><code>drone_raio:1000</code></td>
            <td class="pequeno">+10% de raio de vigia do Drone</td>
        </tr>
        <tr>
            <td class="pequeno"><code>drone_bateria:2000</code></td>
            <td class="pequeno">+20% de duração de bateria do Drone</td>
        </tr>
        <tr>
            <td class="pequeno"><code>desconto_tributo:1500</code></td>
            <td class="pequeno">-15 pontos percentuais no tributo (transporte e Mercado Central)</td>
        </tr>
    </table>
</div>
