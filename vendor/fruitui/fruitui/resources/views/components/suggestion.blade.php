@props(['title', 'meta' => null])
@php(\FruitUI\Support\ComponentContract::suggestion($title, $attributes))
{{-- A generated suggestion for the reader to review and use, such as an AI reply draft. While it is
     being made, set aria-busy="true" (or bind it): a skeleton stands in for the body. --}}
<section {{ $attributes->merge(['aria-label' => $title])->class(['f-suggestion']) }}>
    <header class="f-suggestion__header">
        <svg class="f-icon f-suggestion__icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M11.017 2.814a1 1 0 0 1 1.966 0l1.051 5.558a2 2 0 0 0 1.594 1.594l5.558 1.051a1 1 0 0 1 0 1.966l-5.558 1.051a2 2 0 0 0-1.594 1.594l-1.051 5.558a1 1 0 0 1-1.966 0l-1.051-5.558a2 2 0 0 0-1.594-1.594l-5.558-1.051a1 1 0 0 1 0-1.966l5.558-1.051a2 2 0 0 0 1.594-1.594z"/><path d="M20 2v4"/><path d="M22 4h-4"/><circle cx="4" cy="20" r="2"/></svg>
        <strong class="f-suggestion__title">{{ $title }}</strong>
        @isset($meta)<span class="f-suggestion__meta">{{ $meta }}</span>@endisset
        @isset($dismiss)<span {{ $dismiss->attributes->class(['f-suggestion__dismiss']) }}>{{ $dismiss }}</span>@endisset
    </header>
    @isset($status)
        <div {{ $status->attributes->except('tone')->merge(['data-tone' => \FruitUI\Support\ComponentContract::suggestionStatus($status->attributes->get('tone', 'neutral'))])->class(['f-suggestion__status']) }}>
            <span class="f-spinner f-suggestion__status-spinner" aria-hidden="true"></span>
            <svg class="f-icon f-suggestion__status-icon f-suggestion__status-icon--neutral" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
            <svg class="f-icon f-suggestion__status-icon f-suggestion__status-icon--warning" viewBox="0 0 24 24" aria-hidden="true"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>
            <svg class="f-icon f-suggestion__status-icon f-suggestion__status-icon--danger" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/></svg>
            <span class="f-suggestion__status-text">{{ $status }}</span>
        </div>
    @endisset
    <div class="f-skeleton f-suggestion__placeholder" aria-hidden="true">@for ($line = 0; $line < 4; $line++)<div class="f-skeleton__line"></div>@endfor</div>
    <div class="f-suggestion__body f-prose">{{ $slot }}</div>
    @isset($translation)
        <div {{ $translation->attributes->class(['f-suggestion__translation']) }}><svg class="f-icon f-suggestion__translation-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="m5 8 6 6"/><path d="m4 14 6-6 2-3"/><path d="M2 5h12"/><path d="M7 2h1"/><path d="m22 22-5-10-5 10"/><path d="M14 18h6"/></svg><span class="f-sr-only">{{ __('Translation') }}</span><div class="f-suggestion__translation-text f-prose">{{ $translation }}</div></div>
    @endisset
    @isset($actions)<div {{ $actions->attributes->merge(['aria-label' => __('Actions')])->class(['f-suggestion__actions']) }} role="group">{{ $actions }}</div>@endisset
    @isset($details)<div {{ $details->attributes->class(['f-suggestion__details']) }}>{{ $details }}</div>@endisset
</section>
