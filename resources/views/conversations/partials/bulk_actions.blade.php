<div id="conversations-bulk-actions" class="f-selection-bar conv-bulk-actions" role="region" aria-label="{{ __('Selection') }}" hidden data-count-one="{{ trans_choice(':count selected', 1, ['count' => 1]) }}" data-count-other="{{ trans_choice(':count selected', 2, ['count' => '__count__']) }}">
    <x-fruit::button variant="ghost" size="small" class="conv-checkbox-clear" :aria-label="__('Clear')" :title="__('Clear')"><x-heroicon-o-x-mark class="f-icon" aria-hidden="true" /></x-fruit::button>
    <span class="f-selection-bar__count" role="status"></span>
    @if (!empty($mailbox))
        <x-fruit::menu :title="__('Assignee')" class="conv-user">
            <x-slot:trigger class="f-button--small"><x-heroicon-o-user class="f-icon" aria-hidden="true" /> {{ __('Assignee') }}</x-slot:trigger>
            <x-fruit::menu-link href="#" data-user_id="-1">{{ __("Anyone") }}</x-fruit::menu-link>
            <x-fruit::menu-link href="#" :data-user_id="Auth::user()->id">{{ __("Me") }}</x-fruit::menu-link>
            @foreach ($mailbox->usersAssignable() as $user)
                @if ($user->id != Auth::user()->id)
                    @php
                        $a_class = \Eventy::filter('assignee_list.a_class', '', $user);
                    @endphp
                    <x-fruit::menu-link href="#" :data-user_id="$user->id" :class="$a_class">{{ $user->getFullName() }}@action('assignee_list.item_append', $user)</x-fruit::menu-link>
                @endif
            @endforeach
        </x-fruit::menu>
    @endif
    <x-fruit::menu :title="__('Status')" class="conv-status">
        <x-slot:trigger class="f-button--small"><x-heroicon-o-flag class="f-icon" aria-hidden="true" /> {{ __('Status') }}</x-slot:trigger>
        @foreach (App\Conversation::$statuses as $status => $dummy)
            <x-fruit::menu-link href="#" :data-status="$status">{{ App\Conversation::statusCodeToName($status) }}</x-fruit::menu-link>
        @endforeach
    </x-fruit::menu>
    @action('bulk_actions.before_delete', $mailbox ?? null)
    @if (Auth::user()->can('delete', new App\Conversation()))
        <x-fruit::button variant="danger" size="small" class="conv-delete"><x-heroicon-o-trash class="f-icon" aria-hidden="true" /> {{ __('Delete') }}</x-fruit::button>
    @endif
</div>

<div id="conversations-bulk-actions-delete-modal" class="hide">
    <div class="text-center">
        <div class="text-larger margin-top-10">{{ __("Delete the conversations?") }}</div>
        <div class="form-group margin-top">
            <button class="btn btn-primary delete-conversation-ok" data-loading-text="{{ __("Deleting") }}…">{{ __("Delete") }}</button>
            <button class="btn btn-link" data-dismiss="modal">{{ __("Cancel") }}</button>
        </div>
    </div>
</div>
