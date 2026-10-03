@php(\FruitUI\Support\ComponentContract::validate('breadcrumbs', $attributes))
<nav {{ $attributes->class(['f-breadcrumbs'])->merge(['aria-label' => __('Breadcrumb')]) }}><ol>{{ $slot }}</ol></nav>
