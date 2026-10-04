@props(['duration' => 4000, 'message' => null, 'tone' => null])
@php([$message, $tone] = \FruitUI\Support\ComponentContract::toaster($duration, $message, $tone, $attributes))
<div role="status" aria-live="polite" {{ $attributes->except('role')->class(['f-toast']) }}
    x-data="{{ 'fruitToast('.json_encode(['duration' => (int) $duration, 'message' => $message, 'tone' => $tone], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT).')' }}"
    x-show="notice" x-text="notice" x-cloak></div>
