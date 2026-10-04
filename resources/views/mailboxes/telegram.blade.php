@extends('layouts.app')

@section('title_full', __('Telegram').' - '.$mailbox->name)

@section('main_class', 'fruit-ui')

@section('sidebar')
    @include('mailboxes/sidebar_menu')
@endsection

@section('content')
    <div class="page-content">
        @include('partials/flash_messages')

        <div class="settings-form">
            <p class="f-help">
                {{ __('Messages customers send to your Telegram bot become conversations in this mailbox, and your replies are sent back to them on Telegram. Create a bot with @BotFather and enter its token here.') }}
            </p>

            @if ($telegram_error)
                <x-fruit::alert tone="danger">{{ __('Telegram') }}: {{ $telegram_error }}</x-fruit::alert>
            @elseif ($bot)
                <x-fruit::alert :tone="$settings['enabled'] && !$webhook_ok ? 'warning' : 'info'">
                    {{ __('Bot') }}: <a href="https://t.me/{{ $bot['username'] ?? '' }}" target="_blank" rel="noopener noreferrer">{{ '@'.($bot['username'] ?? '') }}</a>
                    @if ($settings['enabled'])
                        <br>
                        @if ($webhook_ok)
                            {{ __('Receiving messages') }}.
                            @if (!empty($webhook['pending_update_count']))
                                {{ __('Messages waiting to be received') }}: {{ $webhook['pending_update_count'] }}.
                            @endif
                        @else
                            {{ __('Not receiving messages: save the settings to connect the bot.') }}
                        @endif
                        @if (!empty($webhook['last_error_message']) && !empty($webhook['last_error_date']) && $webhook['last_error_date'] > time() - 86400)
                            <br>{{ __('Last error') }} ({{ App\User::dateFormat(Carbon\Carbon::createFromTimestamp($webhook['last_error_date'])) }}): {{ $webhook['last_error_message'] }}
                        @endif
                    @endif
                </x-fruit::alert>
            @endif

            <form class="settings-form" method="POST" action="{{ route('mailboxes.telegram.save', ['id' => $mailbox->id]) }}">
                {{ csrf_field() }}

                <x-fruit::form-section :title="__('Bot')">
                    <x-fruit::field :label="__('Enabled')" layout="row">
                        <x-fruit::switch id="telegram_enabled" name="enabled" value="1" :checked="(bool) old('enabled', $settings['enabled'])" />
                    </x-fruit::field>

                    <x-fruit::field :label="__('Bot Token')" :description="__('The token @BotFather gives you for the bot.')" layout="row">
                        <x-fruit::input type="password" id="telegram_token" name="token" :value="\Helper::safePassword($settings['token'])" autocomplete="new-password" />
                    </x-fruit::field>

                    <x-fruit::checkbox name="ignore_start" value="1" id="telegram_ignore_start" :checked="(bool) old('ignore_start', $settings['ignore_start'])">{{ __('Don\'t add the /start message to conversations') }}</x-fruit::checkbox>
                </x-fruit::form-section>

                <x-fruit::form-section :title="__('Auto Reply')" :footer="__('Sent when a customer starts the bot (/start), in the language of their Telegram app if you add it here.')">
                    <div x-data="fruitTabs">
                        <x-fruit::tabs :aria-label="__('Language')">
                            <x-fruit::tab id="telegram_auto_reply_default_tab" aria-controls="telegram_auto_reply_default" :aria-selected="$active_language ? 'false' : 'true'">{{ __('Default') }}</x-fruit::tab>
                            @foreach ($settings['auto_replies'] as $language => $text)
                                <x-fruit::tab id="telegram_auto_reply_{{ $language }}_tab" aria-controls="telegram_auto_reply_{{ $language }}" :aria-selected="$active_language == $language ? 'true' : 'false'">{{ $languages[$language] ?? $language }}</x-fruit::tab>
                            @endforeach
                        </x-fruit::tabs>
                        <div role="tabpanel" id="telegram_auto_reply_default" aria-labelledby="telegram_auto_reply_default_tab" class="auto-reply-panel" @if ($active_language) hidden @endif>
                            <x-fruit::textarea id="telegram_auto_reply" rows="3" name="auto_reply" :aria-label="__('Auto Reply')">{{ old('auto_reply', $settings['auto_reply']) }}</x-fruit::textarea>
                        </div>
                        @foreach ($settings['auto_replies'] as $language => $text)
                            <div role="tabpanel" id="telegram_auto_reply_{{ $language }}" aria-labelledby="telegram_auto_reply_{{ $language }}_tab" class="auto-reply-panel f-stack" @if ($active_language != $language) hidden @endif>
                                <x-fruit::textarea rows="3" name="auto_replies[{{ $language }}]" :aria-label="$languages[$language] ?? $language">{{ old('auto_replies.'.$language, $text) }}</x-fruit::textarea>
                                <div><x-fruit::button type="submit" variant="danger" size="small" name="remove_language" :value="$language" x-data x-on:click.prevent="Tallport.confirm({message: $el.dataset.confirm, confirm: $el.textContent.trim(), tone: 'danger'}).then(ok => ok && $el.form.requestSubmit($el))" data-confirm="{{ __('Remove the :language auto reply?', ['language' => $languages[$language] ?? $language]) }}">{{ __('Remove :language', ['language' => $languages[$language] ?? $language]) }}</x-fruit::button></div>
                            </div>
                        @endforeach
                    </div>

                    @if (count($languages) > count($settings['auto_replies']))
                        <div class="f-row">
                            <x-fruit::select name="add_language_code" :aria-label="__('Language')" class="auto-reply-add-language">
                                @foreach ($languages as $code => $name)
                                    @if (!array_key_exists($code, $settings['auto_replies']))
                                        <option value="{{ $code }}">{{ App\Ai\Settings::optionName($code) }}</option>
                                    @endif
                                @endforeach
                            </x-fruit::select>
                            <x-fruit::button type="submit" size="small" name="add_language" value="1">{{ __('Add Language') }}</x-fruit::button>
                        </div>
                    @endif
                </x-fruit::form-section>

                <footer class="f-form-row settings-form__actions">
                    <x-fruit::button type="submit" variant="primary">{{ __('Save') }}</x-fruit::button>
                </footer>
            </form>
        </div>
    </div>
@endsection

