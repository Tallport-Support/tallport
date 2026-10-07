@props(['variant' => 'quiet', 'mark' => null, 'markLabel' => null, 'unread' => false, 'unreadLabel' => null])
@php(\FruitUI\Support\ComponentContract::validate('item-row', $attributes, ['variant' => $variant]))
@php($mark = \FruitUI\Support\ComponentContract::mark($mark, $markLabel, true))
@php($unreadLabel = \FruitUI\Support\ComponentContract::unread($unread, $unreadLabel ?? __('Unread')))
<button type="button" {{ $attributes->except('type')->merge(['data-fruit-mark' => $mark])->class(['f-item-row', 'f-item-row--filled' => $variant === 'filled']) }}>
    @include('fruit::partials.item-row-body')
</button>
