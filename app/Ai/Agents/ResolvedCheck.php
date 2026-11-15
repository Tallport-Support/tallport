<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\HasStructuredOutput;

/**
 * Whether a customer's latest message says they need nothing more from the team (App\Ai\Resolved):
 * only when it plainly says so, never when unsure.
 */
class ResolvedCheck extends TallportAgent implements HasStructuredOutput
{
    public function instructions(): string
    {
        return implode("\n", [
            'You decide whether a customer\'s latest message in a support conversation means the support team can close the conversation.',
            self::dataRules(),
            'The conversation\'s latest messages are in <conversation>, oldest first; the last one is the customer\'s latest message. Staff messages are the team\'s replies to the customer.',
            'resolved: true only when the customer\'s latest message clearly says they need nothing more: their request is solved (by themselves or by the team\'s answer), it works now, they found what they needed, or they no longer need help with it; or it only thanks the team for a reply that answered or solved their request. It must not ask anything, report a problem, add a request, or expect an answer.',
            'Otherwise false, and false whenever you are unsure: when something still doesn\'t work or only partly works; when they will try something, check or report back later; when they thank the team for a question, for looking into it or for a reply that doesn\'t solve the request yet; when words like "solved", "fixed", "works", "never mind" or "thanks" are about something else, quoted, hypothetical, conditional or sarcastic; and for automatic messages (out of office, delivery notices).',
            'Judge the customer\'s own words in the latest message, not quoted earlier emails or signatures; earlier messages only tell what the request was and what the team answered.',
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'resolved' => $schema->boolean()->description('Whether the customer needs nothing more, so the conversation can close.')->required(),
        ];
    }
}
