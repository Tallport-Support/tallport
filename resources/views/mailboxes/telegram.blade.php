@extends('mailboxes/layout_page')

@section('page_width', 'narrow')

@section('title_full', __('Telegram').' - '.$mailbox->name)

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

            {{-- Saved with its button. --}}
            <form id="page-form" class="settings-form" method="POST" action="{{ route('mailboxes.telegram.save', ['id' => $mailbox->id]) }}">
                {{ csrf_field() }}

                <x-fruit::form-section :title="__('Bot')" :level="3">
                    <x-fruit::field :label="__('Enabled')" layout="row">
                        <x-fruit::switch id="telegram_enabled" name="enabled" value="1" :checked="(bool) old('enabled', $settings['enabled'])" />
                    </x-fruit::field>

                    <x-fruit::field :label="__('Bot Token')" :description="__('The token @BotFather gives you for the bot.')" layout="row">
                        <x-fruit::input type="password" id="telegram_token" name="token" :value="\Helper::safePassword($settings['token'])" autocomplete="new-password" />
                    </x-fruit::field>

                    <x-fruit::checkbox name="ignore_start" value="1" id="telegram_ignore_start" :checked="(bool) old('ignore_start', $settings['ignore_start'])">{{ __('Don\'t add the /start message to conversations') }}</x-fruit::checkbox>
                </x-fruit::form-section>

                <footer class="f-form-row settings-form__actions">
                    <x-fruit::button type="submit" variant="primary">{{ __('Save') }}</x-fruit::button>
                </footer>
            </form>

            {{-- What the bot says by itself: the mailbox's greeting (its description, and at Start) and auto reply, or its own. --}}
            @include('mailboxes/partials/message_links', ['key' => 'telegram'])
        </div>
    </div>
@endsection
