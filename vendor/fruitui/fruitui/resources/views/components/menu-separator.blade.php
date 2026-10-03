@php(\FruitUI\Support\ComponentContract::validate('menu-separator', $attributes))
<div role="separator" {{ $attributes->except('role')->class(['f-menu__separator']) }}></div>
