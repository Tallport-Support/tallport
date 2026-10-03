@php(\FruitUI\Support\ComponentContract::validate('upload-list', $attributes))
<ul {{ $attributes->class(['f-upload'])->merge(['aria-label' => __('Attachments')]) }}>{{ $slot }}</ul>
