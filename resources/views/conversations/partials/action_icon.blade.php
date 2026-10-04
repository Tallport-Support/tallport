{{-- A conversation action's icon: a Heroicon for the core actions, the action's own glyph otherwise (modules). --}}
@php
    $action_heroicons = [
        'glyphicon-share-alt'   => 'arrow-uturn-left',
        'glyphicon-edit'        => 'pencil-square',
        'glyphicon-trash'       => 'trash',
        'glyphicon-bell'        => 'bell',
        'glyphicon-arrow-right' => 'arrow-uturn-right',
        'glyphicon-indent-left' => 'arrows-pointing-in',
        'glyphicon-log-out'     => 'arrow-right-start-on-rectangle',
        'glyphicon-print'       => 'printer',
        'glyphicon-time'        => 'clock',
    ];
@endphp
@if (isset($action_heroicons[$icon]))<x-dynamic-component :component="'heroicon-o-'.$action_heroicons[$icon]" class="f-icon" aria-hidden="true" />@else<i class="glyphicon {{ $icon }}" aria-hidden="true"></i>@endif
