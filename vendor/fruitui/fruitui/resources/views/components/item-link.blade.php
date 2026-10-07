@props(['variant' => 'quiet', 'current' => false, 'mark' => null, 'markLabel' => null, 'unread' => false, 'unreadLabel' => null])
@php(\FruitUI\Support\ComponentContract::validate('item-link', $attributes, ['variant' => $variant]))
@php($mark = \FruitUI\Support\ComponentContract::mark($mark, $markLabel, true))
@php($unreadLabel = \FruitUI\Support\ComponentContract::unread($unread, $unreadLabel ?? __('Unread')))
{{-- A row that is a destination: a real link that opens in a new tab and works without JavaScript. --}}
<a {{ $attributes->merge(['aria-current' => $current ? 'page' : null, 'data-fruit-mark' => $mark])->class(['f-item-row', 'f-item-row--filled' => $variant === 'filled']) }}>
    @include('fruit::partials.item-row-body')
</a>
