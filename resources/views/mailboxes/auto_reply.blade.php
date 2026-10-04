@extends('layouts.app')

@section('title_full', __('Auto Reply').' - '.$mailbox->name)

@section('main_class', 'fruit-ui')

@section('sidebar')
    @include('mailboxes/sidebar_menu')
@endsection

@section('content')
    <div class="page-content">
        @include('partials/flash_messages')

        <form class="settings-form settings-form--wide" method="POST" action="">
            {{ csrf_field() }}

            <x-fruit::switch id="auto_reply_enabled" name="auto_reply_enabled" value="1" :checked="(bool) old('auto_reply_enabled', $mailbox->auto_reply_enabled)">
                {{ __('Enable Auto Reply') }}
                <x-slot:description>
                    {{ strip_tags(str_replace('<br/>', ' ', __('When a customer emails this mailbox, application can send an auto reply to the customer immediately.<br/><br/>Only one auto reply is sent per new conversation.'))) }}
                </x-slot:description>
            </x-fruit::switch>

            <p class="f-help">
                {{ __('Customers get the auto reply in their language if you add it here; otherwise the default one. Chinese, Japanese and Korean are recognised from the characters used, other languages by the AI Assistant (when it is set up).') }}
            </p>

            <div x-data="fruitTabs" class="auto-reply-tabs">
                <x-fruit::tabs :aria-label="__('Language')">
                    <x-fruit::tab id="auto_reply_default_tab" aria-controls="auto_reply_default" :aria-selected="$active_language ? 'false' : 'true'">{{ __('Default') }}</x-fruit::tab>
                    @foreach ($versions as $language => $version)
                        <x-fruit::tab id="auto_reply_{{ $language }}_tab" aria-controls="auto_reply_{{ $language }}" :aria-selected="$active_language == $language ? 'true' : 'false'">{{ $languages[$language] ?? $language }}@if (!$version->enabled) <small class="f-muted">({{ __('off') }})</small>@endif</x-fruit::tab>
                    @endforeach
                </x-fruit::tabs>

                <div role="tabpanel" id="auto_reply_default" aria-labelledby="auto_reply_default_tab" class="settings-form auto-reply-panel" @if ($active_language) hidden @endif>
                    <x-fruit::field :label="__('Subject')">
                        <x-fruit::input id="auto_reply_subject" name="auto_reply_subject" :value="old('auto_reply_subject', $mailbox->auto_reply_subject)" maxlength="128" />
                    </x-fruit::field>

                    <x-fruit::field :label="__('Message')" class="auto_reply_message-editor">
                        <x-fruit::textarea id="auto_reply_message" class="auto-reply-editor" name="auto_reply_message" rows="8">{{ old('auto_reply_message', $mailbox->auto_reply_message) }}</x-fruit::textarea>
                    </x-fruit::field>
                </div>

                @foreach ($versions as $language => $version)
                    <div role="tabpanel" id="auto_reply_{{ $language }}" aria-labelledby="auto_reply_{{ $language }}_tab" class="settings-form auto-reply-panel" @if ($active_language != $language) hidden @endif>
                        <x-fruit::switch name="versions[{{ $language }}][enabled]" value="1" id="auto_reply_{{ $language }}_enabled" :checked="(bool) old('versions.'.$language.'.enabled', $version->enabled)">{{ __('Enabled') }}</x-fruit::switch>

                        <x-fruit::field :label="__('Subject')">
                            <x-fruit::input id="auto_reply_{{ $language }}_subject" name="versions[{{ $language }}][subject]" :value="old('versions.'.$language.'.subject', $version->subject)" maxlength="255" />
                        </x-fruit::field>

                        <x-fruit::field :label="__('Message')" class="auto_reply_message-editor">
                            <x-fruit::textarea id="auto_reply_{{ $language }}_message" class="auto-reply-editor" name="versions[{{ $language }}][message]" rows="8">{{ old('versions.'.$language.'.message', $version->message) }}</x-fruit::textarea>
                        </x-fruit::field>

                        <div>
                            <x-fruit::button type="submit" variant="danger" size="small" name="remove_language" :value="$language" data-confirm="{{ __('Remove the :language auto reply?', ['language' => $languages[$language] ?? $language]) }}">{{ __('Remove :language', ['language' => $languages[$language] ?? $language]) }}</x-fruit::button>
                        </div>
                    </div>
                @endforeach
            </div>

            <p class="f-help">
                {{ __("Auto replies don't include your mailbox signature, so be sure to add your contact information if necessary.") }}
            </p>
            @if (count($languages) > count($versions))
                <div class="f-row">
                    <x-fruit::select name="add_language_code" :aria-label="__('Language')" class="auto-reply-add-language">
                        @foreach ($languages as $code => $name)
                            @if (!$versions->has($code))
                                <option value="{{ $code }}">{{ App\Ai\Settings::optionName($code) }}</option>
                            @endif
                        @endforeach
                    </x-fruit::select>
                    <x-fruit::button type="submit" size="small" name="add_language" value="1">{{ __('Add Language') }}</x-fruit::button>
                </div>
            @endif

            <div class="settings-form__actions">
                <x-fruit::button type="submit" variant="primary">{{ __('Save') }}</x-fruit::button>
            </div>
        </form>
    </div>

@endsection

@include('partials/editor')

@section('javascript')
    @parent
    $('.auto-reply-editor').each(function() {
        summernoteInit('#'+$(this).attr('id'), {insertVar: true, excludeVars: ['%user.']});
    });
    confirmButtonsInit();
@endsection