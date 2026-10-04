<x-fruit::selection-bar id="conversations-bulk-actions" class="conv-bulk-actions" x-bind:data-count="selected.length">
    <x-fruit::button variant="ghost" size="small" class="conv-checkbox-clear" :aria-label="__('Clear')" :title="__('Clear')" x-on:click="selected = []"><x-heroicon-o-x-mark class="f-icon" aria-hidden="true" /></x-fruit::button>
    @if (!empty($mailbox))
        <x-fruit::menu :title="__('Assignee')" class="conv-user">
            <x-slot:trigger class="f-button--small"><x-heroicon-o-user class="f-icon" aria-hidden="true" /> {{ __('Assignee') }}</x-slot:trigger>
            <x-fruit::menu-link href="#" data-user_id="-1" x-on:click.prevent="$wire.assign(-1, selected)">{{ __("Anyone") }}</x-fruit::menu-link>
            <x-fruit::menu-link href="#" :data-user_id="Auth::user()->id" x-on:click.prevent="$wire.assign({{ Auth::user()->id }}, selected)">{{ __("Me") }}</x-fruit::menu-link>
            @foreach ($mailbox->usersAssignable() as $user)
                @if ($user->id != Auth::user()->id)
                    @php
                        $a_class = \Eventy::filter('assignee_list.a_class', '', $user);
                    @endphp
                    <x-fruit::menu-link href="#" :data-user_id="$user->id" :class="$a_class" x-on:click.prevent="$wire.assign({{ $user->id }}, selected)">{{ $user->getFullName() }}@action('assignee_list.item_append', $user)</x-fruit::menu-link>
                @endif
            @endforeach
        </x-fruit::menu>
    @endif
    <x-fruit::menu :title="__('Status')" class="conv-status">
        <x-slot:trigger class="f-button--small"><x-heroicon-o-flag class="f-icon" aria-hidden="true" /> {{ __('Status') }}</x-slot:trigger>
        @foreach (App\Conversation::$statuses as $status => $dummy)
            <x-fruit::menu-link href="#" :data-status="$status" x-on:click.prevent="$wire.changeStatus({{ $status }}, selected)">{{ App\Conversation::statusCodeToName($status) }}</x-fruit::menu-link>
        @endforeach
    </x-fruit::menu>
    @action('bulk_actions.before_delete', $mailbox ?? null)
    @if (Auth::user()->can('delete', new App\Conversation()))
        <x-fruit::button variant="danger" size="small" class="conv-delete" x-on:click="Tallport.confirm({message: {{ \Illuminate\Support\Js::from(__('Delete the conversations?')) }}, confirm: {{ \Illuminate\Support\Js::from(__('Delete')) }}, tone: 'danger'}).then(ok => ok && $wire.delete(selected))"><x-heroicon-o-trash class="f-icon" aria-hidden="true" /> {{ __('Delete') }}</x-fruit::button>
    @endif
</x-fruit::selection-bar>
