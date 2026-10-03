@php(\FruitUI\Support\ComponentContract::validate('progress', $attributes))
<progress {{ $attributes->class(['f-progress']) }}>{{ $slot }}</progress>
