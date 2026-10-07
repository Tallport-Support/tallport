<?php

namespace App\Ai\Agents;

use App\Ai\Settings;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\HasStructuredOutput;

/**
 * A customer's message translated for the support team.
 */
class ThreadTranslator extends TallportAgent implements HasStructuredOutput
{
    public $language;

    /**
     * The message is HTML, translated as it looks (Translations::sourceHtml()).
     */
    public $html;

    public function __construct($language, $html = false)
    {
        $this->language = $language;
        $this->html = $html;
    }

    public function feature(): string
    {
        return 'translations';
    }

    public function instructions(): string
    {
        return implode("\n", [
            'You translate customer support emails for the support team.',
            self::dataRules(),
            self::glossaryRule(),
            'Translate the message to: '.Settings::languageName($this->language).' ('.$this->language.').',
            'Do not change the content, do not add information, keep the paragraphs.',
            $this->html
                ? 'The message is HTML. Translate only the text people read; keep every tag, attribute, link address and image exactly as it is. translation: the translated HTML, not JSON.'
                : 'translation: only the translated text, not JSON.',
            'If the message is already in the target language, set same_language to true and leave translation empty. Judge by the text the customer wrote: ignore quoted earlier emails (and their "On ... wrote:" line), signatures, disclaimers and single words or names in other languages.',
            'detected_language: the language the customer wrote the message in, as an ISO 639-1 code.',
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'translation'       => $schema->string()->description('The translated message.')->required(),
            'same_language'     => $schema->boolean()->description('Whether the message is already in the target language.')->required(),
            'detected_language' => $schema->string()->description('The language of the message (ISO 639-1).')->required(),
        ];
    }
}
