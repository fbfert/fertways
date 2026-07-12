{{--
    A paginação do painel.

    O `links()` já era chamado na lista de jogadores desde o D-61, mas **sem template registrado o
    Laravel cai no dele, que é escrito para Tailwind** — e este painel é Blade com CSS próprio, sem
    Tailwind nenhum. O resultado era um bloco de markup sem estilo: os controles estavam lá, e ninguém
    os via. Na prática, a lista simplesmente terminava na 30ª linha.

    Registrado em `AppServiceProvider`.
--}}
@if ($paginator->hasPages())
    <nav class="paginacao">
        @if ($paginator->onFirstPage())
            <span class="pg mut">‹ anterior</span>
        @else
            <a class="pg" href="{{ $paginator->previousPageUrl() }}" rel="prev">‹ anterior</a>
        @endif

        <span class="mut pequeno">
            {{ $paginator->firstItem() }}–{{ $paginator->lastItem() }}
            de {{ $paginator->total() }}
            · página {{ $paginator->currentPage() }} de {{ $paginator->lastPage() }}
        </span>

        @if ($paginator->hasMorePages())
            <a class="pg" href="{{ $paginator->nextPageUrl() }}" rel="next">próxima ›</a>
        @else
            <span class="pg mut">próxima ›</span>
        @endif
    </nav>
@endif
