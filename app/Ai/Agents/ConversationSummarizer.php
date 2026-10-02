<?php

namespace App\Ai\Agents;

use App\Ai\Settings;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\HasStructuredOutput;

/**
 * A conversation's summary: a one-liner for the conversation list and a
 * chronological bullet list shown above the threads.
 */
class ConversationSummarizer extends TallportAgent implements HasStructuredOutput
{
    public $language;

    public function __construct($language)
    {
        $this->language = $language;
    }

    public function instructions(): string
    {
        return implode("\n", [
            'You summarize customer support conversations for the support team.',
            self::dataRules(),
            'Write in this language: '.Settings::languageName($this->language).' ('.$this->language.').',
            'one_liner: concise one-line status of the conversation, max 25 words.',
            'summary: newline-separated Markdown bullet list, each bullet starts with "- ".',
            'Summary bullets are chronological from the oldest notable update to the newest.',
            'Each bullet describes one notable update in plain language, max 22 words.',
            'Use the participant names from the author field; do not use generic roles like customer, staff, user or agent.',
            'Skip greetings, signatures, quoted text, auto-replies, boilerplate, duplicate acknowledgements and other non-noteworthy messages.',
            'Merge adjacent messages when they add to the same update, especially from the same author.',
            'Include internal notes only when they materially change the support state or next action.',
            'Prefer 3-8 bullets; fewer when the conversation is short.',
            'Do not use lead-ins like "Subject shows", "The latest thread" or "The email"; do not describe the layout of the conversation, just its content.',
            'State facts only, do not draw conclusions.',
            'Examples (bad -> good):',
            '"The customer says the server is down. Staff asks which server. The customer says Belgium." -> "- Joe reports the server is down.\n- Alice asks Joe which server is affected.\n- Joe says Belgium is affected."',
            '"- Customer reports Belgium is down.\n- Customer reports Netherlands is also down." -> "- Joe reports Belgium and Netherlands are down."',
            '"- Alice says hello." -> "- Alice asks whether the Pro plan can be cancelled before renewal."',
            '"- The email, titled Payment Completed, informs us that the transaction succeeded." -> "- Payment has been confirmed."',
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'one_liner' => $schema->string()->description('A concise one-liner summary of the conversation. Max 25 words.')->required(),
            'summary'   => $schema->string()->description('A chronological newline-separated Markdown bullet list of notable updates. Each line starts with "- ".')->required(),
        ];
    }
}
