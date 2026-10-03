@props(['title', 'subtitle' => null])
@php(\FruitUI\Support\ComponentContract::validate('sidebar-group', $attributes))
<details {{ $attributes->class(['f-sidebar__group']) }}>
    <summary class="f-sidebar__item">
        @isset($icon){{ $icon }}@endisset
        @if($subtitle !== null)
            <span class="f-sidebar__identity"><strong>{{ $title }}</strong><small>{{ $subtitle }}</small></span>
        @else
            <span class="f-sidebar__identity">{{ $title }}</span>
        @endif
        <svg class="f-icon f-sidebar__chevron" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 3.5 4.5 4.5L6 12.5"/></svg>
    </summary>
    {{ $slot }}
</details>
