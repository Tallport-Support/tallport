<x-page-nav :label="__('Knowledge Base')">
    <x-slot:title><h1>{{ __('Knowledge Base') }}</h1></x-slot:title>
    <a href="{{ route('kb') }}" @if (Route::is('kb') && ($category ?? '') === '') aria-current="page" @endif>{{ __('All Articles') }}</a>
    @foreach ($categories as $sidebar_category)
        <a href="{{ route('kb', ['category' => $sidebar_category]) }}" @if (($category ?? '') === $sidebar_category) aria-current="page" @endif>{{ $sidebar_category }}</a>
    @endforeach
</x-page-nav>
