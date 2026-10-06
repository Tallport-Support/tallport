{{-- Images from other servers aren't shown (App\Misc\ExternalImages). --}}
<x-fruit::alert tone="warning" class="external-images-notice" :data-thread-id="$thread->id">
    <p>{{ __('Images from other servers are not shown.') }}</p>
    <div class="external-images-actions">
        <x-fruit::button variant="ghost" size="small" class="external-images-show" data-action="display">{{ __('Show Images') }}</x-fruit::button>
        @if ($thread->customer_cached)
            <x-fruit::button variant="ghost" size="small" class="external-images-show" data-action="display_customer">{{ __('Always Show Images from :customer', ['customer' => $thread->customer_cached->getFullName(true)]) }}</x-fruit::button>
        @endif
    </div>
</x-fruit::alert>
