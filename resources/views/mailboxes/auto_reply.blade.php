@extends('layouts.app')

@section('page_width', 'narrow')

@section('title_full', __('Auto Reply').' - '.$mailbox->name)

@section('main_class', 'fruit-ui')

@section('sidebar')
    @include('mailboxes/sidebar_menu')
@endsection

@section('content')
    <div class="page-content">
        @include('partials/flash_messages')

        <form id="page-form" class="settings-form" method="POST" action="">
            {{ csrf_field() }}

            <x-fruit::form-section :title="__('Auto Reply')" :footer="__('Customers get the auto reply in their language if you add it here; otherwise the default one. Chinese, Japanese and Korean are recognised from the characters used, other languages by the AI (when it is set up).').' '.__('Auto replies don\'t include your mailbox signature, so be sure to add your contact information if necessary.')">
                <x-fruit::field :label="__('Enable Auto Reply')" layout="row">
                    <x-fruit::switch id="auto_reply_enabled" name="auto_reply_enabled" value="1" :checked="(bool) old('auto_reply_enabled', $mailbox->auto_reply_enabled)" />
                    <x-slot:description>{{ strip_tags(str_replace('<br/>', ' ', __('When a customer emails this mailbox, application can send an auto reply to the customer immediately.<br/><br/>Only one auto reply is sent per new conversation.'))) }}</x-slot:description>
                </x-fruit::field>

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
                            <x-editor id="auto_reply_message" name="auto_reply_message" rows="8" vars :exclude-vars="['user.']">{{ old('auto_reply_message', $mailbox->auto_reply_message) }}</x-editor>
                        </x-fruit::field>
                    </div>

                    @foreach ($versions as $language => $version)
                        <div role="tabpanel" id="auto_reply_{{ $language }}" aria-labelledby="auto_reply_{{ $language }}_tab" class="settings-form auto-reply-panel" @if ($active_language != $language) hidden @endif>
                            <x-fruit::field :label="__('Enabled')" layout="row">
                                <x-fruit::switch name="versions[{{ $language }}][enabled]" value="1" id="auto_reply_{{ $language }}_enabled" :checked="(bool) old('versions.'.$language.'.enabled', $version->enabled)" />
                            </x-fruit::field>

                            <x-fruit::field :label="__('Subject')">
                                <x-fruit::input id="auto_reply_{{ $language }}_subject" name="versions[{{ $language }}][subject]" :value="old('versions.'.$language.'.subject', $version->subject)" maxlength="255" />
                            </x-fruit::field>

                            <x-fruit::field :label="__('Message')" class="auto_reply_message-editor">
                                <x-editor id="auto_reply_{{ $language }}_message" name="versions[{{ $language }}][message]" rows="8" vars :exclude-vars="['user.']">{{ old('versions.'.$language.'.message', $version->message) }}</x-editor>
                            </x-fruit::field>

                            <div>
                                <x-fruit::button type="submit" variant="danger" size="small" name="remove_language" :value="$language" x-data x-on:click.prevent="Tallport.confirm({message: $el.dataset.confirm, confirm: $el.textContent.trim(), tone: 'danger'}).then(ok => ok && $el.form.requestSubmit($el))" data-confirm="{{ __('Remove the :language auto reply?', ['language' => $languages[$language] ?? $language]) }}">{{ __('Remove :language', ['language' => $languages[$language] ?? $language]) }}</x-fruit::button>
                            </div>
                        </div>
                    @endforeach
                </div>

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
            </x-fruit::form-section>

        </form>
    </div>

@endsection

@section('page_footer')
    <x-fruit::button type="submit" form="page-form" variant="primary">{{ __('Save') }}</x-fruit::button>
@endsection
