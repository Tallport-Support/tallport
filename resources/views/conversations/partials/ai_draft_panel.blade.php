{{-- AI Assistant: a reply draft (App\Ai\Drafts), asked for by the toolbar's Draft with AI (tallportAiDraft in public/js/conversations.js). --}}
<div class="ai-draft-panel" x-data="tallportAiDraft(@js(route('ai.drafts.store', ['id' => $conversation->id])), @js(App\Ai\Settings::language($conversation->mailbox, Auth::user())), @js([
        'queued'   => __('Waiting in the queue…'),
        'drafting' => __('Drafting…'),
        'failed'   => __('Could not draft a reply.'),
        'slow'     => __('The draft is taking long. Check that the queue is running, or try again.'),
     ]))" x-show="active" x-cloak x-on:ai-draft-request.window="request()">
    @php
        // The language and confidence, once drafted.
        $ai_draft_meta = new Illuminate\Support\HtmlString('<span x-text="meta"></span>');
    @endphp
    <x-fruit::suggestion :title="__('AI Draft')" :meta="$ai_draft_meta" x-bind:aria-busy="busy() ? 'true' : 'false'">
        <x-slot:dismiss><x-fruit::button variant="ghost" class="f-button--icon" :aria-label="__('Close')" :title="__('Close')" x-on:click="close()"><x-icon.x class="f-icon" aria-hidden="true" /></x-fruit::button></x-slot:dismiss>
        <x-slot:status class="ai-draft-status" x-show="status" x-bind:data-tone="tone()" role="status"><span x-text="status"></span></x-slot:status>
        <div class="ai-draft-body" x-show="html" x-html="html"></div>
        <p class="f-muted" x-show="detail" x-text="detail"></p>
        <x-slot:translation x-show="draft && draft.translation" :lang="App\Ai\Settings::language($conversation->mailbox, Auth::user())"><div class="ai-draft-translation-body" x-text="draft ? draft.translation : ''"></div></x-slot:translation>
        <x-slot:actions x-show="draft || failed">
            <x-fruit::button variant="primary" class="ai-draft-insert" x-show="draft" x-on:click="insert()">{{ __('Insert into Reply') }}</x-fruit::button>
            <x-fruit::button class="ai-draft-again" x-on:click="request()">{{ __('Draft Again') }}</x-fruit::button>
        </x-slot:actions>
        <x-slot:details x-show="draft && ((draft.staff_notes || []).length || (draft.retrieved_documents || []).length)">
            <section x-show="draft && (draft.staff_notes || []).length">
                <h4>{{ __('Notes') }}</h4>
                <ul><template x-for="note in (draft ? draft.staff_notes || [] : [])"><li x-text="note"></li></template></ul>
            </section>
            <section x-show="draft && (draft.retrieved_documents || []).length">
                <h4>{{ __('Documentation Used') }}</h4>
                <ul class="f-suggestion__sources"><template x-for="doc in (draft ? draft.retrieved_documents || [] : [])"><li><a x-show="/^https?:\/\//i.test(doc.url || '')" :href="doc.url" target="_blank" rel="noopener noreferrer" x-text="doc.title || doc.url"></a><span x-show="!/^https?:\/\//i.test(doc.url || '')" x-text="doc.title"></span><small x-show="/^https?:\/\//i.test(doc.url || '')" x-text="host(doc.url)"></small></li></template></ul>
            </section>
        </x-slot:details>
    </x-fruit::suggestion>
</div>
