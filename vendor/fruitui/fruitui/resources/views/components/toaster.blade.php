@props(['duration' => 4000, 'message' => null])
@php($message = \FruitUI\Support\ComponentContract::toaster($duration, $message, $attributes))
<div role="status" {{ $attributes->except('role')->class(['f-toast']) }}
    x-data="{{ 'fruitToast('.json_encode(['duration' => (int) $duration, 'message' => $message], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT).')' }}"
    x-show="notice" x-text="notice" x-cloak></div>
