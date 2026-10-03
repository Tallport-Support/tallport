@props(['pane', 'flexible', 'variable', 'min' => 160, 'max' => 420, 'reserve' => 280, 'edge' => 'end'])
@php
    \FruitUI\Support\ComponentContract::splitter($pane, $flexible, $variable, $min, $max, $reserve, $edge, $attributes);
    $config = ['pane' => $pane, 'flexible' => $flexible, 'variable' => $variable, 'min' => (float) $min, 'max' => (float) $max, 'reserve' => (float) $reserve, 'edge' => $edge];
@endphp
<div {{ $attributes->merge(['data-fruit-value-text' => __('{count} pixels')])->except(['role', 'tabindex', 'aria-orientation', 'aria-controls'])->class(['f-splitter']) }} role="separator" tabindex="0" aria-orientation="vertical" aria-controls="{{ $pane }}" data-edge="{{ $edge }}" x-data="{{ 'fruitSplitter('.json_encode($config, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT).')' }}"></div>
