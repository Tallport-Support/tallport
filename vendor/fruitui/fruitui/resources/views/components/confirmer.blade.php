@php(\FruitUI\Support\ComponentContract::validate('confirmer', $attributes))
@php($id = $attributes->get('id', 'fruit-confirm'))
{{-- One per layout. Script fills it from confirm() and $confirm(); wire:ignore keeps Livewire morphs out. --}}
<dialog role="alertdialog" {{ $attributes->except('role')->merge([
    'id' => $id,
    'aria-labelledby' => $id.'-title',
    'aria-describedby' => $id.'-message',
    'data-fruit-confirm-label' => __('OK'),
    'data-fruit-cancel-label' => __('Cancel'),
])->class(['f-dialog', 'f-confirm']) }} x-data="fruitConfirmer" wire:ignore>
    <form method="dialog">
        <div class="f-confirm__body">
            <h2 class="f-confirm__title" id="{{ $id }}-title" x-text="request.title"></h2>
            <p class="f-confirm__message" id="{{ $id }}-message" x-text="request.message" x-show="request.message"></p>
        </div>
        <div class="f-confirm__actions">
            <button class="f-button" type="submit" value="cancel" x-text="request.cancel"></button>
            <button class="f-button" type="submit" value="confirm" x-bind:class="request.tone === 'danger' ? 'f-button--danger' : 'f-button--primary'" x-text="request.confirm"></button>
        </div>
    </form>
</dialog>
