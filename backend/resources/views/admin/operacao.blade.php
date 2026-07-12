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
        <form method="POST" action="{{ route('admin.realocar') }}" class="inline"
              onsubmit="return confirm('Realocar as colônias para slots de founder? Aborta se algum veículo estiver em rota.')">
            @csrf<button class="perigo">Realocar founders</button>
        </form>
        <span class="mut pequeno">O tick avança o mundo (produção, viagens, folha, prazos). A realocação é rara e guardada.</span>
    </div>

@endsection
