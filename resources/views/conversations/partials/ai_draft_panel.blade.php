{{-- AI Assistant: a reply draft (App\Ai\Drafts), asked for by the toolbar's Draft with AI (tallportAiDraft in public/js/conversations.js). --}}
<div class="ai-draft-panel" x-data="tallportAiDraft(@js(route('ai.drafts.store', ['id' => $conversation->id])), @js(App\Ai\Settings::language($conversation->mailbox, Auth::user())), @js([
        'queued'   => __('Waiting in the queue…'),
        'drafting' => __('Drafting…'),
        'failed'   => __('Could not draft a reply.'),
        'slow'     => __('The draft is taking long. Check that the queue is running, or try again.'),
     ]))" x-show="active" x-cloak x-on:ai-draft-request.window="request()">
    <strong>{{ __('AI Draft') }}</strong> <span class="ai-draft-meta f-muted" x-text="meta"></span>
    <div class="ai-draft-status f-muted" :class="{ 'text-danger': failed }" x-text="status" role="status"></div>
    <div class="ai-draft-body f-prose" x-show="html" x-html="html"></div>
    <div class="ai-draft-body" x-show="detail" x-text="detail"></div>
    <div class="ai-draft-translation" x-show="draft && draft.translation">
        <strong>{{ __('Translation') }}</strong>
        <div class="ai-draft-translation-body" x-text="draft ? draft.translation : ''"></div>
    </div>
    <div class="ai-draft-actions f-row" x-show="draft || failed">
        <button type="button" class="f-button f-button--primary f-button--small ai-draft-insert" x-show="draft" x-on:click="insert()">{{ __('Insert into Reply') }}</button>
        <button type="button" class="f-button f-button--small ai-draft-action" x-on:click="request()">{{ __('Draft Again') }}</button>
    </div>
    <div class="ai-draft-notes" x-show="draft && draft.staff_notes && draft.staff_notes.length">
        <strong>{{ __('Notes') }}</strong>
        <ul><template x-for="note in (draft ? draft.staff_notes || [] : [])"><li x-text="note"></li></template></ul>
    </div>
    <div class="ai-draft-docs" x-show="draft && draft.retrieved_documents && draft.retrieved_documents.length">
        <strong>{{ __('Documentation Used') }}</strong>
        <ul><template x-for="doc in (draft ? draft.retrieved_documents || [] : [])"><li><span x-text="doc.title"></span> <a x-show="/^https?:\/\//i.test(doc.url || '')" :href="doc.url" target="_blank" rel="noopener noreferrer" x-text="doc.url"></a></li></template></ul>
    </div>
</div>
