@extends('layouts.app')

@section('title_full', ($workflow->exists ? $workflow->name : __('New Workflow')).' - '.($mailbox ? $mailbox->name : __('All Mailboxes')))

@section('main_class', 'fruit-ui')

@section('sidebar')
    @if ($mailbox && !Auth::user()->isAdmin())
        @include('mailboxes/sidebar_menu')
    @else
        @include('workflows/sidebar_menu')
    @endif
@endsection

@section('content')
    @php
        $index_url = $mailbox ? route('mailboxes.workflows', ['mailbox_id' => $mailbox->id]) : route('workflows');
        $type = (int) old('type', $workflow->type);
        $conditions = old('conditions') ? App\Workflow::groups(old('conditions')) : $workflow->getConditions();
        $actions = old('actions') ? App\Workflow::groups(old('actions')) : $workflow->getActions();
    @endphp
    <div class="page-content">
        @include('partials/flash_messages')

        <form class="settings-form workflow-form" method="POST" action="{{ $mailbox ? route('mailboxes.workflows.save', ['mailbox_id' => $mailbox->id]) : route('workflows.save') }}">
            {{ csrf_field() }}
            <input type="hidden" name="workflow_id" value="{{ $workflow->id }}">
            <input type="hidden" name="conditions" value="">
            <input type="hidden" name="actions" value="">

            <h2 class="settings-form__heading">@if ($workflow->exists){{ $workflow->name }}@else{{ __('New Workflow') }}@endif <small class="f-muted">{{ $mailbox ? $mailbox->name : __('All Mailboxes') }}</small></h2>

            <x-fruit::field :label="__('Name')">
                <x-fruit::input id="name" name="name" :value="old('name', $workflow->name)" maxlength="75" required autofocus />
            </x-fruit::field>

            <div class="f-field">
                <label class="f-label" for="workflow_type">{{ __('Type') }}</label>
                <x-fruit::select id="workflow_type" name="type">
                    <option value="{{ App\Workflow::TYPE_AUTOMATIC }}" @selected($type == App\Workflow::TYPE_AUTOMATIC)>{{ __('Automatic') }}</option>
                    <option value="{{ App\Workflow::TYPE_MANUAL }}" @selected($type == App\Workflow::TYPE_MANUAL)>{{ __('Manual') }}</option>
                </x-fruit::select>
                <p class="f-help workflow-automatic-only">{{ __('Runs by itself on conversations that meet the conditions: when something happens to them, and as time passes (every 5 minutes).') }}</p>
                <p class="f-help workflow-manual-only">{{ __('Users run it from a conversation\'s menu.') }}</p>
            </div>

            <x-fruit::switch id="workflow_active" name="active" value="1" :checked="(bool) old('active', $workflow->exists ? $workflow->active : true)">{{ __('Active') }}</x-fruit::switch>

            <div class="settings-form workflow-automatic-only">
                <x-fruit::field :label="__('Max Executions')" :description="__('How often it can run on the same conversation.')">
                    <x-fruit::number id="max_executions" name="max_executions" :value="old('max_executions', $workflow->max_executions ?: 1)" min="1" max="1000000" />
                </x-fruit::field>

                <x-fruit::checkbox id="apply_to_prev" name="apply_to_prev" value="1" :checked="(bool) old('apply_to_prev', $workflow->apply_to_prev)" :description="__('Also run on conversations from before the workflow')">{{ __('Existing Conversations') }}</x-fruit::checkbox>
                <x-fruit::alert tone="warning">{{ __('On saving, it runs on every existing conversation that meets the conditions. This can\'t be undone.') }}</x-fruit::alert>

                <section class="wf-editor-section">
                    <h2 class="settings-form__heading">{{ __('Conditions') }}</h2>
                    <p class="f-help">{{ __('All of the groups must be met; within a group, one of its conditions.') }}</p>
                    @error('conditions')<p class="f-error">{{ $message }}</p>@enderror
                    <div class="workflow-editor" data-mode="conditions"></div>
                </section>
            </div>

            <section class="wf-editor-section">
                <h2 class="settings-form__heading">{{ __('Actions') }}</h2>
                <p class="f-help">{{ __('Done in this order.') }}</p>
                @error('actions')<p class="f-error">{{ $message }}</p>@enderror
                <div class="workflow-editor" data-mode="actions"></div>
            </section>

            <div class="settings-form__actions f-row">
                <x-fruit::button type="submit" variant="primary">{{ __('Save') }}</x-fruit::button>
                @if ($workflow->exists)
                    <x-fruit::button variant="danger" class="workflow-delete" data-workflow-id="{{ $workflow->id }}" data-redirect="{{ $index_url }}" data-confirm="{{ __('Delete this workflow?') }}">{{ __('Delete') }}</x-fruit::button>
                    @if ($workflow->isAutomatic())<span class="f-muted">{{ __('Ran on :count conversations', ['count' => $workflow->conversationsCount()]) }}</span>@endif
                @endif
            </div>
        </form>

        <span id="workflow-data" class="hidden"
            data-config="{{ json_encode($config) }}"
            data-conditions="{{ json_encode($conditions) }}"
            data-actions="{{ json_encode($actions) }}"
            data-lang="{{ json_encode([
                'select_condition' => __('Select a condition'),
                'select_action'    => __('Select an action'),
                'or'               => __('or'),
                'and'              => __('and'),
                'add_or'           => __('+ OR'),
                'add_condition'    => __('+ AND'),
                'add_action'       => __('+ Action'),
                'remove'           => __('Remove'),
                'minutes'          => __('Minutes'),
                'hours'            => __('Hours'),
                'days'             => __('Days'),
                'to'               => __('To'),
                'cc'               => __('Cc'),
                'bcc'              => __('Bcc'),
                'subject'          => __('Subject'),
                'no_signature'     => __('Without signature'),
                'conv_history'     => __('Conversation History'),
                'history_default'  => __('Use global setting'),
                'history'          => ['none' => __('None'), 'last' => __('Last message'), 'full' => __('Full history')],
                'sender_name'      => __('Sender Name'),
                'senders'          => [
                    App\Workflows\Actions::SENDER_ASSIGNEE_OR_MAILBOX => __('Assignee or Mailbox name (if unassigned)'),
                    App\Workflows\Actions::SENDER_MAILBOX             => __('Mailbox name'),
                    App\Workflows\Actions::SENDER_WORKFLOW            => __('Workflow-user name'),
                ],
                'only_if_available' => __('Only if available'),
            ]) }}"></span>
    </div>
@endsection

@section('body_bottom')
    @parent
    {{-- The email actions' body editor, cloned by public/js/workflows.js. --}}
    <template id="wf-editor-template"><x-editor data-field="body" rows="6" :aria-label="__('Message')" vars></x-editor></template>
@append

@section('javascript')
    @parent
    workflowEditorInit();
@endsection
