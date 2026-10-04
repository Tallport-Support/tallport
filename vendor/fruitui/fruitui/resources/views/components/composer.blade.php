@props(['placement' => 'bottom'])
@php(\FruitUI\Support\ComponentContract::validate('composer', $attributes, ['placement' => $placement]))
<form {{ $attributes->class(['f-composer', 'f-composer--top' => $placement === 'top']) }}>
    {{ $slot }}
</form>
