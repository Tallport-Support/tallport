{{-- Images from other servers aren't shown (App\Misc\ExternalImages). --}}
<div class="f-alert f-alert--warning external-images-notice" data-thread-id="{{ $thread->id }}">
    <small><strong>{{ __('Images from other servers are not shown.') }}</strong>
        <a href="#" class="external-images-show" data-action="display">{{ __('Show images') }}</a>
        @if ($thread->customer_cached)
            · <a href="#" class="external-images-show" data-action="display_customer">{{ __('Always show images from :customer', ['customer' => $thread->customer_cached->getFullName(true)]) }}</a>
        @endif
    </small>
</div>
