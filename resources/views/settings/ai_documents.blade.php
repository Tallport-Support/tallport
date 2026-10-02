@extends('layouts.app')

@section('title', __('Documentation'))

@section('content')
<div class="section-heading">
    {{ __('Documentation') }}
</div>

<div class="container ai-documents">

    @include('partials/flash_messages')

    @if (session('ai_new_key'))
        <div class="alert alert-success">
            {{ __('New API key for :mailbox. Copy it now: it is not shown again.', ['mailbox' => session('ai_new_key')['mailbox']]) }}
            <br><code>{{ session('ai_new_key')['key'] }}</code>
        </div>
    @endif

    @if (!App\Ai\Documents::available())
        <div class="alert alert-warning">
            {{ __('The selected embedding provider does not support embeddings, so drafts are made without documentation. Summaries and translations are not affected.') }}
        </div>
    @endif

    <p class="text-help">
        {{ __('Drafted replies use the documentation of the conversation\'s mailbox. Pages added by URL are fetched as Markdown (the URL plus .md) and fetched again daily.') }}
    </p>

    <h3 class="subheader">{{ __('Add Pages') }}</h3>

    <form class="form-horizontal" method="POST" action="{{ route('ai.documents.action') }}">
        {{ csrf_field() }}
        <input type="hidden" name="action" value="add">

        <div class="form-group">
            <label for="ai_documents_mailbox" class="col-sm-2 control-label">{{ __('Mailbox') }}</label>
            <div class="col-sm-6">
                <select id="ai_documents_mailbox" name="mailbox_id" class="form-control input-sized" required>
                    @foreach ($mailboxes as $mailbox)
                        <option value="{{ $mailbox->id }}" @if (old('mailbox_id') == $mailbox->id) selected @endif>{{ $mailbox->name }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="form-group{{ $errors->has('urls') ? ' has-error' : '' }}">
            <label for="ai_documents_urls" class="col-sm-2 control-label">{{ __('URLs') }}</label>
            <div class="col-sm-8">
                <textarea id="ai_documents_urls" name="urls" class="form-control" rows="4" required placeholder="https://docs.example.com/en/setup">{{ old('urls') }}</textarea>
                <div class="form-help">{{ __('One URL per line. Pages already added are fetched again.') }}</div>
                @include('partials/field_error', ['field' => 'urls'])
            </div>
        </div>

        <div class="form-group">
            <div class="col-sm-8 col-sm-offset-2">
                <button type="submit" class="btn btn-primary">{{ __('Add') }}</button>
            </div>
        </div>
    </form>

    <h3 class="subheader">{{ __('Pages') }}</h3>

    @if (count($documents))
        <form method="POST" action="{{ route('ai.documents.action') }}" class="margin-bottom">
            {{ csrf_field() }}
            <input type="hidden" name="action" value="index">
            <button type="submit" class="btn btn-default">{{ __('Fetch and Index Changes') }}</button>
            <button type="submit" class="btn btn-link" name="force" value="1" data-confirm="{{ __('Index all documentation again? This makes new embeddings for every page.') }}">{{ __('Index All Again') }}</button>
        </form>

        <div class="table-responsive">
            <table class="table table-condensed table-striped">
                <tr>
                    <th>{{ __('Title') }}</th>
                    <th>{{ __('Mailbox') }}</th>
                    <th>{{ __('Status') }}</th>
                    <th>{{ __('Chunks') }}</th>
                    <th>{{ __('Indexed') }}</th>
                    <th></th>
                </tr>
                @foreach ($documents as $document)
                    <tr>
                        <td>
                            <strong>{{ $document->title }}</strong>
                            @if (!$document->enabled)
                                <span class="label label-default">{{ __('Disabled') }}</span>
                            @endif
                            <br>
                            @if ($document->isPrivate())
                                <span class="text-help">API: {{ $document->metadata['api_identifier'] ?? $document->source_url }}</span>
                            @elseif (App\Ai\Document::isHttpUrl($document->source_url))
                                <a href="{{ $document->source_url }}" target="_blank" rel="noopener noreferrer">{{ $document->source_url }}</a>
                            @else
                                {{ $document->source_url }}
                            @endif
                        </td>
                        <td>{{ $document->mailbox ? $document->mailbox->name : '#'.$document->mailbox_id }}</td>
                        <td>
                            @if ($document->status == App\Ai\Document::STATUS_INDEXED)
                                <span class="label label-success">{{ __('Indexed') }}</span>
                            @elseif ($document->status == App\Ai\Document::STATUS_FAILED)
                                <span class="label label-danger">{{ __('Failed') }}</span>
                                <div class="text-danger">{{ $document->last_error }}</div>
                            @else
                                <span class="label label-default">{{ __('Pending') }}</span>
                            @endif
                        </td>
                        <td>{{ $document->chunks_count }}</td>
                        <td>{{ $document->last_indexed_at ? App\User::dateFormat($document->last_indexed_at) : '–' }}</td>
                        <td class="text-right">
                            <form method="POST" action="{{ route('ai.documents.action') }}" class="form-inline">
                                {{ csrf_field() }}
                                <input type="hidden" name="document_id" value="{{ $document->id }}">
                                @if ($document->enabled)
                                    <button type="submit" class="btn btn-xs btn-default" name="action" value="index">{{ __('Index') }}</button>
                                @endif
                                <button type="submit" class="btn btn-xs btn-default" name="action" value="toggle">{{ $document->enabled ? __('Disable') : __('Enable') }}</button>
                                <button type="submit" class="btn btn-xs btn-link text-danger" name="action" value="delete" data-confirm="{{ __('Delete this page from the documentation?') }}">{{ __('Delete') }}</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </table>
        </div>
    @else
        <p class="text-help">{{ __('No documentation has been added yet.') }}</p>
    @endif

    <h3 class="subheader">{{ __('Documentation API') }}</h3>

    <p class="text-help">{{ __('Websites and build jobs can push Markdown pages into a mailbox\'s documentation with the mailbox\'s API key.') }}</p>

    <div class="table-responsive">
        <table class="table table-condensed">
            <tr>
                <th>{{ __('Mailbox') }}</th>
                <th>{{ __('API Key') }}</th>
                <th>{{ __('Last Used') }}</th>
                <th></th>
            </tr>
            @foreach ($mailboxes as $mailbox)
                @php
                    $api_key = $api_keys->get($mailbox->id);
                @endphp
                <tr>
                    <td>{{ $mailbox->name }}</td>
                    <td>@if ($api_key)<code>{{ $api_key->key_preview }}</code>@else<span class="text-help">–</span>@endif</td>
                    <td>{{ $api_key && $api_key->last_used_at ? App\User::dateFormat($api_key->last_used_at) : '–' }}</td>
                    <td class="text-right">
                        <form method="POST" action="{{ route('ai.documents.action') }}" class="form-inline">
                            {{ csrf_field() }}
                            <input type="hidden" name="mailbox_id" value="{{ $mailbox->id }}">
                            @if ($api_key)
                                <button type="submit" class="btn btn-xs btn-default" name="action" value="issue_key" data-confirm="{{ __('Make a new API key? Websites using the current key stop working.') }}">{{ __('New Key') }}</button>
                                <button type="submit" class="btn btn-xs btn-link text-danger" name="action" value="revoke_key" data-confirm="{{ __('Revoke this API key? Websites using it stop working.') }}">{{ __('Revoke') }}</button>
                            @else
                                <button type="submit" class="btn btn-xs btn-default" name="action" value="issue_key">{{ __('Make Key') }}</button>
                            @endif
                        </form>
                    </td>
                </tr>
            @endforeach
        </table>
    </div>

    <pre>curl -X POST "{{ route('ai.documents.api') }}" \
  -H "Authorization: Bearer YOUR_MAILBOX_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "identifier": "setup/android",
    "content": "# Android setup\n\nMarkdown...",
    "public_url": "https://docs.example.com/en/setup/android"
  }'</pre>

    <p><a href="{{ route('settings', ['section' => 'ai']) }}">&larr; {{ __('AI Assistant') }}</a></p>
</div>
@endsection

@section('javascript')
    @parent
    confirmButtonsInit();
@endsection
