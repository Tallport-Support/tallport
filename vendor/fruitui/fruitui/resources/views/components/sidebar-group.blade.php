@props(['title', 'subtitle' => null, 'mark' => null])
@php(\FruitUI\Support\ComponentContract::validate('sidebar-group', $attributes))
@php($mark = \FruitUI\Support\ComponentContract::mark($mark))
<details {{ $attributes->class(['f-sidebar__group']) }}>
    <summary class="f-sidebar__item" @if($mark !== null) data-fruit-mark="{{ $mark }}" @endif>
        @isset($icon){{ $icon }}@endisset
        @if($subtitle !== null)
            <span class="f-sidebar__identity"><strong>{{ $title }}</strong><small>{{ $subtitle }}</small></span>
        @else
            <span class="f-sidebar__identity">{{ $title }}</span>
        @endif
        <svg class="f-icon f-sidebar__chevron" viewBox="0 0 24 24" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>
    </summary>
    {{ $slot }}
</details>
