<?php

namespace App\Ai\Agents;

use App\Ai\Settings;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * An agent's reply (a chat message or an email) translated into the customer's language
 * (App\Ai\ChatTranslation), with the conversation's latest messages for context.
 */
class ReplyTranslator extends TallportAgent
{
    public $language;

    public function __construct($language)
    {
        $this->language = $language;
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
        return implode("\n", [
            'You translate a support agent\'s reply (a chat message or an email) for the customer.',
            self::dataRules(),
            self::conversationRules(),
            self::glossaryRule(),
            'Translate the reply to: '.Settings::languageName($this->language).' ('.$this->language.').',
            self::replyStyleRules(),
            'Preserve the agent\'s intended meaning, position, certainty, directness and level of firmness. Style adaptation must not change any of these.',
            'Do not introduce apologies, gratitude, reassurances, explanations, promises or commitments absent from the original reply. Do not add or omit information; keep the paragraphs.',
            'Use the conversation history only for context. Translate only the agent\'s latest reply in <reply>, not the conversation.',
            'The reply is HTML. Translate only the text people read; keep every tag, attribute, link address and image exactly as it is. translation: the translated HTML itself, as a string.',
            'The translation field must contain only the translated reply, without commentary, added quotation marks or explanations.',
            'If the reply is already in the target language, set same_language to true and leave translation empty.',
            'note: the words "Translated automatically" in the target language (shown to the customer under the reply).',
            $this->jsonRule(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'translation'   => $schema->string()->description('The translated reply (HTML).')->required(),
            'same_language' => $schema->boolean()->description('Whether the reply is already in the target language.')->required(),
            'note'          => $schema->string()->description('"Translated automatically" in the target language.')->required(),
        ];
    }

    protected function validAnswer(array $answer)
    {
        return $this->validTranslation($answer);
    }
}
