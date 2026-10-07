<?php

namespace App\Ai\Agents;

use App\Ai\Settings;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\HasStructuredOutput;

/**
 * Which of some languages a customer's message is written in.
 */
class LanguageRecognizer extends TallportAgent implements HasStructuredOutput
{
    /**
     * Language codes to choose from (App\Ai\Settings::LANGUAGES).
     */
    public $languages;

    public function __construct(array $languages)
    {
        $this->languages = array_values($languages);
    }

    public function feature(): string
    {
        return 'language';
    }

    public function instructions(): string
    {
        $choices = array_map(function ($code) {
            return $code.' ('.Settings::languageName($code).')';
        }, $this->languages);

        return implode("\n", [
            'You recognise the language a customer support message is written in.',
            self::dataRules(),
            'language: the code of the language the customer wrote the message in, if it is one of these: '.implode(', ', $choices).'. Otherwise "other".',
            'Judge by the customer\'s own words, not by quoted text, signatures, names or links.',
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'language' => $schema->string()->enum(array_merge($this->languages, ['other']))->description('The language code, or "other".')->required(),
        ];
    }
}
