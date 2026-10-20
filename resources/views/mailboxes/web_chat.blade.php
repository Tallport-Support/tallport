@extends('mailboxes/layout_page')

@section('page_width', 'narrow')

@section('title_full', __('Website Chat').' - '.$mailbox->name)

@section('content')
    <div class="page-content">
        @include('partials/flash_messages')

        <div class="settings-form">
            <p class="f-help">{{ __('A chat button on your websites. Visitors’ messages become conversations in this mailbox, and they see your replies in the chat.') }}</p>

            {{-- Saved with its button: the greeting goes to visitors, never half-written. --}}
            <form id="page-form" class="settings-form" method="POST" action="{{ route('mailboxes.web_chat.save', ['id' => $mailbox->id]) }}">
                {{-- Enter in a field saves (the form's first submit button), not Add Language. --}}
                <button type="submit" hidden aria-hidden="true" tabindex="-1"></button>
                {{ csrf_field() }}

                <x-fruit::form-section>
                    <x-fruit::field :label="__('Enabled')" layout="row">
                        <x-fruit::switch id="web_chat_enabled" name="enabled" value="1" :checked="(bool) old('enabled', $settings['enabled'])" />
                    </x-fruit::field>

                    <x-fruit::field :label="__('Websites')" :description="__('The websites the chat may be on, one per line, such as https://www.example.com.')">
                        <x-fruit::textarea id="web_chat_origins" name="origins" rows="3" placeholder="https://www.example.com">{{ old('origins', implode("\n", $settings['origins'])) }}</x-fruit::textarea>
                    </x-fruit::field>
                </x-fruit::form-section>

                <x-fruit::form-section :title="__('What Visitors See')" :level="3">
                    {{-- The chat's first message (WebChat::greeting()): Tallport's, translated and by name; the mailbox's own, with versions by language; or none. --}}
                    <div class="settings-form" x-data="{ greetingMode: @js(old('greeting_mode', $settings['greeting_mode'])) }">
                        <x-fruit::fieldset>
                            <legend>{{ __('Greeting') }}</legend>
                            <x-fruit::radio name="greeting_mode" value="tallport" id="web_chat_greeting_tallport" x-model="greetingMode" :checked="old('greeting_mode', $settings['greeting_mode']) == 'tallport'" :description="__('“Hi, how can we help?” in the visitor’s language, with their first name when your website signs them in.')">{{ __('Tallport’s Greeting') }}</x-fruit::radio>
                            <x-fruit::radio name="greeting_mode" value="custom" id="web_chat_greeting_custom" x-model="greetingMode" :checked="old('greeting_mode', $settings['greeting_mode']) == 'custom'">{{ __('Your Own Greeting') }}</x-fruit::radio>
                            <x-fruit::radio name="greeting_mode" value="none" id="web_chat_greeting_none" x-model="greetingMode" :checked="old('greeting_mode', $settings['greeting_mode']) == 'none'">{{ __('No Greeting') }}</x-fruit::radio>
                        </x-fruit::fieldset>

                        <div x-show="greetingMode === 'custom'" x-cloak>
                            <div x-data="fruitTabs">
                                <x-fruit::tabs :aria-label="__('Language')">
                                    <x-fruit::tab id="web_chat_greeting_default_tab" aria-controls="web_chat_greeting_default" :aria-selected="$active_language ? 'false' : 'true'">{{ __('Default') }}</x-fruit::tab>
                                    @foreach ($settings['greetings'] as $language => $text)
                                        <x-fruit::tab id="web_chat_greeting_{{ $language }}_tab" aria-controls="web_chat_greeting_{{ $language }}" :aria-selected="$active_language == $language ? 'true' : 'false'">{{ $languages[$language] ?? $language }}</x-fruit::tab>
                                    @endforeach
                                </x-fruit::tabs>
                                <div role="tabpanel" id="web_chat_greeting_default" aria-labelledby="web_chat_greeting_default_tab" class="auto-reply-panel" @if ($active_language) hidden @endif>
                                    <x-fruit::textarea id="web_chat_greeting" name="greeting" rows="2" maxlength="1000" :aria-label="__('Greeting')">{{ old('greeting', $settings['greeting']) }}</x-fruit::textarea>
                                </div>
                                @foreach ($settings['greetings'] as $language => $text)
                                    <div role="tabpanel" id="web_chat_greeting_{{ $language }}" aria-labelledby="web_chat_greeting_{{ $language }}_tab" class="auto-reply-panel f-stack" @if ($active_language != $language) hidden @endif>
                                        <x-fruit::textarea rows="2" maxlength="1000" name="greetings[{{ $language }}]" :aria-label="$languages[$language] ?? $language">{{ old('greetings.'.$language, $text) }}</x-fruit::textarea>
                                        <div><x-fruit::button type="submit" variant="danger" size="small" name="remove_language" :value="$language">{{ __('Remove :language', ['language' => $languages[$language] ?? $language]) }}</x-fruit::button></div>
                                    </div>
                                @endforeach
                            </div>
                            <p class="f-help">{{ __('Visitors in a language without its own version get the default. The name goes in with {%customer.firstName,fallback=there%}; also {%customer.fullName%} and {%mailbox.name%}.') }}</p>
                            @if (count($languages) > count($settings['greetings']))
                                <div class="f-row">
                                    <x-fruit::select name="add_language_code" :aria-label="__('Language')" class="auto-reply-add-language">
                                        @foreach ($languages as $code => $name)
                                            @if (!array_key_exists($code, $settings['greetings']))
                                                <option value="{{ $code }}">{{ App\Ai\Settings::optionName($code) }}</option>
                                            @endif
                                        @endforeach
                                    </x-fruit::select>
                                    <x-fruit::button type="submit" size="small" name="add_language" value="1">{{ __('Add Language') }}</x-fruit::button>
                                </div>
                            @endif
                        </div>
                    </div>

                    <x-fruit::field :label="__('Reply Time')" :description="__('Below the mailbox’s name, such as “We usually reply within an hour”. Leave it empty for none.')" layout="row">
                        <x-fruit::input id="web_chat_reply_time" name="reply_time" maxlength="100" :value="old('reply_time', $settings['reply_time'])" />
                    </x-fruit::field>

                    <div class="f-form-row">
                        <span>{{ __('Accent Color') }}</span>
                        <x-fruit::accent-picker name="accent" :value="old('accent', $settings['accent'])" />
                    </div>
                </x-fruit::form-section>

                <footer class="f-form-row settings-form__actions">
                    <x-fruit::button type="submit" variant="primary">{{ __('Save') }}</x-fruit::button>
                </footer>
            </form>

            @if ($snippet)
                <x-fruit::form-section :title="__('On Your Website')" :level="3" :footer="__('Add the code to every page, before the closing body tag.')">
                    <div class="f-form-row">
                        <pre>{{ $snippet }}</pre>
                        <x-fruit::copy-button size="small" :value="$snippet">{{ __('Copy') }}</x-fruit::copy-button>
                    </div>
                    {{-- The chat on a page of its own: a "Chat with us" link, and the fallback without JavaScript. --}}
                    <x-fruit::field :label="__('Chat Link')" :description="__('The chat on a page of its own: for a “Chat with Us” link in your menu or emails, and for visitors without JavaScript.')">
                        <div class="f-input-group">
                            <x-fruit::input id="web_chat_link" :value="$link" readonly />
                            <x-fruit::copy-button :value="$link">{{ __('Copy') }}</x-fruit::copy-button>
                        </div>
                    </x-fruit::field>
                </x-fruit::form-section>

                {{-- A website serving /t/ through its own domain (examples/website-chat/proxy). --}}
                <x-fruit::form-section :title="__('Through Your Website’s Domain')" :level="3" :footer="__('Your website serves the chat’s folder, /t/, so visitors’ browsers only talk to it. Its proxy sends this secret, so Tallport counts each visitor’s own address for its limits.')">
                    <div class="f-form-row">
                        <span>{{ __('Proxy Secret') }}</span>
                        <form method="POST" action="{{ route('mailboxes.web_chat.save', ['id' => $mailbox->id]) }}" class="f-row">
                            {{ csrf_field() }}
                            @if ($proxy_secret)
                                <x-fruit::copy-button size="small" :value="$proxy_secret">{{ __('Copy') }}</x-fruit::copy-button>
                                <x-fruit::button type="submit" size="small" variant="danger" name="action" value="rotate_proxy_secret" x-data x-on:click.prevent="Tallport.confirm({message: $el.dataset.confirm, confirm: $el.textContent.trim(), tone: 'danger'}).then(ok => ok && $el.form.requestSubmit($el))" data-confirm="{{ __('Make a new proxy secret? Until your website’s proxy sends the new one, its visitors are counted together for the limits.') }}">{{ __('Rotate') }}</x-fruit::button>
                            @else
                                <x-fruit::button type="submit" size="small" name="action" value="make_proxy_secret">{{ __('Make Secret') }}</x-fruit::button>
                            @endif
                        </form>
                    </div>
                    <a class="f-form-row f-form-row--link" href="{{ App\WebChat\WebChat::EXAMPLES_URL }}/proxy" target="_blank" rel="noopener"><span>{{ __('Examples') }}</span><span class="f-form-row__value">nginx, Caddy, Apache, Cloudflare, Netlify, Vercel, PHP</span></a>
                </x-fruit::form-section>

                {{-- Visitors signed in on the website (App\WebChat\Identity, examples/website-chat/sign-in). --}}
                <x-fruit::form-section :title="__('Signed-In Visitors')" :level="3" :footer="__('Your website’s server signs who the visitor is with this secret, and the page passes it on. They get their chat back on any device, and your team sees who they are.')">
                    <div class="f-form-row">
                        <span>{{ __('Secret') }}</span>
                        <form method="POST" action="{{ route('mailboxes.web_chat.save', ['id' => $mailbox->id]) }}" class="f-row">
                            {{ csrf_field() }}
                            @if ($secret)
                                <x-fruit::copy-button size="small" :value="$secret">{{ __('Copy') }}</x-fruit::copy-button>
                                <x-fruit::button type="submit" size="small" variant="danger" name="action" value="rotate_secret" x-data x-on:click.prevent="Tallport.confirm({message: $el.dataset.confirm, confirm: $el.textContent.trim(), tone: 'danger'}).then(ok => ok && $el.form.requestSubmit($el))" data-confirm="{{ __('Make a new secret? Signing in with the old one stops working until your website uses the new one.') }}">{{ __('Rotate') }}</x-fruit::button>
                            @else
                                <x-fruit::button type="submit" size="small" name="action" value="make_secret">{{ __('Make Secret') }}</x-fruit::button>
                            @endif
                        </form>
                    </div>
                    <a class="f-form-row f-form-row--link" href="{{ App\WebChat\WebChat::EXAMPLES_URL }}/sign-in" target="_blank" rel="noopener"><span>{{ __('Examples') }}</span><span class="f-form-row__value">PHP, Node.js, Python</span></a>
                </x-fruit::form-section>
            @endif
        </div>
    </div>
@endsection
