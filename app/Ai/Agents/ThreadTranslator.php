<?php

namespace App\Ai\Agents;

use App\Ai\Settings;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * A customer's message translated for the support team.
 */
class ThreadTranslator extends TallportAgent
{
    public $language;

    /**
     * The message is HTML, translated as it looks (Translations::sourceHtml()).
     */
    public $html;

    /**
     * The conversation's subject is translated with it (its first message: the subject is its own).
     */
    public $subject;

    public function __construct($language, $html = false, $subject = false)
    {
        $this->language = $language;
        $this->html = $html;
        $this->subject = $subject;
    }

    public function feature(): string
    {
        return 'translations';
    }

    public function fast(): bool
    {
        return true;
    }

    public function instructions(): string
    {
        return implode("\n", array_filter([
            'You translate customer support emails for the support team.',
            self::dataRules(),
            self::conversationRules(),
            self::glossaryRule(),
            'Translate the message to: '.Settings::languageName($this->language).' ('.$this->language.').',
            'Do not change the content, do not add information, keep the paragraphs.',
            $this->html
                ? 'The message is HTML. Translate only the text people read; keep every tag, attribute, link address and image exactly as it is. translation: the translated HTML itself, as a string.'
                : 'translation: only the translated text.',
            'If the message is already in the target language, set same_language to true and leave translation empty. Judge by the text the customer wrote: ignore quoted earlier emails (and their "On ... wrote:" line), signatures, disclaimers and single words or names in other languages.',
            'detected_language: the language the customer wrote the message in, as an ISO 639-1 code (for Chinese: zh-Hans or zh-Hant).',
            $this->subject ? 'subject: the email\'s subject line (the subject data) translated the same way, plain text; empty if it is in the target language already.' : null,
            $this->jsonRule(),
        ]));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'translation'       => $schema->string()->description('The translated message.')->required(),
            'same_language'     => $schema->boolean()->description('Whether the message is already in the target language.')->required(),
            'detected_language' => $schema->string()->description('The language of the message (ISO 639-1; zh-Hans or zh-Hant for Chinese).')->required(),
        ] + ($this->subject ? ['subject' => $schema->string()->description('The subject line translated; empty if already in the target language.')->required()] : []);
    }

    /**
     * The subject is asked for (schema()), but an answer without one is still a translation.
     */
    protected function answerSchema()
    {
        $schema = parent::answerSchema();
        $schema['required'] = array_values(array_diff($schema['required'] ?? [], ['subject']));

        return $schema;
    }

    protected function validAnswer(array $answer)
    {
        return $this->validTranslation($answer) && trim($answer['detected_language']) !== '';
    }
}
