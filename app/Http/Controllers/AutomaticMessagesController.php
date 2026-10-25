<?php

namespace App\Http\Controllers;

use App\AutoReply\ChannelMessages;
use App\Mailbox;
use App\MailboxAutoReply;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * A mailbox's automatic messages for all its channels (App\AutoReply\ChannelMessages), each a page
 * of its settings: the greeting and the auto reply. Each shows the mailbox's message in languages,
 * then a group per channel the mailbox has: the mailbox's, its own (in languages too) or none.
 * Saved with the page's Save; adding or removing a language keeps what was typed, unchecked.
 *
 * The form's groups (messages[group]): "mailbox" and the channels' keys, each with a mode and its
 * texts by language ("default" for the default), an email's with a subject and a message (editor).
 */
class AutomaticMessagesController extends Controller
{
    /**
     * Length of a chat's text (greeting or auto reply), in characters.
     */
    const MAX_TEXT = 1000;

    public function __construct()
    {
        $this->middleware('auth');
    }

    public function greeting($id)
    {
        $mailbox = Mailbox::findOrFail($id);
        $this->authorize('updateAutoReply', $mailbox);
        $settings = ChannelMessages::settings($mailbox);
        $groups = ['mailbox' => ['mode' => $settings['greeting']['mode'], 'texts' => $this->formTexts($settings['greeting']['texts'])]];
        foreach (ChannelMessages::forMailbox($mailbox, true) as $key => $channel) {
            $own = $settings['channels'][$key][ChannelMessages::GREETING] ?? ['mode' => ChannelMessages::MAILBOX, 'texts' => []];
            $groups[$key] = ['label' => $channel['label'], 'mode' => $own['mode'], 'texts' => $this->formTexts($own['texts'])];
        }

        return view('mailboxes/greeting', $this->viewData($mailbox, $groups));
    }

    public function greetingSave($id, Request $request)
    {
        $mailbox = Mailbox::findOrFail($id);
        $this->authorize('updateAutoReply', $mailbox);
        $settings = ChannelMessages::settings($mailbox);
        $input = (array) $request->input('messages', []);
        $adding = $request->filled('add_language') || $request->filled('remove_language');

        $groups = ['mailbox' => ['modes' => [ChannelMessages::TALLPORT, ChannelMessages::OWN, ChannelMessages::OFF]]];
        foreach (ChannelMessages::forMailbox($mailbox, true) as $key => $channel) {
            $groups[$key] = ['modes' => [ChannelMessages::MAILBOX, ChannelMessages::OWN, ChannelMessages::OFF]];
        }
        $texts = [];
        $modes = [];
        $rules = [];
        $names = [];
        foreach ($groups as $group => $options) {
            $modes[$group] = in_array($input[$group]['mode'] ?? null, $options['modes'], true) ? $input[$group]['mode'] : $options['modes'][0];
            $texts[$group] = $this->postedTexts($request, $group, false);
            if ($modes[$group] == ChannelMessages::OWN) {
                $this->textRules($texts[$group], $group, false, $rules, $names);
            }
        }
        if (!$adding && $rules) {
            $validator = $this->validator($texts, $rules, $names);
            if ($validator->fails()) {
                return $this->savedResponse(redirect()->route('mailboxes.greeting', ['id' => $mailbox->getSlug()])->withErrors($validator)->withInput(), $validator);
            }
        }

        $settings['greeting'] = ['mode' => $modes['mailbox'], 'texts' => $texts['mailbox']];
        foreach (array_keys($groups) as $group) {
            if ($group != 'mailbox') {
                $settings['channels'][$group][ChannelMessages::GREETING] = ['mode' => $modes[$group], 'texts' => $texts[$group]];
            }
        }
        ChannelMessages::saveSettings($mailbox, $settings);
        // The bot's description in its empty chat.
        \App\Telegram\Telegram::syncDescriptionLater($mailbox);

        return $this->saved($mailbox, 'mailboxes.greeting', $request);
    }

    public function autoReply($id)
    {
        $mailbox = Mailbox::findOrFail($id);
        $this->authorize('updateAutoReply', $mailbox);
        $settings = ChannelMessages::settings($mailbox);
        $texts = ['default' => [
            'subject'          => $mailbox->auto_reply_subject ?: 'Re: {%subject%}',
            'message'          => $mailbox->auto_reply_message,
            'message_markdown' => $mailbox->auto_reply_message_markdown,
        ]];
        foreach (\App\AutoReply\AutoReplies::versions($mailbox) as $language => $version) {
            $texts[$language] = ['subject' => $version->subject, 'message' => $version->message, 'message_markdown' => $version->message_markdown];
        }
        $groups = ['mailbox' => ['mode' => null, 'texts' => $texts, 'email' => true]];
        foreach (ChannelMessages::forMailbox($mailbox) as $key => $channel) {
            $own = $settings['channels'][$key][ChannelMessages::AUTO_REPLY] ?? ['mode' => ChannelMessages::MAILBOX, 'texts' => []];
            $groups[$key] = ['label' => $channel['label'], 'mode' => $own['mode'], 'texts' => $this->formTexts($own['texts'], $key == 'email'), 'email' => $key == 'email'];
        }

        return view('mailboxes/auto_reply', $this->viewData($mailbox, $groups));
    }

    public function autoReplySave($id, Request $request)
    {
        $mailbox = Mailbox::findOrFail($id);
        $this->authorize('updateAutoReply', $mailbox);
        $settings = ChannelMessages::settings($mailbox);
        $input = (array) $request->input('messages', []);
        $enabled = $request->filled('auto_reply_enabled');
        $adding = $request->filled('add_language') || $request->filled('remove_language');

        $channels = array_keys(ChannelMessages::forMailbox($mailbox));
        // While it's off, the mailbox's versions can't be changed (the page sends none).
        $texts = $enabled ? ['mailbox' => $this->postedTexts($request, 'mailbox', true)] : [];
        $modes = [];
        $rules = [];
        $names = [];
        if ($enabled) {
            $this->textRules($texts['mailbox'], 'mailbox', true, $rules, $names, $mailbox->hasEmail());
        }
        foreach ($channels as $key) {
            $modes[$key] = in_array($input[$key]['mode'] ?? null, [ChannelMessages::OWN, ChannelMessages::OFF], true) ? $input[$key]['mode'] : ChannelMessages::MAILBOX;
            $texts[$key] = $this->postedTexts($request, $key, $key == 'email');
            if ($enabled && $modes[$key] == ChannelMessages::OWN) {
                $this->textRules($texts[$key], $key, $key == 'email', $rules, $names);
            }
        }
        if (!$adding && $rules) {
            $validator = $this->validator($texts, $rules, $names);
            if ($validator->fails()) {
                return $this->savedResponse(redirect()->route('mailboxes.auto_reply', ['id' => $mailbox->getSlug()])->withErrors($validator)->withInput(), $validator);
            }
        }

        // The mailbox's: its columns, and its versions in other languages. Without email, the page
        // has no subjects: they stay as they are (a new version takes Default's).
        $mailbox->auto_reply_enabled = $enabled;
        if ($enabled) {
            $default = $texts['mailbox'][''] ?? ['subject' => null, 'message' => '', 'message_markdown' => null];
            $mailbox->auto_reply_subject = mb_substr($default['subject'] ?? ($mailbox->auto_reply_subject ?: 'Re: {%subject%}'), 0, 128);
            $mailbox->auto_reply_message = $default['message'];
            $mailbox->auto_reply_message_markdown = $default['message_markdown'];
        }
        $mailbox->save();
        if ($enabled) {
            $versions = array_diff_key($texts['mailbox'], ['' => true]);
            MailboxAutoReply::where('mailbox_id', $mailbox->id)->whereNotIn('language', array_keys($versions) ?: [''])->delete();
            foreach ($versions as $language => $text) {
                $version = MailboxAutoReply::firstOrNew(['mailbox_id' => $mailbox->id, 'language' => $language]);
                // Used once it has a text.
                $version->enabled = trim(str_replace('&nbsp;', ' ', strip_tags($text['message'], '<img>'))) !== '';
                $version->subject = $text['subject'] ?? ($version->exists ? $version->subject : $mailbox->auto_reply_subject);
                $version->message = $text['message'];
                $version->message_markdown = $text['message_markdown'];
                $version->save();
            }
        }
        foreach ($channels as $key) {
            $settings['channels'][$key][ChannelMessages::AUTO_REPLY] = ['mode' => $modes[$key], 'texts' => $texts[$key]];
        }
        ChannelMessages::saveSettings($mailbox, $settings);

        return $this->saved($mailbox, 'mailboxes.auto_reply', $request);
    }

    /**
     * Translate from Default: a group's default text (and an email's subject) in other languages,
     * for its versions in them (filled in on the page, saved with it).
     */
    public function translate($id, Request $request)
    {
        $mailbox = Mailbox::findOrFail($id);
        $this->authorize('updateAutoReply', $mailbox);
        $request->validate([
            'message'     => 'required|string|max:20000',
            'subject'     => 'nullable|string|max:255',
            'format'      => 'required|in:html,markdown,text',
            'languages'   => 'required|array|max:10',
            'languages.*' => 'in:'.implode(',', array_keys(\App\Ai\Settings::LANGUAGES)),
        ]);
        if (!self::canTranslate($mailbox)) {
            return response()->json(['status' => 'error', 'msg' => __('The AI Assistant isn’t available for this mailbox.')], 422);
        }
        $translations = [];
        foreach (array_unique($request->languages) as $language) {
            try {
                $answer = (new \App\Ai\Agents\MessageTranslator($language, $request->format, $request->filled('subject')))
                    ->recordFor(\App\Ai\Usage::FEATURE_TRANSLATION, null, $mailbox->id, auth()->id())
                    ->prompt(implode("\n\n", array_filter([
                        \App\Ai\Agents\TallportAgent::glossary($mailbox),
                        $request->filled('subject') ? \App\Ai\Agents\TallportAgent::data('subject', $request->subject) : '',
                        \App\Ai\Agents\TallportAgent::data('message', $request->message),
                    ])));
            } catch (\Throwable $e) {
                \Helper::logException($e, '[Automatic Messages] Not translated (mailbox '.$mailbox->id.'):');

                return response()->json(['status' => 'error', 'msg' => __('The translation failed. Try again later.')], 422);
            }
            $translations[$language] = ['message' => (string) $answer['message']] + ($request->filled('subject') ? ['subject' => (string) ($answer['subject'] ?? '')] : []);
        }

        return response()->json(['status' => 'success', 'translations' => $translations]);
    }

    /**
     * Whether the page offers Translate from Default: the AI Assistant translates for the mailbox.
     */
    public static function canTranslate(Mailbox $mailbox)
    {
        return \App\Ai\Settings::isConfigured() && \App\Ai\Settings::enabled('translations', $mailbox) && \App\Ai\Settings::withinBudget($mailbox);
    }

    protected function viewData(Mailbox $mailbox, array $groups)
    {
        return [
            'mailbox'       => $mailbox,
            'groups'        => $groups,
            'languages'     => \App\Ai\Settings::displayNames(),
            'open'          => session('automatic_messages_open'),
            'can_translate' => self::canTranslate($mailbox),
            // Translate from Default's questions and answers (tallportAutomaticMessages).
            'texts'         => [
                'translate'  => __('Translate Default into :languages?'),
                'replaced'   => __('Their text is replaced with Default’s, translated.'),
                'confirm'    => __('Translate'),
                'translated' => __('Translated into :languages. Check each before customers get it.'),
                'empty'      => __('Write Default first.'),
            ],
        ];
    }

    /**
     * Saved texts as the form has them: the default first, as "default".
     */
    protected function formTexts(array $texts, $email = false)
    {
        $form = ['default' => $email ? ['subject' => '', 'message' => '', 'message_markdown' => null] : ''];
        foreach ($texts as $language => $text) {
            $form[$language === '' ? 'default' : $language] = $email ? (array) $text + ['subject' => '', 'message' => '', 'message_markdown' => null] : (string) $text;
        }

        return $form;
    }

    /**
     * A group's texts as posted, by language ('' the default), with a language added (from the
     * default's text) or removed: plain text (trimmed), or an email's subject (null when the page
     * has none) and message (HTML, and its Markdown).
     */
    protected function postedTexts(Request $request, $group, $email)
    {
        $languages = \App\Ai\Settings::LANGUAGES;
        $texts = [];
        foreach ((array) $request->input('messages.'.$group.'.texts', []) as $language => $text) {
            $language = $language === 'default' ? '' : (string) $language;
            if ($language !== '' && (!isset($languages[$language]) || $request->remove_language === $group.':'.$language)) {
                continue;
            }
            if ($email) {
                [$html, $markdown] = \App\Misc\Markdown::fromInput((array) $text, 'message') ?? ['', null];
                $texts[$language] = [
                    'subject'          => array_key_exists('subject', (array) $text) ? mb_substr(trim((string) $text['subject']), 0, 255) : null,
                    'message'          => \Helper::stripDangerousTags(\Helper::purifyHtml($html)),
                    'message_markdown' => $markdown,
                ];
            } else {
                $texts[$language] = mb_substr(trim((string) $text), 0, self::MAX_TEXT);
            }
        }
        [$add_group, $code] = explode(':', (string) $request->add_language, 2) + [1 => ''];
        if ($add_group === $group && isset($languages[$code]) && !isset($texts[$code])) {
            $texts[$code] = $texts[''] ?? ($email ? ['subject' => null, 'message' => '', 'message_markdown' => null] : '');
            \Session::flash('automatic_messages_open', $group.':'.$code);
        }
        $default = array_key_exists('', $texts) ? ['' => $texts['']] : [];

        return $default + array_diff_key($texts, ['' => true]);
    }

    /**
     * Every version of a group is needed: its text, and an email's subject (when it has them).
     */
    protected function textRules(array $texts, $group, $email, array &$rules, array &$names, $subjects = true)
    {
        foreach (array_keys(['' => true] + $texts) as $language) {
            $field = 'messages.'.$group.'.texts.'.($language === '' ? 'default' : $language);
            if ($email) {
                if ($subjects) {
                    // The mailbox's default subject: its column's length.
                    $rules[$field.'.subject'] = 'required|string|max:'.($group == 'mailbox' && $language === '' ? 128 : 255);
                    $names[$field.'.subject'] = __('Subject');
                }
                $rules[$field.'.message'] = 'required|string';
            } else {
                $rules[$field] = 'required|string';
            }
            $names[$email ? $field.'.message' : $field] = __('Message');
        }
    }

    /**
     * The texts checked by their words (an email's message without its tags), under the form's names.
     */
    protected function validator(array $texts, array $rules, array $names)
    {
        $data = [];
        foreach ($texts as $group => $group_texts) {
            foreach ($group_texts as $language => $text) {
                $data[$group]['texts'][$language === '' ? 'default' : $language] = is_array($text)
                    ? ['subject' => $text['subject'], 'message' => trim(str_replace('&nbsp;', ' ', strip_tags($text['message'], '<img>')))] : $text;
            }
        }

        return Validator::make(['messages' => $data], $rules)->setAttributeNames($names);
    }

    protected function saved(Mailbox $mailbox, $route, Request $request)
    {
        if (!$request->ajax() && !$request->filled('add_language') && !$request->filled('remove_language')) {
            \Session::flash('flash_success_floating', __('Settings updated'));
        }

        return $this->savedResponse(redirect()->route($route, ['id' => $mailbox->getSlug()]));
    }
}
