{{-- The password, asked for in place (App\Livewire\PasswordGate): centred where the settings will show. --}}
<x-fruit::empty-state role="region" aria-labelledby="password-gate-title" class="password-gate">
    <x-slot:icon><x-icon.lock class="f-icon" aria-hidden="true" /></x-slot:icon>
    <x-slot:title><h2 id="password-gate-title">{{ __('Confirm Your Password') }}</h2></x-slot:title>
    {{ $description ?: __('These settings need your password again.') }}
    <x-slot:actions>
        <form wire:submit="confirm" class="f-stack password-gate__form">
            {{-- For password managers: whose password this is. --}}
            <input type="text" class="f-sr-only" autocomplete="username" value="{{ auth()->user()->email }}" readonly tabindex="-1" aria-hidden="true">
            <x-fruit::field :label="__('Password')">
                <x-fruit::input type="password" name="password" wire:model="password" autocomplete="current-password" required autofocus />
            </x-fruit::field>
            <div class="f-row password-gate__actions">
                <x-fruit::button type="submit" variant="primary">{{ __('Confirm') }}</x-fruit::button>
            </div>
        </form>
    </x-slot:actions>
</x-fruit::empty-state>
