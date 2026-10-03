@props(['title' => __('Actions'), 'placement' => 'below'])
@php(\FruitUI\Support\ComponentContract::validate('menu', $attributes, ['placement' => $placement]))
@php($triggerAttributes = isset($trigger) ? $trigger->attributes : new \Illuminate\View\ComponentAttributeBag())
@php(\FruitUI\Support\ComponentContract::validate('menu-trigger', $triggerAttributes))
<details x-data="fruitMenu" {{ $attributes->class(['f-menu', 'f-menu--above' => $placement === 'above']) }}>
    <summary role="button" {{ $triggerAttributes->except(['role', 'aria-haspopup'])->class(['f-button']) }} aria-haspopup="menu">@isset($trigger){{ $trigger }}@else{{ $title }}<span class="f-menu__chevron" aria-hidden="true"></span>@endisset</summary>
    <div class="f-menu__items" role="menu" aria-label="{{ $title }}">{{ $slot }}</div>
</details>
