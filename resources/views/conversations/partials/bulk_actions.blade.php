{{-- The list header's selection bar (App\Livewire\ConversationList): what to do with the selected conversations. --}}
@php
    // Modules' buttons go in the "More" menu.
    ob_start();
    \Eventy::action('bulk_actions.before_delete', $mailbox ?? null);
    $bulk_more = trim(ob_get_clean());
@endphp
<x-fruit::selection-bar id="conversations-bulk-actions" class="conv-bulk-actions" :count="count($selected ?? [])" :aria-label="__('Selected conversations')">
    @if (!empty($mailbox))
        <x-fruit::menu :title="__('Assignee')" class="conv-user">
            <x-slot:trigger class="f-button--ghost f-button--icon" :aria-label="__('Assignee')" :title="__('Assignee')"><x-heroicon-o-user class="f-icon" aria-hidden="true" /></x-slot:trigger>
            <x-fruit::menu-link href="#" data-user_id="-1" wire:click.prevent="assign(-1)">{{ __("Anyone") }}</x-fruit::menu-link>
            <x-fruit::menu-link href="#" :data-user_id="Auth::user()->id" wire:click.prevent="assign({{ Auth::user()->id }})">{{ __("Me") }}</x-fruit::menu-link>
            @foreach ($mailbox->usersAssignable() as $user)
                @if ($user->id != Auth::user()->id)
                    @php
                        $a_class = \Eventy::filter('assignee_list.a_class', '', $user);
                    @endphp
                    <x-fruit::menu-link href="#" :data-user_id="$user->id" :class="$a_class" wire:click.prevent="assign({{ $user->id }})">{{ $user->getFullName() }}@action('assignee_list.item_append', $user)</x-fruit::menu-link>
                @endif
            @endforeach
        </x-fruit::menu>
    @endif
    <x-fruit::menu :title="__('Status')" class="conv-status">
        <x-slot:trigger class="f-button--ghost f-button--icon" :aria-label="__('Status')" :title="__('Status')"><x-heroicon-o-flag class="f-icon" aria-hidden="true" /></x-slot:trigger>
        @foreach (App\Conversation::$statuses as $status => $dummy)
            <x-fruit::menu-link href="#" :data-status="$status" wire:click.prevent="changeStatus({{ $status }})">{{ App\Conversation::statusCodeToName($status) }}</x-fruit::menu-link>
        @endforeach
    </x-fruit::menu>
    @if (Auth::user()->can('delete', new App\Conversation()))
        <x-fruit::button variant="ghost" class="f-button--icon conv-delete" :aria-label="__('Delete')" :title="__('Delete')" x-on:click="Tallport.confirm({message: {{ \Illuminate\Support\Js::from(__('Delete the conversations?')) }}, confirm: {{ \Illuminate\Support\Js::from(__('Delete')) }}, tone: 'danger'}).then(ok => ok && $wire.delete())"><x-heroicon-o-trash class="f-icon" aria-hidden="true" /></x-fruit::button>
    @endif
    @if ($bulk_more !== '')
        <x-fruit::menu :title="__('More')" class="conv-bulk-more">
            <x-slot:trigger class="f-button--ghost f-button--icon" :aria-label="__('More')" :title="__('More')"><x-heroicon-o-ellipsis-horizontal class="f-icon" aria-hidden="true" /></x-slot:trigger>
            {!! $bulk_more !!}
        </x-fruit::menu>
    @endif
    <x-fruit::button variant="ghost" class="f-button--icon conv-checkbox-clear" :aria-label="__('Clear selection')" :title="__('Clear selection')" wire:click="$set('selected', [])"><x-heroicon-o-x-mark class="f-icon" aria-hidden="true" /></x-fruit::button>
</x-fruit::selection-bar>
