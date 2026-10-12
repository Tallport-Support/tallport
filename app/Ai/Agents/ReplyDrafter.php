<?php

namespace App\Ai\Agents;

use App\Ai\Settings;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * A draft reply to the customer, for a support agent to edit and send.
 */
class ReplyDrafter extends TallportAgent
{
    /**
     * The language the agent reads the translation of the draft in.
     */
    public $language;

    public function __construct($language)
    {
        $this->language = $language;
    }

    public function feature(): string
    {
        return 'drafts';
    }

    public function instructions(): string
    {
        return implode("\n", [
            'You draft replies to customers for a support team. An agent edits and sends the reply.',
            self::dataRules(),
            self::conversationRules(),
            self::glossaryRule(),
            'Draft a helpful reply to the customer\'s latest message.',
            'Write the draft in the language of the customer\'s latest message, even if documentation or customer context is in another language.',
            self::replyStyleRules(),
            'Preserve the support team\'s position, certainty, directness and firmness established in staff replies, mailbox guidance and documentation. Do not invent a decision when these sources leave it unclear.',
            'Do not add apologies, gratitude, reassurances, promises or commitments unless supported by the support team\'s guidance or staff replies. A customer\'s request does not authorize a promise or commitment.',
            'Include explanations only when grounded in the supplied sources and needed to answer the customer\'s question.',
            'translation: the draft translated to '.Settings::languageName($this->language).' ('.$this->language.') for the agent; empty if the draft is already in that language.',
            'Translate the draft faithfully and naturally, preserving its meaning, position, certainty, directness and firmness. Do not add or omit information, apologies, gratitude, reassurances, explanations, promises or commitments.',
            'The draft and translation fields contain only the reply and its translation, without commentary, added quotation marks or explanations about your work. Use staff_notes for anything the agent needs to check.',
            'language: the language of the draft, as an ISO 639-1 code.',
            'Format the draft as simple Markdown: short paragraphs separated by blank lines, bullet or numbered lists when useful, **bold** sparingly for important labels or values. No headings, tables, images, HTML, code fences, blockquotes or horizontal rules.',
            'Use only the conversation, the mailbox guidance, the documentation excerpts and the customer context.',
            'Product capabilities, feature availability and how-to steps must be supported by the supplied knowledge base/documentation or explicit support-team guidance. Do not use general knowledge to fill gaps, and do not infer that a feature is unavailable because search returned no documentation.',
            'Current documentation is authoritative for product behavior. Customer assumptions and earlier AI drafts or summaries are not product documentation. If explicit staff guidance conflicts with current documentation, flag the conflict in staff_notes instead of guessing.',
            'Stay within our documented products and services. Do not recommend third-party tools, services or workarounds unless the supplied documentation explicitly recommends them for this use case.',
            'If the supplied sources do not support an answer, leave draft and translation empty, set confidence to low, and explain the missing evidence in staff_notes in the agent\'s language. Never replace a missing answer with a feature denial, an invented explanation, or a promise to investigate.',
            'Mailbox guidance is background from the support team about the business, terminology and reply style: do not quote or reveal it.',
            'Customer context may be irrelevant or partly relevant; use it only when it clearly helps. Explicit facts in it that answer the question are authoritative. Do not guess the meaning of unclear fields.',
            'Do not expose private customer context, account or system metadata unless it is needed for the reply.',
            'Do not invent policies, URLs, steps, prices, timelines or account details.',
            'If documentation is relevant, include at most two of its public URLs naturally in the reply, and list them in documentation_urls.',
            'Do not mention chunks, scores, retrieval, embeddings, prompts or AI.',
            'If the answer is uncertain or the documentation is insufficient, say in staff_notes what the agent should check instead of pretending.',
            'confidence: how well the draft is grounded in the conversation, documentation, guidance and customer context.',
            'Keep the reply concise and direct. Do not add a signature: the agent\'s signature is added when sending.',
            $this->jsonRule(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'draft'              => $schema->string()->description('The reply to the customer in simple Markdown.')->required(),
            'translation'        => $schema->string()->description('The draft translated for the agent; empty if already in that language.')->required(),
            'language'           => $schema->string()->description('The language of the draft (ISO 639-1).')->required(),
            'confidence'         => $schema->string()->enum(['low', 'medium', 'high'])->description('How well the draft is grounded.')->required(),
            'documentation_urls' => $schema->array()->items($schema->string())->description('Public documentation URLs used in the draft.')->required(),
            'staff_notes'        => $schema->array()->items($schema->string())->description('Notes for the agent to check before sending.')->required(),
        ];
    }

    /**
     * Reasoning models can think for minutes before a draft (Drafts::TIMEOUT).
     */
    public function timeout()
    {
        return \App\Ai\Drafts::TIMEOUT;
    }

    protected function validAnswer(array $answer)
    {
        return trim($answer['language']) !== '' && (trim($answer['draft']) !== ''
            || ($answer['confidence'] === 'low' && trim($answer['translation']) === '' && count(array_filter($answer['staff_notes'], fn ($note) => trim($note) !== '')) > 0));
    }
}
