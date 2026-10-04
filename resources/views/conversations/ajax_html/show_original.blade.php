@php
	// If tabs ids will be same, when tabs loaded second time they will not work
	$tabs_unique = time();
@endphp
<div x-data="fruitTabs" class="show-original">
	<x-fruit::tabs :aria-label="__('Original Message')">
		<x-fruit::tab id="tab_preview_{{ $tabs_unique }}_tab" aria-controls="tab_preview_{{ $tabs_unique }}" aria-selected="true">{{ __('Preview') }}</x-fruit::tab>
		<x-fruit::tab id="tab_body_{{ $tabs_unique }}_tab" aria-controls="tab_body_{{ $tabs_unique }}" aria-selected="false">{{ __('Body') }}</x-fruit::tab>
		@if ($thread->headers)<x-fruit::tab id="tab_headers_{{ $tabs_unique }}_tab" aria-controls="tab_headers_{{ $tabs_unique }}" aria-selected="false">{{ __('Email Headers') }}</x-fruit::tab>@endif
	</x-fruit::tabs>

	<div role="tabpanel" id="tab_preview_{{ $tabs_unique }}" aria-labelledby="tab_preview_{{ $tabs_unique }}_tab" class="show-original__panel">
		@if (!$fetched)
			<x-fruit::alert>{{ __('The original message could not be loaded from mail server, below is the latest truncated copy stored in database.') }} (<a href="{{ config('app.freescout_repo') }}/wiki/FAQ#why-does-show-original-window-shows-truncated-message-without-previous-history" target="_blank">{{ __('read more') }}</a>)</x-fruit::alert>
		@endif
		<iframe sandbox="" srcdoc="{!! safe_raw_html(str_replace('"', '&quot;', $body_preview)) !!}" frameborder="0" class="preview-iframe tab-body"></iframe>
	</div>
	<div role="tabpanel" id="tab_body_{{ $tabs_unique }}" aria-labelledby="tab_body_{{ $tabs_unique }}_tab" class="show-original__panel" hidden>
		<pre class="pre-wrap">{{ $thread->getBodyOriginal() }}</pre>
	</div>
	@if ($thread->headers)<div role="tabpanel" id="tab_headers_{{ $tabs_unique }}" aria-labelledby="tab_headers_{{ $tabs_unique }}_tab" class="show-original__panel" hidden>
		<pre class="pre-wrap">{{ $thread->headers }}</pre>
	</div>@endif
</div>
