<?php

namespace App\Ai\Agents;

use App\Ai\Settings;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\HasStructuredOutput;

/**
 * A mailbox's automatic message (its greeting or auto reply, App\AutoReply\ChannelMessages)
 * translated from its default version, for its version in another language (Translate from
 * Default); an email's with its subject.
 */
class MessageTranslator extends TallportAgent implements HasStructuredOutput
{
    public $language;

    /**
     * The message's format: html, markdown or text.
     */
    public $format;

    public $subject;

    public function __construct($language, $format = 'text', $subject = false)
    {
        $this->language = $language;
        $this->format = $format;
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
            'You translate a support team\'s automatic message to its customers (a greeting or an auto reply).',
            self::dataRules(),
            self::glossaryRule(),
            'Translate the message to: '.Settings::languageName($this->language).' ('.$this->language.').',
            'Use natural, contemporary phrasing for native speakers of the target language, in the tone of the original. Do not add or omit information; keep the paragraphs and line breaks.',
            'Keep placeholders such as {%customer.firstName,fallback=there%} exactly as they are, wherever the sentence needs them. Preserve names, product names, addresses, numbers and links.',
            [
                'html'     => 'The message is HTML. Translate only the text people read; keep every tag, attribute, link address and image exactly as it is. message: the translated HTML itself, as a string.',
                'markdown' => 'The message is Markdown. Keep its syntax (emphasis, lists, links and their addresses) exactly as it is. message: the translated Markdown.',
            ][$this->format] ?? 'message: only the translated text.',
            $this->subject ? 'subject: the email\'s subject line (the subject data) translated the same way, plain text.' : null,
        ]));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'message' => $schema->string()->description('The translated message.')->required(),
        ] + ($this->subject ? ['subject' => $schema->string()->description('The translated subject line.')->required()] : []);
    }
}
