@php(\FruitUI\Support\ComponentContract::validate('field', $attributes))
@php($fieldError = $fruitField->error())
<div {{ $attributes->class(['f-field']) }}>
    <label class="f-label" for="{{ $fruitField->id() }}">{{ $label }}</label>
    {{ $slot }}
    @if($description !== null && $description !== '')
        <p class="f-help" id="{{ $fruitField->descriptionId() }}">{{ $description }}</p>
    @endif
    @if($fieldError !== null)
        <p class="f-error" id="{{ $fruitField->errorId() }}">{{ $fieldError }}</p>
    @endif
</div>
