{{-- An empty list: $icon (a components/icon name), $empty_header, $empty_text. --}}
<x-fruit::empty-state @class(['empty-content', $extra_class ?? null])>
	<x-slot:icon><x-dynamic-component :component="'icon.'.($icon ?? 'circle-check')" class="f-icon" aria-hidden="true" /></x-slot:icon>
	@if (!empty($empty_header))
		<x-slot:title>{{ $empty_header }}</x-slot:title>
	@endif
	{{ $empty_text ?? '' }}
</x-fruit::empty-state>
