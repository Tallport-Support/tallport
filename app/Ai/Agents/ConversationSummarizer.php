<?php

namespace App\Ai\Agents;

use App\Ai\Settings;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\HasStructuredOutput;

/**
 * A conversation's summary: a one-liner on where it stands (the conversation list and the
 * top of the conversation), and for long conversations a short background: what's been
 * tried and what's still open (App\Ai\Summaries::BACKGROUND_MIN_MESSAGES).
 */
class ConversationSummarizer extends TallportAgent implements HasStructuredOutput
{
    public $language;

    public $with_background;

    public function __construct($language, $with_background = false)
    {
        $this->language = $language;
        $this->with_background = (bool) $with_background;
    }

    public function feature(): string
    {
        return 'summaries';
    }

    public function instructions(): string
    {
        return implode("\n", [
            'You summarize customer support conversations for the support team.',
            self::dataRules(),
            'Write in this language: '.Settings::languageName($this->language).' ('.$this->language.').',
            'one_liner: where the conversation stands now, in one line, max 25 words: what the customer needs and what is happening about it.',
            $this->with_background
                ? 'background: at most 3 newline-separated Markdown bullets (each starts with "- "), max 20 words each, on what has already been tried and what is still open, so someone new to the conversation needs not read it all. Not a history: no retelling of the messages in order.'
                : 'background: an empty string.',
            'Use the participant names from the author field; do not use generic roles like customer, staff, user or agent.',
            'Skip greetings, signatures, quoted text, auto-replies, boilerplate and acknowledgements.',
            'Include internal notes only when they materially change the support state or next action.',
            'Do not use lead-ins like "Subject shows", "The latest thread" or "The email"; do not describe the layout of the conversation, just its content.',
            'State facts only, do not draw conclusions.',
            'Examples (bad -> good):',
            '"The customer wrote about a problem." -> "Joe reports the Belgium server is down; Alice is checking the network."',
            '"- Joe wrote on Monday.\n- Alice replied.\n- Joe wrote again." -> "- Restarting the app and reinstalling did not help.\n- Still open: whether other Belgium users are affected."',
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'one_liner'  => $schema->string()->description('Where the conversation stands now, in one line. Max 25 words.')->required(),
            'background' => $schema->string()->description('At most 3 Markdown bullets on what has been tried and what is still open, or an empty string.')->required(),
        ];
    }
}
