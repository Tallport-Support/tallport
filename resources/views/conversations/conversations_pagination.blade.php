@if ($paginator->hasPages())
    <div class="f-pagination__controls">
        <a href="#" class="f-button f-button--small pager-nav pager-first @if ($paginator->currentPage() <= 2) disabled @endif" data-page="1" title="{{ __('First Page') }}" aria-label="{{ __('First Page') }}" @if ($paginator->currentPage() <= 2) aria-disabled="true" @endif>«</a>
        <a href="#" class="f-button f-button--small pager-nav pager-prev @if ($paginator->onFirstPage()) disabled @endif" data-page="{{ $paginator->currentPage()-1 }}" title="{{ __('Previous Page') }}" aria-label="{{ __('Previous Page') }}" @if ($paginator->onFirstPage()) aria-disabled="true" @endif>‹</a>
        <a href="#" class="f-button f-button--small pager-nav pager-next @if (!$paginator->hasMorePages()) disabled @endif" data-page="{{ $paginator->currentPage()+1 }}" title="{{ __('Next Page') }}" aria-label="{{ __('Next Page') }}" @if (!$paginator->hasMorePages()) aria-disabled="true" @endif>›</a>
        <a href="#" class="f-button f-button--small pager-nav pager-last @if ($paginator->currentPage() >= $paginator->lastPage()-1) disabled @endif" data-page="{{ $paginator->lastPage() }}" title="{{ __('Last Page') }}" aria-label="{{ __('Last Page') }}" @if ($paginator->currentPage() >= $paginator->lastPage()-1) aria-disabled="true" @endif>»</a>
    </div>
@endif
