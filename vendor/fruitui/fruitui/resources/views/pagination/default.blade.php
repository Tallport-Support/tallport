{{-- Laravel paginator view. Inside a Livewire component, page controls call Livewire's pagination actions. --}}
@php
    $livewire = isset($__livewire);
    // After a page change, show the start of the list: its scrolling pane, or the component's top.
    $scrollToList = "(pane => pane ? pane.scrollTo({ top: 0 }) : \$el.closest('[wire\\\\:id]')?.scrollIntoView({ block: 'nearest' }))(\$el.closest('.f-pane__scroll, .f-pane--scroll'))";
    $pageName = method_exists($paginator, 'getPageName') ? $paginator->getPageName() : 'page';
    if (method_exists($paginator, 'getCursorName')) {
        $previousAction = "setPage('".$paginator->previousCursor()?->encode()."', '".$paginator->getCursorName()."')";
        $nextAction = "setPage('".$paginator->nextCursor()?->encode()."', '".$paginator->getCursorName()."')";
    } else {
        $previousAction = "previousPage('{$pageName}')";
        $nextAction = "nextPage('{$pageName}')";
    }
@endphp
@if ($paginator->hasPages())
    <x-fruit::pagination>
        @if (method_exists($paginator, 'total'))
            <span>{{ __(':first–:last of :total', ['first' => $paginator->firstItem(), 'last' => $paginator->lastItem(), 'total' => $paginator->total()]) }}</span>
        @endif
        <div class="f-pagination__controls">
            @if ($paginator->onFirstPage())
                <button class="f-button" type="button" disabled>{{ __('Previous') }}</button>
            @elseif ($livewire)
                <button class="f-button" type="button" wire:click="{{ $previousAction }}" wire:loading.attr="disabled" x-on:click="{{ $scrollToList }}">{{ __('Previous') }}</button>
            @else
                <a class="f-button" href="{{ $paginator->previousPageUrl() }}" rel="prev">{{ __('Previous') }}</a>
            @endif
            @foreach ($elements ?? [] as $element)
                @if (is_string($element))
                    <span aria-hidden="true">{{ $element }}</span>
                @else
                    @foreach ($element as $page => $url)
                        @if ($livewire)
                            <button class="f-button" type="button" wire:click="{{ "gotoPage({$page}, '{$pageName}')" }}" wire:loading.attr="disabled" x-on:click="{{ $scrollToList }}" aria-label="{{ __('Page :page', ['page' => $page]) }}" @if ($page == $paginator->currentPage()) aria-current="page" @endif>{{ $page }}</button>
                        @else
                            <a class="f-button" href="{{ $url }}" aria-label="{{ __('Page :page', ['page' => $page]) }}" @if ($page == $paginator->currentPage()) aria-current="page" @endif>{{ $page }}</a>
                        @endif
                    @endforeach
                @endif
            @endforeach
            @if (! $paginator->hasMorePages())
                <button class="f-button" type="button" disabled>{{ __('Next') }}</button>
            @elseif ($livewire)
                <button class="f-button" type="button" wire:click="{{ $nextAction }}" wire:loading.attr="disabled" x-on:click="{{ $scrollToList }}">{{ __('Next') }}</button>
            @else
                <a class="f-button" href="{{ $paginator->nextPageUrl() }}" rel="next">{{ __('Next') }}</a>
            @endif
        </div>
    </x-fruit::pagination>
@endif
