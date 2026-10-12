{{-- A user's permissions (App\Livewire\UserPermissions): every change is saved as it's made. --}}
<x-fruit::page width="narrow">
    @if (count($all_mailboxes))
        <x-fruit::form-section :title="__('Mailboxes')" :level="3" :footer="$user->isAdmin() ? __('Administrators open every mailbox, whichever are chosen.') : null">
            <x-fruit::fieldset>
                <legend>{{ __(':first_name has access to the selected mailboxes:', ['first_name' => $user->first_name]) }}</legend>
                @foreach ($all_mailboxes as $mailbox_option)
                    <x-fruit::checkbox id="mailbox-{{ $mailbox_option->id }}" value="{{ $mailbox_option->id }}" wire:model.live="mailboxes">{{ $mailbox_option->name }}</x-fruit::checkbox>
                @endforeach
            </x-fruit::fieldset>
            <div class="f-form-row">
                <div class="f-row">
                    <x-fruit::button variant="ghost" size="small" wire:click="selectAllMailboxes(true)">{{ __('All') }}</x-fruit::button>
                    <x-fruit::button variant="ghost" size="small" wire:click="selectAllMailboxes(false)">{{ __('None') }}</x-fruit::button>
                </div>
            </div>
        </x-fruit::form-section>
    @endif

    @if (!$user->isAdmin())
        {{-- Each permission: the user's own, or the default (Settings » General). --}}
        <x-fruit::form-section :title="__('User Permissions')" :level="3">
            @foreach (App\User::getUserPermissionsList() as $permission_id)
                <x-fruit::field :label="App\User::getUserPermissionName($permission_id)" layout="row">
                    <x-fruit::select id="user_permission_{{ $permission_id }}" wire:model.live="permissions.{{ $permission_id }}">
                        <option value="">{{ $user->hasPermission($permission_id, false) ? __('Default (Allowed)') : __('Default (Not Allowed)') }}</option>
                        <option value="1">{{ __('Allowed') }}</option>
                        <option value="0">{{ __('Not Allowed') }}</option>
                    </x-fruit::select>
                </x-fruit::field>
            @endforeach
        </x-fruit::form-section>
    @endif
</x-fruit::page>
