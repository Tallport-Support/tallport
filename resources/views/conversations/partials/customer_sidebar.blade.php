@if (!empty($customer))
    <div class="conv-customer-block">
        @if (isset($conversation))
            <x-fruit::menu :title="__('Settings')" class="customer-trigger">
                <x-slot:trigger class="f-button--ghost f-button--icon f-button--small" :aria-label="__('Settings')"><x-heroicon-o-cog-6-tooth class="f-icon" aria-hidden="true" /></x-slot:trigger>
                <x-fruit::menu-link :href="route('customers.update', ['id' => $customer->id])">{{ __('Edit Profile') }}</x-fruit::menu-link>
                @if (!$conversation->isChat())
                    <x-fruit::menu-link :href="route('conversations.ajax_html', array_merge(['action' => 'change_customer'], \Request::all(), ['conversation_id' => $conversation->id]))" data-fruit-dialog-url :data-fruit-dialog-title="__('Change Customer')">{{ __('Change Customer') }}</x-fruit::menu-link>
                @endif
                @if ($customer->getMeta(App\Misc\ExternalImages::META_KEY))
                    <x-fruit::menu-link href="#" class="external-images-block" :data-customer-id="$customer->id">{{ __('Hide images from other servers') }}</x-fruit::menu-link>
                @endif
                <ul class="menu-module-items">{{ \Eventy::action('conversation.customer.menu', $customer, $conversation) }}{{ \Eventy::action('customer_profile.menu', $customer, $conversation) }}</ul>
            </x-fruit::menu>
        @endif
        @include('customers/profile_snippet', ['customer' => $customer, 'main_email' => $conversation->customer_email ?? '', 'conversation' => $conversation ?? null])
    </div>
    @if (isset($conversation) && isset($mailbox))
    	@action('conversation.before_prev_convs', $customer, $conversation, $mailbox)
    @endif
    @if (count($prev_conversations))
        @include('conversations/partials/prev_convs_short')
    @endif
    @if (isset($conversation) && isset($mailbox))
        @include('conversations/partials/attachments_sidebar')
    	@action('conversation.after_prev_convs', $customer, $conversation, $mailbox)
    @endif
@endif
