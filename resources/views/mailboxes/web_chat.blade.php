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
                    <x-fruit::field :label="__('Greeting')" :description="__('The first message in the chat, from the team. Leave it empty for none.')">
                        <x-fruit::textarea id="web_chat_greeting" name="greeting" rows="2" maxlength="1000">{{ old('greeting', $settings['greeting']) }}</x-fruit::textarea>
                    </x-fruit::field>

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
