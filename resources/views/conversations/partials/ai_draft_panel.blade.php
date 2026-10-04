{{-- AI Assistant: a reply draft (App\Ai\Drafts), filled in by aiDraftsInit(). --}}
<div class="ai-draft-panel hidden"
     data-draft-url="{{ route('ai.drafts.store', ['id' => $conversation->id]) }}"
     data-text-queued="{{ __('Waiting in the queue…') }}"
     data-text-drafting="{{ __('Drafting…') }}"
     data-text-failed="{{ __('Could not draft a reply.') }}"
     data-text-slow="{{ __('The draft is taking long. Check that the queue is running, or try again.') }}"
     data-translation-language="{{ App\Ai\Settings::language($conversation->mailbox, Auth::user()) }}">
    <strong>{{ __('AI Draft') }}</strong><span class="ai-draft-meta"></span>
    <div class="ai-draft-status text-help"></div>
    <div class="ai-draft-body hidden"></div>
    <div class="ai-draft-translation hidden">
        <strong>{{ __('Translation') }}</strong>
        <div class="ai-draft-translation-body"></div>
    </div>
    <div class="ai-draft-actions hidden">
        <button type="button" class="f-button f-button--primary f-button--small ai-draft-insert">{{ __('Insert into Reply') }}</button>
        <button type="button" class="f-button f-button--small ai-draft-action">{{ __('Draft Again') }}</button>
    </div>
    <div class="ai-draft-notes hidden">
        <strong>{{ __('Notes') }}</strong>
        <ul></ul>
    </div>
    <div class="ai-draft-docs hidden">
        <strong>{{ __('Documentation Used') }}</strong>
        <ul></ul>
    </div>
</div>
