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
            'teamPhoto' => $team_photo,
            'greeting' => \App\WebChat\WebChat::greeting($mailbox),
            'poll'     => $poll,
            'available' => $available,
            'workerVersion' => \App\WebChat\WebChat::assetVersion('x'),
            'maxFiles' => \App\WebChat\WebChat::MAX_FILES,
            'maxFileMb' => \App\WebChat\WebChat::MAX_FILE_MB,
            'codeIntro' => __('Enter the code we sent to :email.', ['email' => '%EMAIL%']),
            'text'     => [
                'label'    => __('Chat with :name', ['name' => $team]),
                'you'      => __('You'),
                'sending'  => __('Sending…'),
                'waiting'  => __('Waiting to send'),
                'failed'   => __('Not sent'),
                'retry'    => __('Try Again'),
                'error'    => __('Something went wrong. Try again.'),
                'unavailable'  => __('Chat isn’t available right now. Try again later.'),
                // :time: "in 40 seconds", in the visitor's language (the browser's Intl.RelativeTimeFormat).
                'waitMessages' => __('You’re sending messages quickly. You can send the next one :time.'),
                'waitFiles'    => __('You’ve sent a lot of files. You can send more :time.'),
                'waitOver'     => __('You can send messages again.'),
                'remove'   => __('Remove :name'),
                'repliesTo' => __('Replies also go to :email.'),
                'emailInvalid' => __('Enter an email address, like name@example.com.'),
                'codeInvalid'  => __('Enter the 6-digit code from the email.'),
                'codeSent'     => __('It can take a minute to arrive.'),
                'codeAgain'    => __('We sent a new code.'),
                'codeLast'     => __('We sent a new code. You can ask for another one later.'),
                'confirmed'    => __('Email confirmed.'),
                'tooLarge' => __('Files can be up to :size MB.', ['size' => \App\WebChat\WebChat::MAX_FILE_MB]),
                'tooMany'  => __('Up to :count files per message.', ['count' => \App\WebChat\WebChat::MAX_FILES]),
            ],
        ];
    @endphp
    <div class="widget" x-data="tallportWebChat(@js($web_chat_config))" :data-embedded="embedded" @keydown.escape="escape($event)">
        <header class="widget-header">
            <span class="f-avatar widget-team-mark" aria-hidden="true">@if ($team_photo)<img src="{{ $team_photo }}" alt="">@else{{ mb_strtoupper(mb_substr($team, 0, 1)) }}@endif</span>
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
                        <template x-if="entry.kind === 'event'">
                            <p class="f-message-event widget-event"><span class="f-message-event__icon" aria-hidden="true"><x-icon.mail class="f-icon" /></span><span class="f-message-event__text" x-text="entry.body"></span></p>
                        </template>
                        {{-- After their first message: the reply can go by email too, once a code confirms the address. --}}
                        <template x-if="entry.kind === 'email'">
                            <section class="f-card widget-email" aria-labelledby="widget-email-title">
                                <h2 id="widget-email-title">{{ __('Get the Reply by Email') }}</h2>
                                <template x-if="emailStep === 'address'">
                                    <form @submit.prevent="sendCode" novalidate>
                                        <p class="widget-email-text">{{ __('We’ll answer here. If you leave, we can email you the reply.') }}</p>
                                        <label class="f-label" for="widget-email">{{ __('Email') }}</label>
                                        <div class="widget-email-row"><input id="widget-email" class="f-input" type="email" autocomplete="email" required x-model="emailDraft" :aria-invalid="emailError ? 'true' : 'false'" aria-describedby="widget-email-error" @input="emailError = ''"><button class="f-button" type="submit" :aria-busy="busy ? 'true' : null">{{ __('Send Code') }}</button></div>
                                        <p id="widget-email-error" class="f-error" x-show="emailError" x-text="emailError"></p>
                                    </form>
                                </template>
                                <template x-if="emailStep === 'code'">
                                    <form @submit.prevent="confirmCode" novalidate>
                                        <p class="widget-email-text" x-html="codeIntro"></p>
                                        <label class="f-label" for="widget-code">{{ __('Code') }}</label>
                                        <div class="widget-email-row"><input id="widget-code" class="f-input widget-code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required x-model="codeDraft" :aria-invalid="codeError ? 'true' : 'false'" aria-describedby="widget-code-error widget-code-help" @input="codeError = ''"><button class="f-button" type="submit" :aria-busy="busy ? 'true' : null">{{ __('Confirm') }}</button></div>
                                        <p id="widget-code-error" class="f-error" x-show="codeError" x-text="codeError"></p>
                                        <p id="widget-code-help" class="f-help" role="status" x-text="codeNotice"></p>
                                        <div class="widget-email-actions"><button class="f-button f-button--ghost f-button--small" type="button" @click="sendAgain" :disabled="codesLeft <= 0">{{ __('Send Again') }}</button><button class="f-button f-button--ghost f-button--small" type="button" @click="changeAddress">{{ __('Change Address') }}</button></div>
                                    </form>
                                </template>
                            </section>
                        </template>
                        <div class="widget-message" :data-state="entry.state" x-show="entry.kind === 'team' || entry.kind === 'visitor'">
                            <span class="f-avatar widget-avatar" :class="{ 'widget-team-mark': entry.team }" aria-hidden="true" x-show="entry.kind === 'team' && entry.first"><template x-if="entry.photo"><img :src="entry.photo" alt=""></template><template x-if="!entry.photo"><span x-text="entry.initials"></span></template></span>
                            <p class="widget-author" :class="{ 'f-sr-only': entry.kind === 'visitor' || !entry.first }" x-text="entry.kind === 'visitor' ? text.you : entry.author"></p>
                            <p class="widget-bubble" x-show="entry.body" x-text="entry.body"></p>
                            {{-- An image shows as a thumbnail and opens in a new tab; other files download. --}}
                            <template x-for="file in entry.files" :key="file.id"><div class="widget-attachment"><template x-if="file.thumbnail"><a class="f-attachment f-attachment--thumbnail" :href="file.url" target="_blank" rel="noopener"><span class="f-attachment__thumbnail"><img :src="file.thumbnail" alt="" loading="lazy"></span><span class="f-attachment__body"><span x-text="file.name"></span><small class="f-attachment__detail" x-text="file.size"></small></span></a></template><template x-if="!file.thumbnail"><a class="f-attachment" :href="file.url" :download="file.name"><span class="f-attachment__leading"><x-icon.file-text class="f-icon" aria-hidden="true" /></span><span class="f-attachment__body"><span x-text="file.name"></span><small class="f-attachment__detail" x-text="file.size"></small></span><span class="f-attachment__trailing"><x-icon.download class="f-icon" aria-hidden="true" /></span></a></template></div></template>
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
        {{-- New chats aren't taken now (App\WebChat\Spam): said to a visitor without a conversation. --}}
        <p class="f-alert widget-unavailable" role="status" x-show="!available && !hasConversation" x-cloak>{{ __('Chat isn’t available right now. Try again later.') }}</p>
        <form class="f-composer widget-composer" @submit.prevent="send" x-show="available || hasConversation">
            {{-- Only bots fill this in. --}}
            <input type="text" name="website" x-ref="trap" class="widget-trap" tabindex="-1" autocomplete="off" aria-hidden="true">
            <p class="f-help widget-wait" x-show="waitText" x-text="waitText" x-cloak></p>
            <label class="f-sr-only" for="widget-message">{{ __('Message') }}</label>
            <p class="f-error" x-show="error" x-text="error" x-cloak></p>
            <ul class="widget-files" role="list" aria-label="{{ __('Files to send') }}" x-show="files.length" x-cloak>
                <template x-for="file in files" :key="file.id"><li><span class="f-chip"><span x-text="file.name"></span><button class="f-chip__remove" type="button" @click="removeFile(file)" :aria-label="text.remove.replace(':name', file.name)"><x-icon.x class="f-icon" aria-hidden="true" /></button></span></li></template>
            </ul>
            <div class="f-composer__field"><textarea id="widget-message" class="f-input f-composer__input" rows="1" x-ref="message" x-model="draft" @input="typing()" maxlength="{{ \App\WebChat\WebChat::MAX_LENGTH }}" placeholder="{{ __('Message :name', ['name' => $team]) }}" aria-describedby="widget-message-help" @keydown.enter="if (!$event.shiftKey && !$event.isComposing) { $event.preventDefault(); $el.form.requestSubmit(); }"></textarea><button class="f-button f-button--ghost f-button--icon" type="button" @click="$refs.files.click()" aria-label="{{ __('Attach Files') }}" title="{{ __('Attach Files') }}"><x-icon.paperclip class="f-icon" aria-hidden="true" /></button><input type="file" multiple hidden x-ref="files" @change="addFiles($event.target)"><button class="f-button f-button--primary f-button--icon f-composer__send" type="submit" :disabled="waitText !== ''" aria-label="{{ __('Send') }}"><x-icon.send class="f-icon" aria-hidden="true" /></button></div>
            <p id="widget-message-help" class="f-sr-only">{{ __('Enter to send, Shift+Enter for a new line.') }}</p>
        </form>
        <noscript><p class="widget-noscript">{{ __('Turn on JavaScript to chat with :name.', ['name' => $team]) }}</p></noscript>
    </div>
</body>
</html>
