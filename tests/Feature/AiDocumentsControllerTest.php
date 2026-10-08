<?php

namespace Tests\Feature;

use App\Ai\Document;
use App\Ai\DocumentApiKey;
use App\Jobs\AiIndexDocument;
use App\Option;
use Illuminate\Support\Facades\Bus;
use Laravel\Ai\Embeddings;
use Tests\FeatureTestCase;

/**
 * AiDocumentsController: indexing one document again, and the documentation
 * API's checks, localized URLs and answers when indexing is impossible or
 * fails.
 */
class AiDocumentsControllerTest extends FeatureTestCase
{
    protected $admin;
    protected $mailbox;
    protected $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createAdmin();
        $this->mailbox = $this->createMailbox();
        Option::$cache = [];
        Option::set('aiassistant.api_key', encrypt('sk-test'));
        Embeddings::fake(function ($prompt) {
            return array_map(function ($text) {
                return [1.0, 0.0, 0.1];
            }, $prompt->inputs);
        });
        $this->token = DocumentApiKey::issue($this->mailbox->id);
    }

    protected function api(array $data)
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$this->token])->postJson('/ai-assistant/api/documents', $data);
    }

    protected function document($title)
    {
        $document = new Document();
        $document->mailbox_id = $this->mailbox->id;
        $document->source_identifier = hash('sha256', $title);
        $document->source_type = Document::SOURCE_TYPE_URL;
        $document->source_url = 'https://93.184.215.14/'.$title;
        $document->title = $title;
        $document->status = Document::STATUS_PENDING;
        $document->enabled = true;
        $document->save();

        return $document;
    }

    public function testIndexOneDocumentAgain()
    {
        $this->document('first');
        $second = $this->document('second');
        Bus::fake([AiIndexDocument::class]);

        \Session::start();
        $this->actingAs($this->admin)->post('/ai-assistant/documents', [
            '_token' => csrf_token(), 'action' => 'index', 'document_id' => $second->id, 'force' => 1,
        ])->assertRedirect(route('ai.documents'));

        Bus::assertDispatchedTimes(AiIndexDocument::class, 1);
        Bus::assertDispatched(AiIndexDocument::class, function ($job) use ($second) {
            return $job->document_id == $second->id && $job->fetch && $job->force;
        });
    }

    public function testApiChecksEachField()
    {
        $valid = ['identifier' => 'faq', 'content' => 'Answers.'];

        $this->api(array_merge($valid, ['content' => str_repeat('a', 1000001)]))
            ->assertStatus(422)
            ->assertJsonPath('errors.content.0', 'Content must be 1000000 bytes or fewer.');
        $this->api(array_merge($valid, ['title' => str_repeat('t', 192), 'canonical_locale' => 'en-GB-oxendict']))
            ->assertStatus(422)
            ->assertJsonPath('errors.title.0', 'Title must be a string of 191 characters or fewer.')
            ->assertJsonPath('errors.canonical_locale.0', 'Canonical locale must be one of: en, ja, zh, ko.');
        $this->api(array_merge($valid, ['canonical_locale' => 'de', 'enabled' => 'maybe']))
            ->assertStatus(422)
            ->assertJsonPath('errors.canonical_locale.0', 'Canonical locale must be one of: en, ja, zh, ko.')
            ->assertJsonPath('errors.enabled.0', 'Enabled must be true or false.');
        $this->api(array_merge($valid, ['localized_urls' => 'https://93.184.215.14/ja']))
            ->assertStatus(422)
            ->assertJsonPath('errors.localized_urls.0', 'Localized URLs must be an object keyed by locale.');
        $this->api(array_merge($valid, ['localized_urls' => ['de' => 'https://93.184.215.14/de', 'ja' => 'ftp://93.184.215.14/ja']]))
            ->assertStatus(422)
            ->assertJsonPath('errors', [
                'localized_urls.de' => ['Locale must be one of: en, ja, zh, ko.'],
                'localized_urls.ja' => ['Localized URL must be a valid http or https URL of 2048 characters or fewer.'],
            ]);

        $this->assertSame(0, Document::count());
    }

    public function testApiRefusesANonStringLocale()
    {
        $this->api(['identifier' => 'faq', 'content' => 'Answers.', 'canonical_locale' => ['en']])
            ->assertStatus(422)
            ->assertJsonPath('errors.canonical_locale.0', 'Canonical locale must be a string of 10 characters or fewer.');
        $this->assertSame(0, Document::count());
    }

    public function testApiKeepsLocalizedUrls()
    {
        $this->api([
            'identifier'     => 'setup',
            'content'        => '# Setup',
            'public_url'     => 'https://93.184.215.14/en/setup',
            'localized_urls' => ['ja' => 'https://93.184.215.14/ja/setup', 'zh' => '', 'ko' => null],
        ])->assertStatus(201);

        $document = Document::first();
        $this->assertSame(['ja' => 'https://93.184.215.14/ja/setup'], $document->localized_urls);
        $this->assertSame('https://93.184.215.14/ja/setup', $document->localizedUrl('ja'));
        $this->assertSame('https://93.184.215.14/en/setup', $document->localizedUrl('ko'));
    }

    public function testApiSavesWhenTheProviderHasNoEmbeddings()
    {
        Option::set('aiassistant.documentation.embedding_provider', 'anthropic');
        Option::$cache = [];

        $this->api(['identifier' => 'faq', 'content' => '# FAQ'])
            ->assertStatus(202)
            ->assertJson([
                'status'   => 'success',
                'message'  => 'Documentation created.',
                'indexing' => ['status' => 'skipped', 'message' => 'The embedding provider does not support embeddings'],
            ]);

        $this->assertSame(Document::STATUS_PENDING, Document::first()->status);
    }

    public function testApiReportsFailedIndexing()
    {
        Embeddings::fake(function () {
            throw new \RuntimeException('Embedding service is down');
        });

        $response = $this->api(['identifier' => 'faq', 'content' => '# FAQ']);

        $response->assertStatus(500)->assertJson([
            'status'   => 'error',
            'message'  => 'Documentation was saved, but indexing failed.',
            'document' => ['identifier' => 'faq', 'title' => 'FAQ'],
            'error'    => ['type' => 'indexing_failed'],
        ]);
        $this->assertMatchesRegularExpression('/^Error occurred \(ID: [A-F0-9]{12}\)$/', $response->json('error.detail'));
        $this->assertSame($response->json('error.detail'), Document::first()->last_error);
        $this->assertStringNotContainsString('Embedding service is down', $response->getContent());
    }
}
