@if ($paginator->hasPages())
    <nav class="pagination" aria-label="Pagination">
        <span class="muted">{{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} sur {{ $paginator->total() }}</span>
        <div class="pages">
            @if ($paginator->onFirstPage())
                <span class="page disabled"><x-admin.icon name="chevron-back" /></span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="Précédent"><x-admin.icon name="chevron-back" /></a>
            @endif

            @foreach ($elements as $element)
                @if (is_string($element))
                    <span class="page disabled">{{ $element }}</span>
                @endif
                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <span class="page current">{{ $page }}</span>
                        @else
                            <a href="{{ $url }}">{{ $page }}</a>
                        @endif
                    @endforeach
                @endif
            @endforeach

            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="Suivant"><x-admin.icon name="chevron-forward" /></a>
            @else
                <span class="page disabled"><x-admin.icon name="chevron-forward" /></span>
            @endif
        </div>
    </nav>
@endif
