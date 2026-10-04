{{-- A conversation action's icon: a Lucide icon (components/icon) for the core actions, none for modules' Glyphicons. --}}
@php
    $action_icons = [
        'glyphicon-share-alt'   => 'reply',
        'glyphicon-edit'        => 'square-pen',
        'glyphicon-trash'       => 'trash-2',
        'glyphicon-bell'        => 'bell',
        'glyphicon-arrow-right' => 'forward',
        'glyphicon-indent-left' => 'merge',
        'glyphicon-log-out'     => 'arrow-right-from-line',
        'glyphicon-print'       => 'printer',
        'glyphicon-time'        => 'clock',
    ];
@endphp
@if (isset($action_icons[$icon]))<x-dynamic-component :component="'icon.'.$action_icons[$icon]" class="f-icon" aria-hidden="true" />@endif
