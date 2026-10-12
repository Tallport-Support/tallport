<?php

namespace Tests\Feature;

use App\Ai\Agents\ChatTranslator;
use App\Ai\Agents\ConversationSummarizer;
use App\Ai\Agents\ReplyDrafter;
use App\Ai\Agents\ReplyTranslator;
use App\Ai\Agents\ThreadTranslator;
use App\Ai\ChatTranslation;
use App\Ai\ConversationContext;
use App\Ai\Document;
use App\Ai\Documents;
use App\Ai\Drafts;
use App\Ai\Summaries;
use App\Ai\Translations;
use App\Conversation;
use App\Jobs\AiIndexDocument;
use App\Jobs\AiSummarizeConversation;
use App\KbArticle;
use App\Option;
use App\Thread;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Embeddings;
use Tests\FeatureTestCase;

class AiConversationContextTest extends FeatureTestCase
{
    protected function conversation()
    {
        $agent = $this->createUser();
        $mailbox = $this->createMailbox([$agent]);
        $this->receiveEmail($mailbox, $this->makeEmail([
            'from' => 'Casey <casey@customer.example.org>', 'to' => $mailbox->email,
            'subject' => 'Android', 'body' => 'How do I use the ad-blocker on Android?',
        ]));
        Option::set('aiassistant.api_key', encrypt('sk-test'));
        Option::set('aiassistant.summary_conversation_threshold', 0);

        return [$agent, $mailbox, Conversation::where('mailbox_id', $mailbox->id)->first()];
    }

    protected function message($conversation, $body, $type = Thread::TYPE_CUSTOMER)
    {
        return Thread::create($conversation, $type, $body, [
            'created_by_customer_id' => $conversation->customer_id,
            'source_via' => Thread::PERSON_CUSTOMER, 'source_type' => Thread::SOURCE_TYPE_WEB,
        ]);
    }

    protected function data($prompt, $tag)
    {
        preg_match('#<'.$tag.'>\n(.*?)\n</'.$tag.'>#s', $prompt, $matches);

        return json_decode($matches[1], true);
    }

    protected function remember($conversation, $id, $memory = 'An older troubleshooting attempt failed.', $language = 'en')
    {
        Summaries::updateData($conversation, function ($data) use ($id, $memory, $language) {
            $data['summaries'][$language] = ['one_liner' => 'Checking Android.', 'background' => '', 'memory' => $memory, 'thread_id' => $id, 'at' => now()->toDateTimeString()];

            return $data;
        });
    }

    protected function article($mailbox, $body)
    {
        $article = new KbArticle();
        $article->title = '12VPX NEO for Android';
        $article->mailbox_id = $mailbox->id;
        $article->body = $body;
        $article->save();

        return $article;
    }

    public function testSummaryReadsEveryBatchInOrderAndCarriesMemoryForward()
    {
        [, , $conversation] = $this->conversation();
        $ids = [$conversation->threads()->first()->id];
        foreach (range(1, 24) as $i) {
            $ids[] = $this->message($conversation, 'Message '.$i)->id;
        }
        $calls = [];
        ConversationSummarizer::fake(function ($prompt) use (&$calls) {
            $calls[] = $this->data($prompt, 'conversation');

            return ['one_liner' => 'Still checking.', 'background' => '', 'memory' => 'The first attempt failed; keep that fact.'];
        });

        Summaries::summarize($conversation, 'en');
        $this->assertTrue(Summaries::isStale($conversation, 'en'));
        Summaries::summarize($conversation, 'en');

        $this->assertNull($calls[0]['previous_summary']);
        $this->assertSame(array_slice($ids, 0, 20), array_column($calls[0]['messages'], 'id'));
        $this->assertSame(array_slice($ids, 20), array_column($calls[1]['messages'], 'id'));
        $this->assertSame('The first attempt failed; keep that fact.', $calls[1]['previous_summary']['memory']);
        $this->assertSame(end($ids), Summaries::get($conversation, 'en')['thread_id']);
        $this->assertFalse(Summaries::isStale($conversation, 'en'));
        ConversationSummarizer::assertPromptedTimes(2);
    }

    public function testHistoryHasSharedCharacterLimitsAndExcludesUnpublishedMessages()
    {
        [, , $conversation] = $this->conversation();
        $this->remember($conversation, $conversation->threads()->first()->id);
        $ids = [];
        foreach (range(1, 8) as $i) {
            $ids[] = $this->message($conversation, str_repeat('字', 4500))->id;
        }
        $draft = $this->message($conversation, 'Unsent secret');
        $draft->state = Thread::STATE_DRAFT;
        $draft->save();

        $recent = ConversationContext::history($conversation);
        $forward = ConversationContext::history($conversation, $ids[0] - 1);

        $this->assertSame(array_slice($ids, 2), array_column($recent['messages'], 'id'));
        $this->assertSame(array_slice($ids, 0, 6), array_column($forward['messages'], 'id'));
        $this->assertTrue($forward['incomplete']);
        $this->assertSame(24000, array_sum(array_map(fn ($message) => mb_strlen($message['body']), $recent['messages'])));
        $this->assertSame([true], array_values(array_unique(array_column($recent['messages'], 'truncated'))));
    }

    public function testHistoricalContextDoesNotIncludeFutureMessagesOrTheirSummary()
    {
        [, , $conversation] = $this->conversation();
        $first = $conversation->threads()->first();
        $target = $this->message($conversation, 'Please clarify.');
        $later = $this->message($conversation, 'A later correction.');
        $this->remember($conversation, $later->id, 'Future information.');

        $context = ConversationContext::build($conversation, 'en', false, $target->id);

        $this->assertNull($context['conversation']['previous_summary']);
        $this->assertSame([$first->id], array_column($context['conversation']['messages'], 'id'));
        $this->assertStringNotContainsString('Future information.', $context['prompt']);
    }

    public function testSummaryUsesFreshMemoryInAnotherLanguageAndNeverMovesBackwards()
    {
        [, , $conversation] = $this->conversation();
        $first = $conversation->threads()->first();
        $stale = $conversation->fresh();
        $this->remember($conversation, $first->id, 'Previously verified fact.', 'de');
        $next = $this->message($conversation, 'Next question.');
        $last = $this->message($conversation, 'Latest question.');
        ConversationSummarizer::fake(function ($prompt) use ($conversation) {
            $data = $this->data($prompt, 'conversation');
            $this->assertSame('Previously verified fact.', $data['previous_summary']['memory']);
            $future = $this->message($conversation, 'Arrived during the provider call.');
            $this->remember($conversation, $future->id, 'A newer worker already saved this.');

            return ['one_liner' => 'Old result', 'background' => '', 'memory' => 'Old memory'];
        });

        Summaries::summarize($stale, 'en');

        $this->assertSame('A newer worker already saved this.', Summaries::get($conversation->fresh(), 'en')['memory']);
        ConversationSummarizer::assertPrompted(fn ($prompt) => array_column($this->data($prompt->prompt, 'conversation')['messages'], 'id') === [$next->id, $last->id]);
    }

    public function testFailedSummaryKeepsMemoryAndReleasesItsLock()
    {
        [, , $conversation] = $this->conversation();
        $this->remember($conversation, $conversation->threads()->first()->id);
        $next = $this->message($conversation, 'A new question.');
        ConversationSummarizer::fake(function () {
            throw new \RuntimeException('Provider down');
        });
        try {
            Summaries::summarize($conversation, 'en');
            $this->fail('The provider failure should propagate.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Provider down', $e->getMessage());
        }
        $this->assertSame('An older troubleshooting attempt failed.', Summaries::get($conversation->fresh(), 'en')['memory']);

        ConversationSummarizer::fake([['one_liner' => 'Recovered', 'background' => '', 'memory' => 'New memory']]);
        Summaries::summarize($conversation, 'en');

        $this->assertSame($next->id, Summaries::get($conversation, 'en')['thread_id']);
        ConversationSummarizer::assertPromptedTimes(2);
    }

    public function testSummaryJobQueuesAnotherBoundedBatch()
    {
        [, , $conversation] = $this->conversation();
        foreach (range(1, 22) as $i) {
            $this->message($conversation, 'Message '.$i);
        }
        \Queue::fake([AiSummarizeConversation::class]);
        ConversationSummarizer::fake([['one_liner' => 'In progress', 'background' => '', 'memory' => 'First batch']]);

        (new AiSummarizeConversation($conversation->id, 'en'))->handle();

        $this->assertTrue(Summaries::isStale($conversation->fresh(), 'en'));
        \Queue::assertPushed(AiSummarizeConversation::class, fn ($job) => $job->conversation_id == $conversation->id && $job->language === 'en');
        ConversationSummarizer::assertPromptedTimes(1);
    }

    public function testInvalidSummaryCannotEraseMemoryOrAdvanceItsPosition()
    {
        [, , $conversation] = $this->conversation();
        $id = $conversation->threads()->first()->id;
        $this->remember($conversation, $id);
        $this->message($conversation, 'Another question.');

        foreach ([null, '', '   ', ['invalid']] as $memory) {
            ConversationSummarizer::fake([['one_liner' => 'Still checking.', 'background' => '', 'memory' => $memory]]);
            try {
                Summaries::summarize($conversation, 'en');
                $this->fail('Invalid memory must not be saved.');
            } catch (\RuntimeException $e) {
                $this->assertSame(__('The AI\'s answer could not be read.'), $e->getMessage());
            }
            $summary = Summaries::get($conversation->fresh(), 'en');
            $this->assertSame($id, $summary['thread_id']);
            $this->assertSame('An older troubleshooting attempt failed.', $summary['memory']);
        }
    }

    public function testAllConversationAgentsReceiveTheSharedSourcesAndMemory()
    {
        [$agent, $mailbox, $conversation] = $this->conversation();
        \Queue::fake([AiIndexDocument::class]);
        $this->article($mailbox, '<p>Android ad blocking is built into 12VPX NEO.</p>');
        $this->remember($conversation, $conversation->threads()->first()->id);
        Translations::store($conversation->threads()->first(), 'de', 'en', 'Erste Frage.');
        $this->message($conversation, 'Private staff observation.', Thread::TYPE_NOTE);
        $target = $this->message($conversation, 'How do I enable ad blocking on Android?');
        ChatTranslation::setCustomerLanguage($conversation->customer, 'nl', true);
        Option::set('aiassistant.customer_context_url', [$mailbox->id => 'https://crm.example.org/context']);
        Option::set('aiassistant.customer_context_guidance', [$mailbox->id => 'We support 12VPX NEO.']);
        Option::set('aiassistant.translation_glossary', [$mailbox->id => '12VPX NEO']);
        Http::fake(['https://crm.example.org/context' => Http::response(['plan' => 'Pro'])]);
        ReplyDrafter::fake([['draft' => 'Use NEO.', 'translation' => '', 'language' => 'en', 'confidence' => 'high', 'documentation_urls' => [], 'staff_notes' => []]]);
        ReplyTranslator::fake([['translation' => '<p>Gebruik NEO.</p>', 'same_language' => false, 'note' => '']]);
        ThreadTranslator::fake([['translation' => 'How do I enable it?', 'same_language' => false, 'detected_language' => 'nl']]);
        ChatTranslator::fake(fn () => ['messages' => [['id' => $target->id, 'translation' => 'How do I enable it?', 'same_language' => false]], 'detected_language' => 'nl']);
        ConversationSummarizer::fake([['one_liner' => 'Explaining NEO.', 'background' => '', 'memory' => 'Remember NEO.']]);

        Drafts::draft($conversation, 'en');
        ChatTranslation::translateReply($conversation, '<p>Use NEO.</p>', $agent);
        Translations::translate($target, 'en');
        ChatTranslation::translateIncoming($conversation, 'de');
        Summaries::summarize($conversation, 'en');

        foreach ([ReplyDrafter::class, ReplyTranslator::class, ThreadTranslator::class, ChatTranslator::class, ConversationSummarizer::class] as $class) {
            $class::assertPrompted(function ($prompt) {
                $data = $this->data($prompt->prompt, 'conversation');

                return ($data['previous_summary']['memory'] ?? '') === 'An older troubleshooting attempt failed.'
                    && str_contains($prompt->prompt, 'Private staff observation.')
                    && str_contains($prompt->prompt, 'internal_note')
                    && $this->data($prompt->prompt, 'mailbox_guidance') === 'We support 12VPX NEO.'
                    && $this->data($prompt->prompt, 'glossary') === '12VPX NEO'
                    && $this->data($prompt->prompt, 'customer_context') === ['plan' => 'Pro']
                    && str_contains($this->data($prompt->prompt, 'documentation')[0]['content'], 'Android ad blocking');
            });
        }
        Http::assertSentCount(5);
        \Queue::assertPushed(AiIndexDocument::class);
    }

    public function testIndexedKnowledgeBaseFeatureSurvivesASemanticMissAndTheOldExcerptCutoff()
    {
        [, $mailbox, $conversation] = $this->conversation();
        \Queue::fake([AiIndexDocument::class]);
        $article = $this->article($mailbox, '<p>'.str_repeat('General product description. ', 80).'</p><p>12VPX NEO on Android includes ad blocking. Enable Ad Blocking in the app settings.</p>');
        $document = Document::where('source_identifier', KbArticle::documentIdentifier($article->id))->first();
        Embeddings::fake(fn ($prompt) => array_fill(0, count($prompt->inputs), [0.0, 1.0]));
        Documents::index($document);
        Embeddings::fake(fn ($prompt) => array_fill(0, count($prompt->inputs), [1.0, 0.0]));
        ReplyDrafter::fake([['draft' => 'Use the documented setting.', 'translation' => '', 'language' => 'en', 'confidence' => 'high', 'documentation_urls' => [], 'staff_notes' => []]]);

        $draft = Drafts::draft($conversation, 'en');

        $this->assertSame('available', $draft['documentation_status']);
        $this->assertSame('12VPX NEO for Android', $draft['retrieved_documents'][0]['title']);
        ReplyDrafter::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, 'includes ad blocking. Enable Ad Blocking in the app settings.'));
        $this->assertSame('', $draft['retrieved_documents'][0]['url']);
        Embeddings::assertGenerated(fn ($prompt) => str_contains($prompt->inputs[0], 'ad-blocker'));
        \Queue::assertPushed(AiIndexDocument::class);
    }

    public function testCurrentKnowledgeBaseIsAvailableWithoutEmbeddingsAndIsMailboxScoped()
    {
        [, $mailbox, $conversation] = $this->conversation();
        \Queue::fake([AiIndexDocument::class]);
        $article = $this->article($mailbox, '<p>Android ad blocking is included.</p>');
        $this->article($this->createMailbox(), '<p>Android: private unrelated instructions.</p>');
        Option::set('aiassistant.providers', [['id' => 'p1', 'provider' => 'anthropic', 'api_key' => encrypt('sk-test'), 'base_url' => '']]);
        Option::$cache = [];

        $context = ConversationContext::build($conversation, 'en');

        $this->assertSame('available', $context['documentation']['status']);
        $this->assertStringContainsString('Android ad blocking is included.', $context['prompt']);
        $this->assertStringNotContainsString('private unrelated instructions', $context['prompt']);
        $this->assertSame(Document::STATUS_PENDING, Document::where('source_identifier', KbArticle::documentIdentifier($article->id))->value('status'));
        \Queue::assertPushed(AiIndexDocument::class);
    }

    public function testOversizedDocumentationKeepsTheRelevantPassageWithinItsBudget()
    {
        $body = str_repeat('Unrelated information. ', 500)."\n\nAndroid ad blocking: enable it in NEO settings.";

        $excerpt = Documents::excerpt($body, 'how to use ad-blocker on android', 1800);

        $this->assertLessThanOrEqual(1800, mb_strlen($excerpt));
        $this->assertStringContainsString('Android ad blocking: enable it in NEO settings.', $excerpt);
    }

    public function testKnowledgeBaseRanksRelevanceBeforeLimitingCandidates()
    {
        [, $mailbox] = $this->conversation();
        \Queue::fake([AiIndexDocument::class]);
        $this->article($mailbox, '<p>Android ad blocking: enable it in NEO settings.</p>');
        foreach (range(1, 21) as $i) {
            $this->article($mailbox, '<p>Unrelated Android setup information '.$i.'.</p>');
        }

        $results = Documents::searchKnowledgeBase($mailbox->id, 'how to use ad-blocker on android', 5);

        $this->assertStringContainsString('Android ad blocking: enable it in NEO settings.', $results[0]['content']);
        $this->assertCount(5, $results);
        \Queue::assertPushed(AiIndexDocument::class);
    }

    public function testKeywordMatchesLeaveRoomForSemanticDocumentation()
    {
        [, $mailbox, $conversation] = $this->conversation();
        \Queue::fake([AiIndexDocument::class]);
        foreach (range(1, 6) as $i) {
            $this->article($mailbox, '<p>Android setup information '.$i.'.</p>');
        }
        $document = new Document(['mailbox_id' => $mailbox->id, 'source_identifier' => 'neo-ad-blocking']);
        $document->source_type = Document::SOURCE_TYPE_URL;
        $document->source_url = 'https://docs.example.org/android';
        $document->title = 'NEO ad blocking';
        $document->enabled = true;
        $document->setContent('Enable Ad Blocking in NEO settings.');
        $document->save();
        Embeddings::fake(fn ($prompt) => array_fill(0, count($prompt->inputs), [1.0, 0.0]));
        Documents::index($document);

        $context = ConversationContext::build($conversation, 'en');

        $this->assertContains('NEO ad blocking', array_column($context['documentation']['chunks'], 'title'));
        $this->assertStringContainsString('Android setup information', $context['prompt']);
        \Queue::assertPushed(AiIndexDocument::class);
    }
}
