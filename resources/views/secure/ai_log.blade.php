@extends('layouts.app')

@section('page_width', 'wide')

@section('title_full', __('Logs').' - '.__('AI'))

@section('main_class', 'fruit-ui')

@section('sidebar')
    <x-page-nav :label="__('Logs')">
        <x-slot:title><h1>{{ __('Logs') }}</h1></x-slot:title>
    </x-page-nav>
@endsection

@section('content')
{{-- Manage » Logs » AI (AiLogController): each call to a model, newest first. --}}
<div class="page-content logs-page ai-log">
    @section('logs_bar_filters')
        <x-fruit::select form="ai-log-filters" name="feature" :aria-label="__('Feature')" x-on:change="$el.form.submit()">
            <option value="">{{ __('All Features') }}</option>
            @foreach ($features as $feature_option)
                <option value="{{ $feature_option }}" @selected($feature === $feature_option)>{{ App\Ai\Usage::featureName($feature_option) }}</option>
            @endforeach
        </x-fruit::select>
        <x-fruit::select form="ai-log-filters" name="outcome" :aria-label="__('Status')" x-on:change="$el.form.submit()">
            <option value="">{{ __('All') }}</option>
            <option value="errors" @selected($outcome == 'errors')>{{ __('Errors') }}</option>
        </x-fruit::select>
        <x-fruit::select form="ai-log-filters" name="model" :aria-label="__('Model')" x-on:change="$el.form.submit()">
            <option value="">{{ __('All Models') }}</option>
            @foreach ($models as $model_option)
                <option value="{{ $model_option }}" @selected($model === $model_option)>{{ $model_option }}</option>
            @endforeach
        </x-fruit::select>
    @endsection
    @include('secure/logs_menu', ['names' => App\ActivityLog::menuNames(), 'current_name' => App\ActivityLog::NAME_AI])
    <form id="ai-log-filters" method="GET" action="{{ route('logs.ai') }}" hidden></form>

    @if ($calls->count())
        <div class="f-table__scroll">
            <x-fruit::table id="table-ai-log" class="logs-table" :aria-label="__('AI')">
                <thead>
                    <tr>
                        <th>{{ __('Date') }}</th>
                        <th>{{ __('Feature') }}</th>
                        <th>{{ __('Model') }}</th>
                        <th>{{ __('Duration') }}</th>
                        <th>{{ __('Tokens (In / Out)') }}</th>
                        <th>{{ __('Mailbox') }}</th>
                        <th>{{ __('Conversation') }}</th>
                        <th>{{ __('Status') }}</th>
                        <th><span class="f-sr-only">{{ __('Details') }}</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($calls as $call)
                        @php [$status_name, $status_tone] = $call->statusName(); @endphp
                        <tr>
                            <td>{{ App\User::dateFormat($call->created_at, 'M j, H:i:s') }}</td>
                            <td>{{ App\Ai\Usage::featureName($call->feature) }}</td>
                            <td>
                                @if ($call->model)
                                    <span class="f-muted">{{ App\Ai\Providers::PRESETS[$call->provider]['name'] ?? $call->provider }} ·</span> {{ $call->model }}
                                @else
                                    <span class="ai-log__none">—</span>
                                @endif
                                @if ($call->backup)
                                    <x-fruit::badge>{{ __('Backup') }}</x-fruit::badge>
                                @endif
                                @if ($call->fast)
                                    <x-fruit::badge variant="outline">{{ __('Fast') }}</x-fruit::badge>
                                @endif
                            </td>
                            <td>@if ($call->duration_ms !== null){{ App\Ai\Usage::formatDuration($call->duration_ms) }}@else<span class="ai-log__none">—</span>@endif</td>
                            <td>@if ($call->input_tokens || $call->output_tokens){{ number_format($call->input_tokens) }} / {{ number_format($call->output_tokens) }}@else<span class="ai-log__none">—</span>@endif</td>
                            <td>@if ($call->mailbox){{ $call->mailbox->name }}@endif</td>
                            <td>@if ($call->conversation)<a href="{{ route('conversations.view', ['id' => $call->conversation_id]) }}" target="_blank">#{{ $call->conversation->number }}</a>@endif</td>
                            {{-- The outcome, and the error on one line (whole in its Details). --}}
                            <td class="logs-table__long"><span><x-fruit::badge :tone="$status_tone">{{ $status_name }}</x-fruit::badge> <span class="f-muted">{{ $call->error }}</span></span></td>
                            <td>
                                @if ((string) $call->error !== '')
                                    <x-fruit::button variant="ghost" size="small" x-data x-on:click="$dispatch('fruit-dialog-open', { name: 'ai-call-{{ $call->id }}' })">{{ __('Details') }}</x-fruit::button>
                                    <x-fruit::dialog name="ai-call-{{ $call->id }}" aria-labelledby="ai-call-{{ $call->id }}-title">
                                        <header class="f-dialog__header"><h2 id="ai-call-{{ $call->id }}-title">{{ App\Ai\Usage::featureName($call->feature) }}</h2></header>
                                        <div class="f-dialog__body">
                                            <x-fruit::description-list>
                                                <div><dt>{{ __('Date') }}</dt><dd>{{ App\User::dateFormat($call->created_at, 'M j, H:i:s') }}</dd></div>
                                                <div><dt>{{ __('Model') }}</dt><dd>{{ App\Ai\Providers::PRESETS[$call->provider]['name'] ?? $call->provider }} · {{ $call->model }}</dd></div>
                                                <div><dt>{{ __('Status') }}</dt><dd>{{ $status_name }}</dd></div>
                                                <div><dt>{{ __('Error') }}</dt><dd class="ai-log__error">{{ $call->error }}</dd></div>
                                            </x-fruit::description-list>
                                        </div>
                                        <form method="dialog" class="f-dialog__footer"><x-fruit::button type="submit" variant="primary">{{ __('Done') }}</x-fruit::button></form>
                                    </x-fruit::dialog>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </x-fruit::table>
        </div>

        {{ $calls->links('fruit::pagination.default') }}
    @else
        <x-fruit::empty-state>
            <x-slot:icon><x-icon.file-text /></x-slot:icon>
            {{ __('This log is empty.') }}
        </x-fruit::empty-state>
    @endif
</div>
@endsection
