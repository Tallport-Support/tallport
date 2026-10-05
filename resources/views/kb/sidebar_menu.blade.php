<x-page-nav :label="__('Knowledge Base')">
    <x-slot:title><h1>{{ __('Knowledge Base') }}</h1></x-slot:title>
    <x-slot:actions>
        @if (!empty($kb_action))
            <a wire:navigate href="{{ $kb_action['url'] }}" class="f-button">{{ $kb_action['label'] }}</a>
        @endif
    </x-slot:actions>
    <a wire:navigate href="{{ route('kb') }}" @if (Route::is('kb') && ($category ?? '') === '') aria-current="page" @endif>{{ __('All Articles') }}</a>
    @foreach ($categories as $sidebar_category)
        <a wire:navigate href="{{ route('kb', ['category' => $sidebar_category]) }}" @if (($category ?? '') === $sidebar_category) aria-current="page" @endif>{{ $sidebar_category }}</a>
    @endforeach
</x-page-nav>
