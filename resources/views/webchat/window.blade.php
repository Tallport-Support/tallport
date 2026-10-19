<!doctype html>
{{-- The website chat's window (WebChatController::window()): in the frame the website's script
     (public/t/t.js) places, or on its own page (the website's "Chat with us" link). From FruitUI's
     Widget example; its script is public/js/webchat.js. Every address is relative to /t/, so a
     website can serve the folder through its own domain. --}}
<html lang="{{ app()->getLocale() }}" dir="{{ \Helper::isLocaleRtl() ? 'rtl' : 'ltr' }}" class="fruit-ui" data-theme="system" data-fruit-accent="{{ $settings['accent'] }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, interactive-widget=resizes-content">
    <meta name="robots" content="noindex">
    <title>{{ __('Chat with :name', ['name' => $team]) }}</title>
    <link rel="stylesheet" href="s?v={{ \App\WebChat\WebChat::assetVersion('s') }}">
    <script src="j?v={{ \App\WebChat\WebChat::assetVersion('j') }}" defer nonce="{{ $nonce }}"></script>
</head>
<body>
    @php
        $web_chat_config = [
            'widget'   => $settings['widget'],
            'origin'   => $origin,
            'team'     => $team,
            'greeting' => $settings['greeting'],
            'poll'     => $poll,
            'maxFiles' => \App\WebChat\WebChat::MAX_FILES,
            'maxFileMb' => \App\WebChat\WebChat::MAX_FILE_MB,
            'text'     => [
                'label'    => __('Chat with :name', ['name' => $team]),
                'unread'   => __(':name, unread messages: :count'),
                'you'      => __('You'),
                'sending'  => __('Sending…'),
                'waiting'  => __('Waiting to send'),
                'failed'   => __('Not sent'),
                'retry'    => __('Try Again'),
                'remove'   => __('Remove :name'),
                'tooLarge' => __('Files can be up to :size MB.', ['size' => \App\WebChat\WebChat::MAX_FILE_MB]),
                'tooMany'  => __('Up to :count files per message.', ['count' => \App\WebChat\WebChat::MAX_FILES]),
            ],
        ];
    @endphp
    <div class="widget" x-data="tallportWebChat(@js($web_chat_config))" :data-embedded="embedded" @keydown.escape="escape($event)">
        <header class="widget-header">
            <span class="f-avatar widget-team-mark" aria-hidden="true">{{ mb_strtoupper(mb_substr($team, 0, 1)) }}</span>
            <div class="widget-heading">
                <h1 id="widget-title" x-ref="title" tabindex="-1">{{ $team }}</h1>
                @if ($settings['reply_time'] !== '')
                    <p>{{ $settings['reply_time'] }}</p>
                @endif
            </div>
            <button class="f-button f-button--ghost f-button--icon widget-close" type="button" x-show="embedded" x-cloak @click="close" aria-label="{{ __('Close Chat') }}" title="{{ __('Close Chat') }}"><x-icon.x class="f-icon" aria-hidden="true" /></button>
        </header>
        <div class="f-alert f-alert--warning widget-offline" role="status" x-show="!online" x-cloak><x-icon.triangle-alert class="f-icon f-alert__icon" aria-hidden="true" /><div class="f-alert__body">{{ __('You’re offline. Your messages will be sent when you’re back.') }}</div></div>
        <div class="f-pane__scroll f-history widget-history" role="region" tabindex="0" aria-labelledby="widget-title" x-data="fruitHistory">
            <ol class="widget-messages" role="list">
                <template x-for="entry in timeline" :key="entry.id">
                    <li class="widget-entry" :data-kind="entry.kind" :data-first="entry.first" :data-last="entry.last" :data-entry-id="entry.id">
                        <div class="widget-message" :data-state="entry.state">
                            <span class="f-avatar widget-avatar" :class="{ 'widget-team-mark': entry.team }" aria-hidden="true" x-show="entry.kind === 'team' && entry.first" x-text="entry.initials"></span>
                            <p class="widget-author" :class="{ 'f-sr-only': entry.kind === 'visitor' || !entry.first }" x-text="entry.kind === 'visitor' ? text.you : entry.author"></p>
                            <p class="widget-bubble" x-show="entry.body" x-text="entry.body"></p>
                            <template x-for="file in entry.files" :key="file.id"><a class="f-attachment widget-attachment" :href="file.url" :download="file.name"><span class="f-attachment__leading"><x-icon.file-text class="f-icon" aria-hidden="true" /></span><span class="f-attachment__body"><span x-text="file.name"></span><small class="f-attachment__detail" x-text="file.size"></small></span><span class="f-attachment__trailing"><x-icon.download class="f-icon" aria-hidden="true" /></span></a></template>
                            <p class="widget-meta" x-show="entry.time && entry.state !== 'failed' && (entry.last || entry.state)"><time x-text="entry.time"></time><span x-text="entry.state ? ' · ' + text[entry.state] : ''"></span></p>
                            {{-- Not sent: as FruitUI's failed-send line (.f-message__status). --}}
                            <p class="widget-status" x-show="entry.state === 'failed'" x-cloak><x-icon.circle-alert class="f-icon widget-status__icon" aria-hidden="true" /><span x-text="text.failed"></span><button class="f-button f-button--ghost f-button--small" type="button" @click="deliver(entry)" x-text="text.retry"></button></p>
                        </div>
                    </li>
                </template>
            </ol>
            <button class="f-button f-history__latest" type="button" x-show="awayFromLatest" x-cloak @click="jumpToLatest({ focus: true })"><x-icon.chevron-down class="f-icon" aria-hidden="true" />{{ __('Jump to Latest') }}</button>
        </div>
        <p class="f-sr-only" role="status" x-text="announcement"></p>
        <form class="f-composer widget-composer" @submit.prevent="send">
            <label class="f-sr-only" for="widget-message">{{ __('Message') }}</label>
            <p class="f-error" x-show="error" x-text="error" x-cloak></p>
            <ul class="widget-files" role="list" aria-label="{{ __('Files to send') }}" x-show="files.length" x-cloak>
                <template x-for="file in files" :key="file.id"><li><span class="f-chip"><span x-text="file.name"></span><button class="f-chip__remove" type="button" @click="removeFile(file)" :aria-label="text.remove.replace(':name', file.name)"><x-icon.x class="f-icon" aria-hidden="true" /></button></span></li></template>
            </ul>
            <div class="f-composer__field"><textarea id="widget-message" class="f-input f-composer__input" rows="1" x-ref="message" x-model="draft" maxlength="{{ \App\WebChat\WebChat::MAX_LENGTH }}" placeholder="{{ __('Message :name', ['name' => $team]) }}" aria-describedby="widget-message-help" @keydown.enter="if (!$event.shiftKey && !$event.isComposing) { $event.preventDefault(); $el.form.requestSubmit(); }"></textarea><button class="f-button f-button--ghost f-button--icon" type="button" @click="$refs.files.click()" aria-label="{{ __('Attach Files') }}" title="{{ __('Attach Files') }}"><x-icon.paperclip class="f-icon" aria-hidden="true" /></button><input type="file" multiple hidden x-ref="files" @change="addFiles($event.target)"><button class="f-button f-button--primary f-button--icon f-composer__send" type="submit" aria-label="{{ __('Send') }}"><x-icon.send class="f-icon" aria-hidden="true" /></button></div>
            <p id="widget-message-help" class="f-sr-only">{{ __('Enter to send, Shift+Enter for a new line.') }}</p>
        </form>
        <noscript><p class="widget-noscript">{{ __('Turn on JavaScript to chat with :name.', ['name' => $team]) }}</p></noscript>
    </div>
</body>
</html>
