@if($paginator->hasPages())
<div class="pager">
    @if($paginator->onFirstPage())<span class="muted">&larr;</span>@else<a href="{{ $paginator->previousPageUrl() }}">&larr;</a>@endif
    <span>{{ $paginator->currentPage() }} из {{ $paginator->lastPage() }}</span>
    @if($paginator->hasMorePages())<a href="{{ $paginator->nextPageUrl() }}">&rarr;</a>@else<span class="muted">&rarr;</span>@endif
    <span class="muted">всего {{ $paginator->total() }}</span>
</div>
@endif
