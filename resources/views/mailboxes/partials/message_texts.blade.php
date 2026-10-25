{{-- A message's versions by language, as rows of its group's section (App\Http\Controllers\AutomaticMessagesController):
     the default first, then a Disclosure per language whose summary shows its text on one line, each opening to edit;
     an Add Language menu, and Translate from Default with the AI Assistant (tallportAutomaticMessages). $subject: with
     a subject (for email); $editor: written in the editor, else plain text. $show: an Alpine expression for when they
     show (a channel's own); $disabled: one for when they can't be changed (the mailbox's auto reply, while it's off). --}}
@php
    $texts_name = 'messages['.$group.'][texts]';
    $texts_old = 'messages.'.$group.'.texts';
    $missing_languages = array_diff_key($languages, $texts);
    $add_disabled = $disabled ? '('.$disabled.") ? 'true' : null" : 'null';
@endphp
@foreach ($texts as $language => $text)
    @php
        $text_id = $base.'--'.$language;
        $text_label = $language == 'default' ? __('Default') : ($languages[$language] ?? $language);
        $text_markdown = $editor && App\Misc\Markdown::isMarkdown($text['message_markdown'], $text['message']);
        $text_value = $editor ? ($text_markdown ? old($texts_old.'.'.$language.'.message_markdown', $text['message_markdown']) : old($texts_old.'.'.$language.'.message', $text['message'])) : old($texts_old.'.'.$language, $text);
        $text_preview = trim(strtok(trim(html_entity_decode(strip_tags(preg_replace('#<br\s*/?>|</p>#i', "\n", (string) $text_value)))), "\n") ?: '');
        $text_added = $open == $group.':'.$language;
    @endphp
    <details class="f-disclosure settings-version" id="{{ $text_id }}" data-group="{{ $group }}" data-language="{{ $language }}" @if ($show) x-show="{{ $show }}" @endif @if ($text_added || count($texts) == 1 || $errors->has($texts_old.'.'.$language) || $errors->has($texts_old.'.'.$language.'.*')) open @endif>
        <summary @if ($text_added) x-init="$el.focus()" @endif><span>{{ $text_label }}</span><span class="f-form-row__value">{{ $text_preview }}</span></summary>
        <div class="settings-version__fields">
            @if ($subject)
                <x-fruit::field :label="__('Subject')" :description="__('For email.')">
                    <x-fruit::input id="{{ $text_id }}-subject" name="{{ $texts_name }}[{{ $language }}][subject]" :value="old($texts_old.'.'.$language.'.subject', $text['subject'])" maxlength="{{ $group == 'mailbox' && $language == 'default' ? 128 : 255 }}" data-subject ::disabled="{{ $disabled ?: 'false' }}" />
                </x-fruit::field>
            @endif
            @if ($editor)
                <x-fruit::field :label="__('Message')" :description="$text_markdown ? __('Markdown. Variables are filled in for each customer.') : __('Variables are filled in for each customer.')" class="auto_reply_message-editor">
                    <x-editor id="{{ $text_id }}-message" name="{{ $texts_name }}[{{ $language }}][message]" rows="8" vars :exclude-vars="['user.']" :markdown="$text_markdown" switchable data-message ::disabled="{{ $disabled ?: 'false' }}">{{ $text_value }}</x-editor>
                </x-fruit::field>
            @else
                <x-fruit::field :label="__('Message')" :description="__('Plain text. Variables such as {%customer.firstName%} are filled in for each customer.')">
                    <x-fruit::textarea id="{{ $text_id }}-message" name="{{ $texts_name }}[{{ $language }}]" rows="3" maxlength="{{ App\Http\Controllers\AutomaticMessagesController::MAX_TEXT }}" data-message>{{ $text_value }}</x-fruit::textarea>
                </x-fruit::field>
            @endif
            @if ($language != 'default')
                <div>
                    <x-fruit::button type="submit" variant="danger" size="small" name="remove_language" :value="$group.':'.$language" ::disabled="{{ $disabled ?: 'false' }}" x-on:click.prevent="Tallport.confirm({message: $el.dataset.confirm, detail: $el.dataset.detail, confirm: {{ \Illuminate\Support\Js::from(__('Remove')) }}, tone: 'danger'}).then(ok => ok && $el.form.requestSubmit($el))" :data-confirm="__('Remove the :language version?', ['language' => $text_label])" :data-detail="__('Customers writing in it get Default instead.')">{{ __('Remove :language…', ['language' => $text_label]) }}</x-fruit::button>
                </div>
            @endif
        </div>
    </details>
@endforeach
<div class="f-form-row settings-version-add" @if ($show) x-show="{{ $show }}" @endif>
    @if ($missing_languages)
        <x-fruit::menu :title="__('Add Language')">
            <x-slot:trigger ::aria-disabled="{{ $add_disabled }}">{{ __('Add Language') }}<span class="f-menu__chevron" aria-hidden="true"></span></x-slot:trigger>
            @foreach ($missing_languages as $code => $name)
                <x-fruit::menu-item x-on:click="addLanguage($el, {{ \Illuminate\Support\Js::from($group.':'.$code) }})">{{ App\Ai\Settings::optionName($code) }}</x-fruit::menu-item>
            @endforeach
        </x-fruit::menu>
    @endif
    @if ($can_translate && count($texts) > 1)
        <x-fruit::button ::disabled="{{ $disabled ?: 'false' }}" x-on:click="translate($el, {{ \Illuminate\Support\Js::from($group) }})">{{ __('Translate from Default') }}</x-fruit::button>
    @endif
</div>
