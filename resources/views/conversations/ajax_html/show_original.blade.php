{{-- Show Original (ConversationsController::ajaxHtmlShowOriginal()), in a FruitUI dialog: the message
     as it reads, its source, and its headers (a summary for agents, then every header). --}}
@php
    // Tab ids unique per load: the dialog may open more than once on a page.
    $tabs_unique = uniqid();
    $all_headers = $thread->headers ? App\Incoming\OriginalHeaders::all($thread->headers) : [];
    $summary = $thread->headers ? App\Incoming\OriginalHeaders::summary($thread->headers) : [];
    $authentication = $thread->headers ? App\Incoming\OriginalHeaders::authentication($thread->headers) : null;
    $delivered_via = $thread->headers ? App\Incoming\OriginalHeaders::deliveredVia($thread->headers) : null;
@endphp
<div x-data="fruitTabs" class="show-original">
    <x-fruit::tabs :aria-label="__('Original Message')">
        <x-fruit::tab id="tab_preview_{{ $tabs_unique }}_tab" aria-controls="tab_preview_{{ $tabs_unique }}" aria-selected="true">{{ __('Message') }}</x-fruit::tab>
        <x-fruit::tab id="tab_body_{{ $tabs_unique }}_tab" aria-controls="tab_body_{{ $tabs_unique }}" aria-selected="false">{{ __('Source') }}</x-fruit::tab>
        @if ($thread->headers)<x-fruit::tab id="tab_headers_{{ $tabs_unique }}_tab" aria-controls="tab_headers_{{ $tabs_unique }}" aria-selected="false">{{ __('Headers') }}</x-fruit::tab>@endif
    </x-fruit::tabs>

    <div role="tabpanel" id="tab_preview_{{ $tabs_unique }}" aria-labelledby="tab_preview_{{ $tabs_unique }}_tab" class="show-original__panel">
        @if (!$fetched)
            <x-fruit::alert>{{ __('The original message could not be loaded from mail server, below is the latest truncated copy stored in database.') }} (<a href="{{ config('app.freescout_repo') }}/wiki/FAQ#why-does-show-original-window-shows-truncated-message-without-previous-history" target="_blank">{{ __('read more') }}</a>)</x-fruit::alert>
        @endif
        <iframe sandbox="" srcdoc="{!! safe_raw_html(str_replace('"', '&quot;', $body_preview)) !!}" frameborder="0" class="preview-iframe show-original__preview" title="{{ __('Message') }}"></iframe>
    </div>

    <div role="tabpanel" id="tab_body_{{ $tabs_unique }}" aria-labelledby="tab_body_{{ $tabs_unique }}_tab" class="show-original__panel" hidden>
        @if (!$fetched)
            <x-fruit::alert>{{ __('The original message could not be loaded from mail server, below is the latest truncated copy stored in database.') }}</x-fruit::alert>
        @endif
        <div class="show-original__copy"><x-fruit::copy-button size="small" :value="$source">{{ __('Copy Source') }}</x-fruit::copy-button></div>
        <pre class="original-source">{{ $source }}</pre>
    </div>

    @if ($thread->headers)
        <div role="tabpanel" id="tab_headers_{{ $tabs_unique }}" aria-labelledby="tab_headers_{{ $tabs_unique }}_tab" class="show-original__panel" hidden>
            {{-- What an agent looks at first. --}}
            <x-fruit::table class="original-headers" :aria-label="__('Summary')">
                <tbody>
                    @foreach ($summary as $header_name => $header_value)
                        <tr><th scope="row">{{ $header_name }}</th><td>@if ($header_name == 'Message-ID')<code>{{ $header_value }}</code>@else{{ $header_value }}@endif</td></tr>
                    @endforeach
                    <tr>
                        <th scope="row">{{ __('Authentication') }}</th>
                        <td>
                            @if ($authentication === null)
                                <span class="f-muted">{{ __('Not checked') }}</span>
                            @else
                                <span class="f-row original-headers__badges">
                                    @foreach ($authentication as $auth_method => $auth_result)
                                        @if ($auth_result !== null)
                                            <x-fruit::badge :tone="App\Incoming\OriginalHeaders::tone($auth_result)">{{ strtoupper($auth_method) }} {{ $auth_result }}</x-fruit::badge>
                                        @endif
                                    @endforeach
                                </span>
                            @endif
                        </td>
                    </tr>
                    @if ($delivered_via)
                        <tr><th scope="row">{{ __('Delivered via') }}</th><td>{{ $delivered_via }}</td></tr>
                    @endif
                </tbody>
            </x-fruit::table>

            {{-- Every header, in order, unfolded. --}}
            <x-fruit::disclosure :title="__('All Headers (:count)', ['count' => count($all_headers)])" class="original-headers__all">
                <div class="show-original__copy"><x-fruit::copy-button size="small" :value="$thread->headers">{{ __('Copy Headers') }}</x-fruit::copy-button></div>
                <x-fruit::table class="original-headers" :aria-label="__('All Headers (:count)', ['count' => count($all_headers)])">
                    <tbody>
                        @foreach ($all_headers as [$header_name, $header_value])
                            <tr><th scope="row">{{ $header_name }}</th><td><code>{{ $header_value }}</code></td></tr>
                        @endforeach
                    </tbody>
                </x-fruit::table>
            </x-fruit::disclosure>
        </div>
    @endif
</div>
<footer class="f-dialog__footer">
    @if ($raw_kept)
        <a href="{{ route('threads.original_eml', ['thread_id' => $thread->id]) }}" class="f-button" download><x-icon.download class="f-icon" aria-hidden="true" /> {{ __('Download .eml') }}</a>
    @endif
    <span class="f-toolbar__spacer"></span>
    <form method="dialog"><x-fruit::button type="submit" variant="primary" autofocus>{{ __('Done') }}</x-fruit::button></form>
</footer>
