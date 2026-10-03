@props(['name' => null, 'state' => 'complete', 'progress' => null])
@php(\FruitUI\Support\ComponentContract::uploadRow($name, $state, $progress, $attributes))
@php($status = ['uploading' => __('Uploading…'), 'complete' => __('Uploaded'), 'error' => __('Upload failed. Retry or remove the file.'), 'cancelled' => __('Cancelled')][$state])
<li {{ $attributes->class(['f-upload__row'])->merge(['data-state' => $state]) }}>
    <div class="f-upload__body">
        <strong>{{ $name }}</strong>
        <span class="f-help" role="status">@isset($detail){{ $detail }}@else{{ $status }}@endisset</span>
        @if ($state === 'uploading')
            <progress class="f-progress" max="100" @if($progress !== null) value="{{ (int) $progress }}" @endif aria-label="{{ __('Upload :name', ['name' => $name]) }}"></progress>
        @endif
        {{ $slot }}
    </div>
    @isset($actions)<div {{ $actions->attributes->class(['f-upload__actions']) }}>{{ $actions }}</div>@endisset
</li>
