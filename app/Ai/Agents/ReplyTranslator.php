<?php

namespace App\Ai\Agents;

use App\Ai\Settings;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\UseCheapestModel;
use Laravel\Ai\Contracts\HasStructuredOutput;

/**
 * An agent's chat reply translated into the customer's language (App\Ai\ChatTranslation),
 * with the chat's latest messages for context.
 */
#[UseCheapestModel]
class ReplyTranslator extends TallportAgent implements HasStructuredOutput
{
    public $language;

    public function __construct($language)
    {
        $this->language = $language;
    }

    public function instructions(): string
    {
        return implode("\n", [
            'You translate a support agent\'s chat reply for the customer.',
            self::dataRules(),
            'Translate the reply to: '.Settings::languageName($this->language).' ('.$this->language.').',
            'The chat\'s latest messages are given for context only, so that the reply\'s meaning, references and tone come across; do not translate or repeat them.',
            'Do not change the content, do not add information, keep the paragraphs and the tone. Keep names, product names, codes, numbers and URLs as they are.',
            'The reply is HTML. Translate only the text people read; keep every tag, attribute, link address and image exactly as it is. translation: the translated HTML, not JSON.',
            'If the reply is already in the target language, set same_language to true and leave translation empty.',
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'translation'   => $schema->string()->description('The translated reply (HTML).')->required(),
            'same_language' => $schema->boolean()->description('Whether the reply is already in the target language.')->required(),
        ];
    }
}
