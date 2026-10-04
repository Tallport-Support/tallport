{{-- A mailbox's name and signature (a FruitUI remote dialog from the sidebar's mailbox menu); the rest is on its settings pages. --}}
<form class="f-stack modal-form" x-data x-on:submit.prevent="Tallport.post(@js(route('mailboxes.quick_settings.save', ['id' => $mailbox->id])), $el).then(response => { if (Tallport.result(response)) { $el.closest('dialog').close(); Livewire.navigate(location.href); } })">
    @if ($can_rename)
        <x-fruit::field :label="__('Mailbox Name')">
            <x-fruit::input name="name" :value="$mailbox->name" maxlength="40" required />
        </x-fruit::field>
    @endif
    @if ($can_signature)
        <x-fruit::field :label="__('Email Signature')" class="signature-editor">
            <x-editor id="quick-signature" name="signature" rows="6" vars>{{ $mailbox->signature }}</x-editor>
        </x-fruit::field>
    @endif
    <footer class="f-dialog__footer">
        <a href="{{ route('mailboxes.update', ['id' => $mailbox->id]) }}" class="f-button f-button--ghost">{{ __('All Settings') }}</a>
        <x-fruit::button x-on:click="$el.closest('dialog').close()">{{ __('Cancel') }}</x-fruit::button>
        <x-fruit::button type="submit" variant="primary">{{ __('Save') }}</x-fruit::button>
    </footer>
</form>
