{{-- The open conversation's toolbar (App\Livewire\ConversationToolbar). --}}
<div id="conv-toolbar" class="conv-toolbar" x-data="{ following: @js($is_following) }">
    @php
        $delete_confirm = 'Tallport.confirm({message: '.\Illuminate\Support\Js::from(__('Delete this conversation?')).', confirm: '.\Illuminate\Support\Js::from(__('Delete')).', tone: \'danger\'}).then(ok => ok && $wire.delete())';
    @endphp
    <div class="conv-actions f-toolbar__group">
        @foreach ($toolbar_actions as $action_key => $action)
            @if (!empty($action['url']))
                <a href="{{ $action['url']($conversation) }}" class="f-button f-button--ghost f-button--icon {{ $action['class'] }} conv-action @if (!empty($action['mobile_only'])) hidden-xs @endif"
                    @if (!empty($action['attrs']))
                        @foreach ($action['attrs'] as $attr_key => $attr_value)
                            {{ $attr_key }}="{{ $attr_value }}"
                        @endforeach
                    @endif
                    title="{{ $action['label'] }}" aria-label="{{ $action['label'] }}">@include('conversations/partials/action_icon', ['icon' => $action['icon']])</a>
            @else
                <button type="button" class="f-button f-button--ghost f-button--icon {{ $action['class'] }} conv-action @if ($action_key === 'delete' || !empty($action['mobile_only'])) hidden-xs @endif"
                    @if (!empty($action['attrs']))
                        @foreach ($action['attrs'] as $attr_key => $attr_value)
                            {{ $attr_key }}="{{ $attr_value }}"
                        @endforeach
                    @endif
                    @if (in_array($action_key, ['delete', 'delete_mobile'])) x-on:click="{{ $delete_confirm }}" @endif
                    @if (in_array($action_key, ['reply', 'note', 'forward'])) x-on:click="Livewire.dispatch('composer-open', {mode: '{{ $action_key }}'})" @endif
                    title="{{ $action['label'] }}" aria-label="{{ $action['label'] }}">@include('conversations/partials/action_icon', ['icon' => $action['icon']])</button>
            @endif
        @endforeach

        @if (App\Ai\Drafts::allowed(Auth::user(), $conversation))
            <button type="button" class="f-button f-button--ghost f-button--icon conv-action ai-draft-action" x-on:click="$dispatch('ai-draft-request')" title="{{ __('Draft with AI') }}" aria-label="{{ __('Draft with AI') }}"><x-icon.sparkles class="f-icon" aria-hidden="true" /></button>
        @endif

        @action('conversation.action_buttons', $conversation, $mailbox)

        <x-fruit::menu :title="__('More Actions')" class="conv-action conv-more-actions">
            <x-slot:trigger class="f-button--ghost f-button--icon" :aria-label="__('More Actions')" :title="__('More Actions')"><x-icon.ellipsis class="f-icon" aria-hidden="true" /></x-slot:trigger>
            <ul class="menu-module-items">@action('conversation.prepend_action_buttons', $conversation, $mailbox)</ul>
            @foreach ($dropdown_actions as $action_key => $action)
                @if ($action_key === 'delete_mobile')
                    <x-fruit::menu-link href="#" :class="$action['class'].' hidden-lg hidden-md hidden-sm'" x-on:click.prevent="{{ $delete_confirm }}">@include('conversations/partials/action_icon', ['icon' => $action['icon']]) {{ $action['label'] }}</x-fruit::menu-link>
                @elseif (!empty($action['has_opposite']))
                    <x-fruit::menu-link href="#" :class="$action['class']" data-follow-action="follow" x-show="!following" x-on:click.prevent="$wire.follow(true).then(ok => ok && (following = true))">@include('conversations/partials/action_icon', ['icon' => $action['icon']]) {{ $action['label'] }}</x-fruit::menu-link>
                    <x-fruit::menu-link href="#" :class="$action['opposite']['class']" data-follow-action="unfollow" x-show="following" x-on:click.prevent="$wire.follow(false).then(ok => ok && (following = false))">@include('conversations/partials/action_icon', ['icon' => $action['icon']]) {{ $action['opposite']['label'] }}</x-fruit::menu-link>
                @else
                    <a role="menuitem" href="{{ !empty($action['url']) ? $action['url']($conversation) : '#' }}" class="f-menu-item {{ $action['class'] }}"
                        @if (in_array($action_key, ['reply', 'note', 'forward'])) x-on:click.prevent="Livewire.dispatch('composer-open', {mode: '{{ $action_key }}'})" @endif
                        @if (!empty($action['attrs']))
                            @foreach ($action['attrs'] as $attr_key => $attr_value)
                                {{ $attr_key }}="{{ $attr_value }}"
                            @endforeach
                        @endif
                        >@include('conversations/partials/action_icon', ['icon' => $action['icon']]) {{ $action['label'] }}</a>
                @endif
            @endforeach
            <ul class="menu-module-items">@action('conversation.append_action_buttons', $conversation, $mailbox)</ul>
        </x-fruit::menu>
</div>

    <span class="f-toolbar__spacer"></span>

    <ul class="conv-info">
        @action('conversation.convinfo.prepend', $conversation, $mailbox)
        @if ($conversation->state != App\Conversation::STATE_DELETED)
            <li>
                <x-fruit::menu :title="__('Assignee')" id="conv-assignee" class="conv-user">
                    <x-slot:trigger class="f-button--small" :title="__('Assignee').': '.$conversation->getAssigneeName(true)"><x-icon.user class="f-icon" aria-hidden="true" /> <span class="conv-info-val"><span>{{ $conversation->getAssigneeName(true) }}</span></span></x-slot:trigger>
                    <x-fruit::menu-link href="#" data-user_id="-1" wire:click.prevent="assign(-1)" :class="!$conversation->user_id ? 'active' : ''" :aria-current="!$conversation->user_id ? 'true' : null">{{ __("Anyone") }}</x-fruit::menu-link>
                    <x-fruit::menu-link href="#" :data-user_id="Auth::user()->id" wire:click.prevent="assign({{ Auth::user()->id }})" :class="$conversation->user_id == Auth::user()->id ? 'active' : ''" :aria-current="$conversation->user_id == Auth::user()->id ? 'true' : null">{{ __("Me") }}</x-fruit::menu-link>
                    @foreach ($mailbox->usersAssignable() as $assignable_user)
                        @if ($assignable_user->id != Auth::user()->id)
                            @php
                                $a_class = \Eventy::filter('assignee_list.a_class', '', $assignable_user);
                            @endphp
                            <x-fruit::menu-link href="#" :data-user_id="$assignable_user->id" wire:click.prevent="assign({{ $assignable_user->id }})" :class="trim($a_class.($conversation->user_id == $assignable_user->id ? ' active' : ''))" :aria-current="$conversation->user_id == $assignable_user->id ? 'true' : null">{{ $assignable_user->getFullName() }}@action('assignee_list.item_append', $assignable_user)</x-fruit::menu-link>
                        @endif
                    @endforeach
                </x-fruit::menu>
            </li>
        @endif
        <li>
            @php
                $status_tones = ['success' => 'success', 'info' => 'accent', 'warning' => 'warning', 'danger' => 'danger'];
            @endphp
            <x-fruit::menu :title="__('Status')" id="conv-status" class="conv-status">
                @if ($conversation->state != App\Conversation::STATE_DELETED)
                    <x-slot:trigger class="f-button--small" :title="__('Status').': '.$conversation->getStatusName()"><span class="f-badge f-badge--{{ $status_tones[$conversation->getStatusClass()] ?? 'neutral' }} conv-status-dot" aria-hidden="true"></span> <span class="conv-info-val"><span>{{ $conversation->getStatusName() }}</span></span></x-slot:trigger>
                    @if (!$conversation->isSpam())
                        @foreach (App\Conversation::$statuses as $status => $dummy)
                            <x-fruit::menu-link href="#" :data-status="$status" wire:click.prevent="changeStatus({{ $status }})" :class="$conversation->status == $status ? 'active' : ''" :aria-current="$conversation->status == $status ? 'true' : null">{{ App\Conversation::statusCodeToName($status) }}</x-fruit::menu-link>
                        @endforeach
                    @else
                        <x-fruit::menu-link href="#" data-status="not_spam" wire:click.prevent="changeStatus('not_spam')">{{ __('Not Spam') }}</x-fruit::menu-link>
                    @endif
                @else
                    <x-slot:trigger class="f-button--small"><x-icon.trash-2 class="f-icon" aria-hidden="true" /> <span class="conv-info-val"><span>{{ __('Deleted') }}</span></span></x-slot:trigger>
                    <x-fruit::menu-link href="#" class="conv-restore-trigger" wire:click.prevent="restore">{{ __('Restore') }}</x-fruit::menu-link>
                @endif
            </x-fruit::menu>
        </li>@action('conversation.convinfo.before_nav', $conversation, $mailbox)<li class="conv-next-prev">
            <a href="{{ $conversation->urlPrev(App\Conversation::getFolderParam()) }}" class="f-button f-button--ghost f-button--icon" title="{{ __("Newer") }}" aria-label="{{ __("Newer") }}"><x-icon.chevron-up class="f-icon" aria-hidden="true" /></a>
            <a href="{{ $conversation->urlNext(App\Conversation::getFolderParam()) }}" class="f-button f-button--ghost f-button--icon" title="{{ __("Older") }}" aria-label="{{ __("Older") }}"><x-icon.chevron-down class="f-icon" aria-hidden="true" /></a>
        </li><li class="conv-customer-toggle">
            <button type="button" class="f-button f-button--ghost f-button--icon app-inspector-toggle" x-data x-on:click="let ws = $el.closest('.app-workspace'); ws.dataset.view = ws.dataset.view === 'inspector' ? '' : 'inspector'; $el.setAttribute('aria-expanded', ws.dataset.view === 'inspector')" aria-expanded="false" aria-controls="app-inspector" aria-label="{{ __('Customer') }}" title="{{ __('Customer') }}"><x-icon.circle-user class="f-icon" aria-hidden="true" /></button>
        </li>
    </ul>
</div>
