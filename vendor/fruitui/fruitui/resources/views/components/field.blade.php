@php(\FruitUI\Support\ComponentContract::validate('field', $attributes))
@php($fieldError = $fruitField->error())
<div {{ $attributes->class(['f-field', 'f-field--row' => $layout === 'row', 'f-field--inline' => $layout === 'inline']) }}>
    <label class="f-label" for="{{ $fruitField->id() }}">{{ \FruitUI\Support\ComponentContract::fieldLabel($label) }}</label>
    {{ $slot }}
    @if($description !== null && $description !== '')
        <p class="f-help" id="{{ $fruitField->descriptionId() }}">{{ $description }}</p>
    @endif
    @if($fieldError !== null)
        <p class="f-error" id="{{ $fruitField->errorId() }}">{{ $fieldError }}</p>
    @endif
</div>
