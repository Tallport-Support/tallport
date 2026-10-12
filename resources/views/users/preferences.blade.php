@extends('layouts.app')

@section('title', __('Preferences'))

@section('title_full', __('Preferences').' - '.$user->getFullName())

{{-- A level beside the sidebar; saved as it changes, the whole form. --}}
@section('drilldown')
<x-fruit::drilldown-column :title="__('Preferences')" id="user-preferences">
    <x-fruit::page width="narrow">
        <form class="settings-form" method="POST" action="{{ route('users.preferences.save', ['id' => $user->id]) }}" x-on:change="Tallport.autoSave($el)">
            {{ csrf_field() }}

            <x-fruit::form-section :title="__('General')" :level="3">
                <x-fruit::field :label="__('Language')" layout="row">
                    <x-fruit::select id="locale" name="locale">
                        @include('partials/locale_options', ['selected' => old('locale', $user->getLocale())])
                    </x-fruit::select>
                </x-fruit::field>

                <x-fruit::field :label="__('Timezone')" layout="row">
                    <x-fruit::select id="timezone" name="timezone" required>
                        @include('partials/timezone_options', ['current_timezone' => old('timezone', $user->timezone)])
                    </x-fruit::select>
                </x-fruit::field>

                <x-fruit::fieldset>
                    <legend>{{ __('Time Format') }}</legend>
                    <x-fruit::radio id="12hour" name="time_format" :value="App\User::TIME_FORMAT_12" :checked="old('time_format', $user->time_format) == App\User::TIME_FORMAT_12">{{ __('12-hour clock (e.g. 2:13pm)') }}</x-fruit::radio>
                    <x-fruit::radio id="24hour" name="time_format" :value="App\User::TIME_FORMAT_24" :checked="old('time_format', $user->time_format) == App\User::TIME_FORMAT_24 || !$user->time_format">{{ __('24-hour clock (e.g. 14:13)') }}</x-fruit::radio>
                </x-fruit::fieldset>

                <x-fruit::field :label="__('Keyboard Shortcuts')" :description="__('On (press ? to see them)')" layout="row">
                    <input type="hidden" name="keyboard_shortcuts_shown" value="1">
                    <x-fruit::switch id="keyboard_shortcuts" name="keyboard_shortcuts" value="1" :checked="$user->hasKeyboardShortcuts()" />
                </x-fruit::field>
            </x-fruit::form-section>

            {{-- Unset: Pending after a reply. --}}
            <x-fruit::form-section :title="__('Replies')" :level="3">
                <x-fruit::field :label="__('Status After a Reply')" layout="row">
                    <x-fruit::select id="reply_status" name="reply_status">
                        @foreach ([App\Conversation::STATUS_ACTIVE, App\Conversation::STATUS_PENDING, App\Conversation::STATUS_CLOSED] as $status)
                            <option value="{{ $status }}" @if (old('reply_status', $user->replyStatus()) == $status) selected @endif>{{ App\Conversation::statusCodeToName($status) }}</option>
                        @endforeach
                    </x-fruit::select>
                </x-fruit::field>

                <x-fruit::field :label="__('When a Conversation Moves')" :description="__('Stay in Folder opens the next conversation in the folder you are in.')" layout="row">
                    <x-fruit::select id="stay_in_conversation" name="stay_in_conversation">
                        <option value="0" @if (!old('stay_in_conversation', $user->stay_in_conversation)) selected @endif>{{ __('Stay in Folder') }}</option>
                        <option value="1" @if (old('stay_in_conversation', $user->stay_in_conversation)) selected @endif>{{ __('Stay in Conversation') }}</option>
                    </x-fruit::select>
                </x-fruit::field>
            </x-fruit::form-section>

            <x-fruit::form-section :title="__('Translation')" :level="3">
                {{-- Summaries and translations are in the user's language (General); these aren't translated. --}}
                <x-fruit::field :label="__('No Translation Needed')" :description="__('Messages in these languages are shown as written.')" layout="row">
                    <input type="hidden" name="languages_shown" value="1">
                    <x-fruit::select id="languages" name="languages[]" multiple size="6">
                        @foreach (App\Ai\Settings::displayNames() as $code => $name)
                            <option value="{{ $code }}" @selected(in_array($code, (array) old('languages', $user->languages)))>{{ App\Ai\Settings::optionName($code) }}</option>
                        @endforeach
                    </x-fruit::select>
                </x-fruit::field>
            </x-fruit::form-section>

            {{-- The accent: the installation's (Settings » Appearance) unless one is chosen here; previewed when picked. --}}
            <x-fruit::form-section :title="__('Appearance')" :level="3" x-data="{ installation: {{ $user->accent ? 'false' : 'true' }} }">
                <x-fruit::field :label="__('Use the Installation Default')" layout="row">
                    <x-fruit::switch name="accent_default" value="1" :checked="!$user->accent" x-model="installation" x-on:change="if (installation) document.documentElement.dataset.fruitAccent = {{ \Illuminate\Support\Js::from(App\Misc\Branding::accent()) }}" />
                </x-fruit::field>
                <div class="f-form-row">
                    <span>{{ __('Accent Color') }}</span>
                    <fieldset class="accent-choice" x-bind:disabled="installation"><x-fruit::accent-picker name="accent" :value="App\Misc\Branding::accent($user)" x-on:change="document.documentElement.dataset.fruitAccent = $event.target.value" /></fieldset>
                </div>
            </x-fruit::form-section>

        </form>
    </x-fruit::page>
</x-fruit::drilldown-column>
@endsection
