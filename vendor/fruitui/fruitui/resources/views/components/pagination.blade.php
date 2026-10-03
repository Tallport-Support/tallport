@php(\FruitUI\Support\ComponentContract::validate('pagination', $attributes))
<nav {{ $attributes->class(['f-pagination'])->merge(['aria-label' => __('Pagination')]) }}>{{ $slot }}</nav>
