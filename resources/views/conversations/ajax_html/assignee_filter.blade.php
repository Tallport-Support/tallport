<div class="f-input-group">
    <select class="f-input conv-assignee-filter" aria-label="{{ __('Assigned To') }}">
        <option value=""></option>
        @foreach($users as $user)
            <option value="{{ $user->id }}" @if ($user->id == $user_id) selected="selected" @endif>{{ $user->getFullName() }}</option>
        @endforeach
    </select>
    <button class="f-button conv-assignee-filter-reset" type="button" aria-label="{{ __('Clear') }}" title="{{ __('Clear') }}"><x-heroicon-o-x-mark class="f-icon" aria-hidden="true" /></button>
</div>
