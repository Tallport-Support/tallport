@extends('layouts.app')

@section('title_full', __('Telegram').' - '.$mailbox->name)

@section('sidebar')
    @include('partials/sidebar_menu_toggle')
    @include('mailboxes/sidebar_menu')
@endsection

@section('javascript')
    @parent
    confirmButtonsInit();
@endsection

@section('content')

    <div class="section-heading">
        {{ __('Telegram') }}
    </div>

    @include('partials/flash_messages')

    <div class="row-container">
        <div class="row">
            <div class="col-xs-12">
                <p class="text-help margin-top">
                    {{ __('Messages customers send to your Telegram bot become conversations in this mailbox, and your replies are sent back to them on Telegram. Create a bot with @BotFather and enter its token here.') }}
                </p>

                @if ($telegram_error)
                    <div class="alert alert-danger">{{ __('Telegram') }}: {{ $telegram_error }}</div>
                @elseif ($bot)
                    <div class="alert {{ $settings['enabled'] && !$webhook_ok ? 'alert-warning' : 'alert-info' }}">
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
                    </div>
                @endif

                <form class="form-horizontal margin-top" method="POST" action="{{ route('mailboxes.telegram.save', ['id' => $mailbox->id]) }}">
                    {{ csrf_field() }}

                    <div class="form-group">
                        <label for="telegram_enabled" class="col-sm-2 control-label">{{ __('Enabled') }}</label>
                        <div class="col-sm-6">
                            <div class="controls">
                                <div class="onoffswitch-wrap">
                                    <div class="onoffswitch">
                                        <input type="checkbox" name="enabled" value="1" id="telegram_enabled" class="onoffswitch-checkbox" @if (old('enabled', $settings['enabled']))checked="checked"@endif>
                                        <label class="onoffswitch-label" for="telegram_enabled"></label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="form-group{{ $errors->has('token') ? ' has-error' : '' }}">
                        <label for="telegram_token" class="col-sm-2 control-label">{{ __('Bot Token') }}</label>
                        <div class="col-sm-6">
                            <input id="telegram_token" type="password" class="form-control input-sized-lg" name="token" value="{{ \Helper::safePassword($settings['token']) }}" autocomplete="new-password">
                            <div class="form-help">{{ __('The token @BotFather gives you for the bot.') }}</div>
                            @include('partials/field_error', ['field' => 'token'])
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="col-sm-2 control-label">{{ __('Auto Reply') }}</label>
                        <div class="col-sm-6">
                            <ul class="nav nav-tabs">
                                <li class="@if (!$active_language) active @endif"><a href="#telegram_auto_reply_default" data-toggle="tab">{{ __('Default') }}</a></li>
                                @foreach ($settings['auto_replies'] as $language => $text)
                                    <li class="@if ($active_language == $language) active @endif"><a href="#telegram_auto_reply_{{ $language }}" data-toggle="tab">{{ $languages[$language] ?? $language }}</a></li>
                                @endforeach
                            </ul>
                            <div class="tab-content margin-top-10">
                                <div class="tab-pane @if (!$active_language) active @endif" id="telegram_auto_reply_default">
                                    <textarea id="telegram_auto_reply" class="form-control input-sized-lg" rows="3" name="auto_reply" aria-label="{{ __('Auto Reply') }}">{{ old('auto_reply', $settings['auto_reply']) }}</textarea>
                                </div>
                                @foreach ($settings['auto_replies'] as $language => $text)
                                    <div class="tab-pane @if ($active_language == $language) active @endif" id="telegram_auto_reply_{{ $language }}">
                                        <textarea class="form-control input-sized-lg" rows="3" name="auto_replies[{{ $language }}]" aria-label="{{ $languages[$language] ?? $language }}">{{ old('auto_replies.'.$language, $text) }}</textarea>
                                        <button type="submit" class="btn btn-link btn-sm text-danger" name="remove_language" value="{{ $language }}" data-confirm="{{ __('Remove the :language auto reply?', ['language' => $languages[$language] ?? $language]) }}">{{ __('Remove :language', ['language' => $languages[$language] ?? $language]) }}</button>
                                    </div>
                                @endforeach
                            </div>
                            <div class="form-help">{{ __('Sent when a customer starts the bot (/start), in the language of their Telegram app if you add it here.') }}</div>
                            @if (count($languages) > count($settings['auto_replies']))
                                <div class="form-inline margin-top-10">
                                    <select name="add_language_code" class="form-control input-sm" aria-label="{{ __('Language') }}">
                                        @foreach ($languages as $code => $name)
                                            @if (!array_key_exists($code, $settings['auto_replies']))
                                                <option value="{{ $code }}">{{ App\Ai\Settings::optionName($code) }}</option>
                                            @endif
                                        @endforeach
                                    </select>
                                    <button type="submit" class="btn btn-default btn-sm" name="add_language" value="1">{{ __('Add Language') }}</button>
                                </div>
                            @endif
                        </div>
                    </div>

                    <div class="form-group">
                        <div class="col-sm-6 col-sm-offset-2">
                            <label class="checkbox" for="telegram_ignore_start">
                                <input type="checkbox" name="ignore_start" value="1" id="telegram_ignore_start" @if (old('ignore_start', $settings['ignore_start']))checked="checked"@endif>
                                {{ __('Don\'t add the /start message to conversations') }}
                            </label>
                        </div>
                    </div>

                    <div class="form-group margin-top">
                        <div class="col-sm-6 col-sm-offset-2">
                            <button type="submit" class="btn btn-primary">{{ __('Save') }}</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection

@section('javascript')
    @parent
    confirmButtonsInit();
@endsection
