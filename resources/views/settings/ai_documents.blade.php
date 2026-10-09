@extends('layouts.app')

@section('page_width', 'medium')

@section('title', __('Documentation'))

{{-- Converted to FruitUI: the whole main area is in its scope. --}}
@section('main_class', 'fruit-ui')

@section('sidebar')
    <x-page-nav>
        <x-slot:title>
            <x-fruit::back-link href="{{ route('settings', ['section' => 'ai']) }}">{{ __('AI') }}</x-fruit::back-link>
            <h1>{{ __('Documentation') }}</h1>
        </x-slot:title>
    </x-page-nav>
@endsection

@section('content')
<div class="fruit-ui page-content ai-documents">
    <div class="settings-form">
        @include('partials/flash_messages')

        @if (session('ai_new_key'))
            <x-fruit::alert tone="success">
                {{ __('New API key for :mailbox. Copy it now: it is not shown again.', ['mailbox' => session('ai_new_key')['mailbox']]) }}
                <br><code>{{ session('ai_new_key')['key'] }}</code>
            </x-fruit::alert>
        @endif

        @if (!App\Ai\Documents::available())
            <x-fruit::alert tone="warning">
                {{ __('The selected embedding provider does not support embeddings, so drafts are made without documentation. Summaries and translations are not affected.') }}
            </x-fruit::alert>
        @endif

        <p class="f-help">
            {{ __('Drafted replies use the documentation of the conversation\'s mailbox. Pages added by URL are fetched as Markdown (the URL plus .md) and fetched again daily.') }}
        </p>

        <form class="settings-form" method="POST" action="{{ route('ai.documents.action') }}">
            {{ csrf_field() }}
            <input type="hidden" name="action" value="add">

            <x-fruit::form-section :title="__('Add Pages')">
                <x-fruit::field :label="__('Mailbox')" layout="row">
                    <x-fruit::select id="ai_documents_mailbox" name="mailbox_id" required>
                        @foreach ($mailboxes as $mailbox_option)
                            <option value="{{ $mailbox_option->id }}" @selected(old('mailbox_id') == $mailbox_option->id)>{{ $mailbox_option->name }}</option>
                        @endforeach
                    </x-fruit::select>
                </x-fruit::field>

                <x-fruit::field :label="__('URLs')" :description="__('One URL per line. Pages already added are fetched again.')">
                    <x-fruit::textarea id="ai_documents_urls" name="urls" rows="4" required placeholder="https://docs.example.com/en/setup">{{ old('urls') }}</x-fruit::textarea>
                </x-fruit::field>
            </x-fruit::form-section>

            <footer class="f-form-row settings-form__actions">
                <x-fruit::button type="submit" variant="primary">{{ __('Add') }}</x-fruit::button>
            </footer>
        </form>
    </div>

    <h2 class="settings-form__heading">{{ __('Pages') }}</h2>

    @if (count($documents))
        <form method="POST" action="{{ route('ai.documents.action') }}" class="f-row ai-documents__actions">
            {{ csrf_field() }}
            <input type="hidden" name="action" value="index">
            <x-fruit::button type="submit">{{ __('Fetch and Index Changes') }}</x-fruit::button>
            <x-fruit::button type="submit" variant="ghost" name="force" value="1" x-data x-on:click.prevent="Tallport.confirm({message: $el.dataset.confirm, confirm: $el.textContent.trim()}).then(ok => ok && $el.form.requestSubmit($el))" data-confirm="{{ __('Index all documentation again? This makes new embeddings for every page.') }}">{{ __('Index All Again') }}</x-fruit::button>
        </form>

        <x-fruit::table>
            <thead>
                <tr>
                    <th>{{ __('Title') }}</th>
                    <th>{{ __('Mailbox') }}</th>
                    <th>{{ __('Status') }}</th>
                    <th>{{ __('Chunks') }}</th>
                    <th>{{ __('Indexed') }}</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($documents as $document)
                    <tr>
                        <td>
                            <strong>{{ $document->title }}</strong>
                            @if (!$document->enabled)
                                <x-fruit::badge>{{ __('Disabled') }}</x-fruit::badge>
                            @endif
                            <br>
                            @if ($document->isPrivate())
                                <span class="f-muted">API: {{ $document->metadata['api_identifier'] ?? $document->source_url }}</span>
                            @elseif (App\Ai\Document::isHttpUrl($document->source_url))
                                <a href="{{ $document->source_url }}" target="_blank" rel="noopener noreferrer">{{ $document->source_url }}</a>
                            @else
                                {{ $document->source_url }}
                            @endif
                        </td>
                        <td>{{ $document->mailbox ? $document->mailbox->name : '#'.$document->mailbox_id }}</td>
                        <td>
                            @if ($document->status == App\Ai\Document::STATUS_INDEXED && $document->embedding_fingerprint == $embedding_fingerprint)
                                <x-fruit::badge tone="success">{{ __('Indexed') }}</x-fruit::badge>
                            @elseif ($document->status == App\Ai\Document::STATUS_FAILED)
                                <x-fruit::badge tone="danger">{{ __('Failed') }}</x-fruit::badge>
                                <div class="f-error">{{ $document->last_error }}</div>
                            @else
                                <x-fruit::badge>{{ __('Pending') }}</x-fruit::badge>
                            @endif
                        </td>
                        <td>{{ $document->chunks_count }}</td>
                        <td>{{ $document->last_indexed_at ? App\User::dateFormat($document->last_indexed_at) : '–' }}</td>
                        <td>
                            <form method="POST" action="{{ route('ai.documents.action') }}" class="f-row">
                                {{ csrf_field() }}
                                <input type="hidden" name="document_id" value="{{ $document->id }}">
                                @if ($document->enabled)
                                    <x-fruit::button type="submit" size="small" name="action" value="index">{{ __('Index') }}</x-fruit::button>
                                @endif
                                <x-fruit::button type="submit" size="small" name="action" value="toggle">{{ $document->enabled ? __('Disable') : __('Enable') }}</x-fruit::button>
                                <x-fruit::button type="submit" size="small" variant="danger" name="action" value="delete" x-data x-on:click.prevent="Tallport.confirm({message: $el.dataset.confirm, confirm: $el.textContent.trim(), tone: 'danger'}).then(ok => ok && $el.form.requestSubmit($el))" data-confirm="{{ __('Delete this page from the documentation?') }}">{{ __('Delete') }}</x-fruit::button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </x-fruit::table>
    @else
        <p class="f-muted">{{ __('No documentation has been added yet.') }}</p>
    @endif

    <h2 class="settings-form__heading">{{ __('Documentation API') }}</h2>

    <p class="f-help">{{ __('Websites and build jobs can push Markdown pages into a mailbox\'s documentation with the mailbox\'s API key.') }}</p>

    <x-fruit::table>
        <thead>
            <tr>
                <th>{{ __('Mailbox') }}</th>
                <th>{{ __('API Key') }}</th>
                <th>{{ __('Last Used') }}</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            @foreach ($mailboxes as $mailbox_option)
                @php
                    $api_key = $api_keys->get($mailbox_option->id);
                @endphp
                <tr>
                    <td>{{ $mailbox_option->name }}</td>
                    <td>@if ($api_key)<code>{{ $api_key->key_preview }}</code>@else<span class="f-muted">–</span>@endif</td>
                    <td>{{ $api_key && $api_key->last_used_at ? App\User::dateFormat($api_key->last_used_at) : '–' }}</td>
                    <td>
                        <form method="POST" action="{{ route('ai.documents.action') }}" class="f-row">
                            {{ csrf_field() }}
                            <input type="hidden" name="mailbox_id" value="{{ $mailbox_option->id }}">
                            @if ($api_key)
                                <x-fruit::button type="submit" size="small" name="action" value="issue_key" x-data x-on:click.prevent="Tallport.confirm({message: $el.dataset.confirm, confirm: $el.textContent.trim(), tone: 'danger'}).then(ok => ok && $el.form.requestSubmit($el))" data-confirm="{{ __('Make a new API key? Websites using the current key stop working.') }}">{{ __('New Key') }}</x-fruit::button>
                                <x-fruit::button type="submit" size="small" variant="danger" name="action" value="revoke_key" x-data x-on:click.prevent="Tallport.confirm({message: $el.dataset.confirm, confirm: $el.textContent.trim(), tone: 'danger'}).then(ok => ok && $el.form.requestSubmit($el))" data-confirm="{{ __('Revoke this API key? Websites using it stop working.') }}">{{ __('Revoke') }}</x-fruit::button>
                            @else
                                <x-fruit::button type="submit" size="small" name="action" value="issue_key">{{ __('Make Key') }}</x-fruit::button>
                            @endif
                        </form>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </x-fruit::table>

    <pre>curl -X POST "{{ route('ai.documents.api') }}" \
  -H "Authorization: Bearer YOUR_MAILBOX_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "identifier": "setup/android",
    "content": "# Android setup\n\nMarkdown...",
    "public_url": "https://docs.example.com/en/setup/android"
  }'</pre>
</div>
@endsection
