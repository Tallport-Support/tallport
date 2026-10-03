@extends('layouts.app')

@section('title_full', ($workflow->exists ? $workflow->name : __('New Workflow')).' - '.($mailbox ? $mailbox->name : __('All Mailboxes')))

@section('sidebar')
    @include('partials/sidebar_menu_toggle')
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
    <div class="section-heading">
        <a href="{{ $index_url }}">{{ __('Workflows') }}</a> » @if ($workflow->exists){{ $workflow->name }}@else{{ __('New Workflow') }}@endif
        <small>{{ $mailbox ? $mailbox->name : __('All Mailboxes') }}</small>
    </div>

    @include('partials/flash_messages')

    <div class="row-container">
        <form class="form-horizontal margin-top workflow-form" method="POST" action="{{ $mailbox ? route('mailboxes.workflows.save', ['mailbox_id' => $mailbox->id]) : route('workflows.save') }}">
            {{ csrf_field() }}
            <input type="hidden" name="workflow_id" value="{{ $workflow->id }}">
            <input type="hidden" name="conditions" value="">
            <input type="hidden" name="actions" value="">

            <div class="form-group{{ $errors->has('name') ? ' has-error' : '' }}">
                <label for="name" class="col-sm-2 control-label">{{ __('Name') }}</label>
                <div class="col-sm-6">
                    <input id="name" type="text" class="form-control input-sized" name="name" value="{{ old('name', $workflow->name) }}" maxlength="75" required autofocus>
                    @include('partials/field_error', ['field' => 'name'])
                </div>
            </div>

            <div class="form-group">
                <label for="workflow_type" class="col-sm-2 control-label">{{ __('Type') }}</label>
                <div class="col-sm-6">
                    <select id="workflow_type" class="form-control input-sized" name="type">
                        <option value="{{ App\Workflow::TYPE_AUTOMATIC }}" @if ($type == App\Workflow::TYPE_AUTOMATIC) selected @endif>{{ __('Automatic') }}</option>
                        <option value="{{ App\Workflow::TYPE_MANUAL }}" @if ($type == App\Workflow::TYPE_MANUAL) selected @endif>{{ __('Manual') }}</option>
                    </select>
                    <div class="form-help workflow-automatic-only">{{ __('Runs by itself on conversations that meet the conditions: when something happens to them, and as time passes (every 5 minutes).') }}</div>
                    <div class="form-help workflow-manual-only">{{ __('Users run it from a conversation\'s menu.') }}</div>
                </div>
            </div>

            <div class="form-group">
                <label for="workflow_active" class="col-sm-2 control-label">{{ __('Active') }}</label>
                <div class="col-sm-6">
                    <div class="onoffswitch-wrap">
                        <div class="onoffswitch">
                            <input type="checkbox" name="active" value="1" id="workflow_active" class="onoffswitch-checkbox" @if (old('active', $workflow->exists ? $workflow->active : true)) checked @endif>
                            <label class="onoffswitch-label" for="workflow_active"></label>
                        </div>
                    </div>
                </div>
            </div>

            <div class="workflow-automatic-only">
                <div class="form-group{{ $errors->has('max_executions') ? ' has-error' : '' }}">
                    <label for="max_executions" class="col-sm-2 control-label">{{ __('Max Executions') }}</label>
                    <div class="col-sm-6">
                        <input id="max_executions" type="number" class="form-control input-sized" name="max_executions" value="{{ old('max_executions', $workflow->max_executions ?: 1) }}" min="1" max="1000000">
                        <div class="form-help">{{ __('How often it can run on the same conversation.') }}</div>
                        @include('partials/field_error', ['field' => 'max_executions'])
                    </div>
                </div>

                <div class="form-group">
                    <label for="apply_to_prev" class="col-sm-2 control-label">{{ __('Existing Conversations') }}</label>
                    <div class="col-sm-6">
                        <label class="checkbox inline plain"><input type="checkbox" name="apply_to_prev" value="1" id="apply_to_prev" @if (old('apply_to_prev', $workflow->apply_to_prev)) checked @endif> {{ __('Also run on conversations from before the workflow') }}</label>
                        <div class="form-help text-warning">{{ __('On saving, it runs on every existing conversation that meets the conditions. This can\'t be undone.') }}</div>
                    </div>
                </div>

                <h4 class="margin-top-10">{{ __('Conditions') }}</h4>
                <p class="text-help">{{ __('All of the groups must be met; within a group, one of its conditions.') }}</p>
                @include('partials/field_error', ['field' => 'conditions'])
                <div class="workflow-editor" data-mode="conditions"></div>
            </div>

            <h4 class="margin-top-10">{{ __('Actions') }}</h4>
            <p class="text-help">{{ __('Done in this order.') }}</p>
            @include('partials/field_error', ['field' => 'actions'])
            <div class="workflow-editor" data-mode="actions"></div>

            <div class="form-group margin-top">
                <div class="col-sm-6">
                    <button type="submit" class="btn btn-primary">{{ __('Save') }}</button>
                    @if ($workflow->exists)
                        <a href="#" class="btn btn-link text-danger workflow-delete" data-workflow-id="{{ $workflow->id }}" data-redirect="{{ $index_url }}" data-confirm="{{ __('Delete this workflow?') }}">{{ __('Delete') }}</a>
                        @if ($workflow->isAutomatic())<span class="text-help margin-left-10">{{ __('Ran on :count conversations', ['count' => $workflow->conversationsCount()]) }}</span>@endif
                    @endif
                </div>
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

@include('partials/editor')

@section('javascript')
    @parent
    workflowEditorInit();
@endsection
