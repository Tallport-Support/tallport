@props(['description' => null])
@aware(['fruitField' => null])
@php($attributes = \FruitUI\Support\ComponentContract::control('switch', $attributes, $fruitField))
@php([$attributes, $descriptionId] = \FruitUI\Support\ComponentContract::choiceDescription($attributes, $description))
@if ($descriptionId)
{{-- The description sits outside the label, so it describes the control without joining its name. --}}
<div class="f-choice">
@endif
<label class="f-switch">
    <input type="checkbox" role="switch" {{ $attributes }}>
    <span>{{ $slot }}</span>
</label>
@if ($descriptionId)
    <p class="f-help f-choice__description" id="{{ $descriptionId }}">{{ $description }}</p>
</div>
@endif
