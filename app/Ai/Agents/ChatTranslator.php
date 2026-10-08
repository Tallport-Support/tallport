<?php

namespace App\Ai\Agents;

use App\Ai\Settings;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * A customer's latest chat messages translated for the support team together (App\Ai\ChatTranslation),
 * with the chat's earlier messages for context.
 */
class ChatTranslator extends TallportAgent
{
    public $language;

    public $message_ids;

    public function __construct($language, array $message_ids = [])
    {
        $this->language = $language;
        $this->message_ids = $message_ids;
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
            'You translate a customer\'s chat messages for the support team.',
            self::dataRules(),
            self::glossaryRule(),
            'Translate each message to: '.Settings::languageName($this->language).' ('.$this->language.').',
            'The chat\'s earlier messages are given for context only, so that the meaning, references and tone come across; do not translate them.',
            'Do not change the content, do not add information, keep the line breaks. Keep names, product names, codes, numbers and URLs as they are.',
            'messages: one entry per message, with its id. If a message is already in the target language, set same_language to true and leave its translation empty.',
            'detected_language: the language the customer writes in, as an ISO 639-1 code (for Chinese: zh-Hans or zh-Hant).',
            $this->jsonRule(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'messages' => $schema->array()->items($schema->object([
                'id'            => $schema->integer()->required(),
                'translation'   => $schema->string()->required(),
                'same_language' => $schema->boolean()->required(),
            ]))->description('The messages, translated.')->required(),
            'detected_language' => $schema->string()->description('The language the customer writes in (ISO 639-1; zh-Hans or zh-Hant for Chinese).')->required(),
        ];
    }

    protected function validAnswer(array $answer)
    {
        if (trim($answer['detected_language']) === '') {
            return false;
        }
        $seen = [];
        foreach ($answer['messages'] as $message) {
            $id = $message['id'];
            if (!in_array($id, $this->message_ids, true) || isset($seen[$id]) || !$this->validTranslation($message)) {
                return false;
            }
            $seen[$id] = true;
        }

        return true;
    }
}
