<div class="f-stack modal-form">
    <div class="f-alert f-alert--warning">
        <div class="f-alert__body">
            <p>{{ __('Selected conversation will be merged into the current conversation behind the popup.') }}</p>
            <p><strong>{{ __("Merged conversations can not be unmerged.") }}</strong></p>
        </div>
    </div>

    <div class="f-field">
        <label class="f-label" for="merge-conv-number">{{ __('Search Conversation by Number') }} (#)</label>
        <div class="f-input-group">
            <input type="number" class="f-input merge-conv-number" id="merge-conv-number">
            <button class="f-button btn-merge-search" data-loading-text="{{ __('Search') }}…" type="button">{{ __('Search') }}</button>
        </div>
    </div>

    <div class="conv-merge-search-result hidden">
        <table class="f-table conv-merge-table">
            <tr>
                <td>

                </td>
            </tr>
        </table>
    </div>

    @if (count($prev_conversations))
        <div class="conv-merge-list">
            <span class="f-label">{{ __('Previous Conversations') }}</span>

            <table class="f-table conv-merge-table">
                @foreach ($prev_conversations as $prev_conversation)
                    <tr>
                        <td>
                            <div class="conv-merge-item"><input type="checkbox" class="f-check conv-merge-id" value="{{ $prev_conversation->id }}" aria-label="#{{ $prev_conversation->number }}" /><a href="{{ $prev_conversation->url() }}" target="_blank" data-toggle="tooltip" title="{{ __('Click to view') }}"><strong>#{{ $prev_conversation->number }}</strong> {{ $prev_conversation->getSubject() }}</a></div>
                        </td>
                    </tr>
                @endforeach
            </table>
        </div>
    @endif

    <div class="conv-merge-selected f-stack">

    </div>

    <div class="modal-form__actions">
        <button class="f-button f-button--primary btn-merge-conv" data-loading-text="{{ __('Merge') }}…" type="submit" disabled>{{ __('Merge') }}</button>
    </div>
</div>
