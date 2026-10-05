{{-- Move the conversation to another mailbox (a FruitUI remote dialog; tallportMove in public/js/conversations.js). --}}
<div class="f-stack modal-form" x-data="tallportMove({{ $conversation->id }})">
    <x-fruit::field :label="__('Select Mailbox')" control-id="move-conv-mailbox-id">
        <x-fruit::select id="move-conv-mailbox-id" x-model="mailbox_id" x-bind:disabled="email !== ''">
            @foreach ($mailboxes as $mailbox)
                @if ($mailbox->id != $conversation->mailbox_id)
                    <option value="{{ $mailbox->id }}">{{ $mailbox->name }} ({{ $mailbox->email }})</option>
                @endif
            @endforeach
        </x-fruit::select>
    </x-fruit::field>
    <x-fruit::field :label="__('Or Enter Mailbox Email')" control-id="move-conv-mailbox-email">
        <x-fruit::input type="email" id="move-conv-mailbox-email" x-model.trim="email" />
    </x-fruit::field>
    <div class="modal-form__actions">
        <button class="f-button f-button--primary btn-move-conv" type="button" x-on:click="move($el)">{{ __('Move') }}</button>
    </div>
</div>
