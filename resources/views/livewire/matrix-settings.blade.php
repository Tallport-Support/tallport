<div class="settings-form f-stack" @if ($identity && in_array($identity->status, ['ready', 'verification'])) wire:poll.5s="poll" @endif>
    <p class="f-help">{{ __('Use a Matrix account for private customer chats, including encrypted messages.') }}</p>
    @if ($error || ($identity && $identity->error))
        <x-fruit::alert tone="danger">{{ is_string($error) ? $error : ((!$identity || $identity->status === 'login') && !$checked_homeserver ? __('Check the homeserver address. Password login and Matrix v1.11 or newer are required.') : __('Check the Matrix connection and device verification.')) }}</x-fruit::alert>
    @endif
    @if (!$identity || $identity->status === 'login')
        @if (!$checked_homeserver)
            <form wire:submit="checkHomeserver" class="f-stack" wire:key="matrix-homeserver">
                <x-fruit::field :label="__('Homeserver URL')" name="homeserver">
                    <x-fruit::input wire:model="homeserver" inputmode="url" placeholder="matrix.example.org" required />
                </x-fruit::field>
                <x-fruit::button type="submit" variant="primary" wire:loading.attr="disabled">{{ __('Next') }}</x-fruit::button>
            </form>
        @else
            <form x-data="{ password: '' }" x-on:submit.prevent="$wire.connect(password); password = ''" class="f-stack" wire:key="matrix-login">
                <div class="f-stack">
                    <span>{{ $checked_homeserver }}</span>
                    <x-fruit::button type="button" wire:click="changeHomeserver" wire:loading.attr="disabled">{{ __('Change') }}</x-fruit::button>
                </div>
                <x-fruit::field :label="__('Matrix account')" name="matrix_user">
                    <x-fruit::input wire:model="matrix_user" placeholder="support / @support:example.org" autocomplete="username" required />
                </x-fruit::field>
                <x-fruit::field :label="__('Password')">
                    <x-fruit::input x-model="password" id="matrix-password" type="password" autocomplete="current-password" required />
                </x-fruit::field>
                <x-fruit::button type="submit" variant="primary" wire:loading.attr="disabled">{{ __('Connect') }}</x-fruit::button>
            </form>
        @endif
    @else
        <x-fruit::card class="f-stack">
            <strong>{{ $identity->user_id }}</strong>
            <span>{{ $identity->homeserver }}</span>
            <x-fruit::badge :tone="$identity->isVerified() ? 'success' : 'warning'">{{ $identity->status === 'disabled' ? __('Disabled') : ($identity->isReady() ? __('Connected') : __('Verify this device')) }}</x-fruit::badge>
            @if ($identity->status === 'verification')<p class="f-help">{{ __('This device is unverified. You can still send and receive messages.') }}</p>@endif
            <span class="f-help">{{ __('Device') }}: <code>{{ $identity->device_id }}</code></span>
            <code class="f-help">Ed25519: {{ $fingerprint }}</code>
            @if ($identity->last_synced_at)<span class="f-help">{{ __('Last successful sync') }}: {{ $identity->last_synced_at->diffForHumans() }}</span>@endif
            <x-fruit::button wire:click="toggleEnabled" wire:loading.attr="disabled">{{ $identity->status === 'disabled' ? __('Enable') : __('Disable') }}</x-fruit::button>
            <x-fruit::button wire:click="disconnect" wire:loading.attr="disabled">{{ __('Disconnect') }}</x-fruit::button>
        </x-fruit::card>
        @if ($identity->status === 'verification')
            <p>{{ __('Verify this device from a trusted Matrix client.') }}</p>
            @if ($verification)
                <x-fruit::card class="f-stack">
                    <strong>{{ __('Verify this device') }}</strong>
                    <span>{{ __('Device') }}: <code>{{ $verification['peer'] }}</code></span>
                    @if ($verification['phase'] === 'requested')
                        <x-fruit::button variant="primary" wire:click="verify('accept')" wire:loading.attr="disabled">{{ __('Accept') }}</x-fruit::button>
                    @elseif ($numbers && !$verification['confirmed'])
                        <p>{{ __('Compare all three numbers with your trusted Matrix client.') }}</p>
                        <strong class="f-title-2">{{ implode(' — ', $numbers) }}</strong>
                        <x-fruit::button variant="primary" wire:click="verify('confirm')" wire:loading.attr="disabled">{{ __('The numbers match') }}</x-fruit::button>
                    @else
                        <p>{{ __('Waiting for your Matrix client to finish verification.') }}</p>
                    @endif
                    <x-fruit::button wire:click="verify('cancel')" wire:loading.attr="disabled">{{ __('Cancel') }}</x-fruit::button>
                </x-fruit::card>
            @endif
        @endif
        @foreach ($reviews as $review)
            <x-fruit::card class="f-stack" wire:key="review-{{ $review->id }}">
                <strong>{{ __('Review Matrix device keys') }}</strong>
                <span>{{ $review->value['user'] }}</span>
                <p>{{ __('Confirm this account and its keys to resume sending.') }}</p>
                <code class="f-help">{{ $review->value['keys']['master'] }}</code>
                @foreach ($review->value['keys']['devices'] as $device)
                    <span>{{ $device['id'] }} — Ed25519: <code>{{ $device['signing'] }}</code></span>
                    <span>Curve25519: <code>{{ $device['curve'] }}</code></span>
                @endforeach
                <x-fruit::button wire:click="approveDevices({{ $review->id }}, '{{ $review->value['fingerprint'] }}')" wire:loading.attr="disabled">{{ __('I have checked these keys') }}</x-fruit::button>
            </x-fruit::card>
        @endforeach
        <x-fruit::card class="f-stack">
            <p class="f-help">{{ __('After restoring a backup, reset this device before reconnecting. Received message keys are kept.') }}</p>
            <x-fruit::button wire:click="resetDevice" wire:confirm="{{ __('Reset the Matrix device and reconnect?') }}" wire:loading.attr="disabled">{{ __('Reset device') }}</x-fruit::button>
        </x-fruit::card>
    @endif
</div>
