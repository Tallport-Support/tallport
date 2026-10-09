<?php

namespace Tests\Feature;

use App\Ai\Document;
use App\Ai\DocumentApiKey;
use App\Ai\Documents;
use App\Option;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Embeddings;
use Tests\FeatureTestCase;

/**
 * AI Assistant documentation: adding pages, indexing them with embeddings
 * (faked), searching them, and the documentation API.
 */
class AiDocumentsTest extends FeatureTestCase
{
    protected $admin;
    protected $mailbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createAdmin();
        $this->mailbox = $this->createMailbox();
        Option::$cache = [];
        Option::set('aiassistant.api_key', encrypt('sk-test'));

        // Vectors by topic: [android, invoices, other].
        Embeddings::fake(function ($prompt) {
            return array_map(function ($text) {
                $text = strtolower($text);

                return [str_contains($text, 'android') ? 1.0 : 0.0, str_contains($text, 'invoice') ? 1.0 : 0.0, 0.1];
            }, $prompt->inputs);
        });
    }

    protected function postForm($user, $uri, array $data)
    {
        \Session::start();

        return $this->actingAs($user)->post($uri, array_merge(['_token' => csrf_token()], $data));
    }

    protected function fakePages(array $pages)
    {
        // A fresh fake: earlier ones would answer first.
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(array_merge($pages, ['*' => Http::response('Not found', 404)]));
    }

    protected function addDocument($markdown = "# Android setup\n\nOpen the app on Android.")
    {
        $this->fakePages(['https://93.184.215.14/en/android.md' => Http::response($markdown)]);
        $this->postForm($this->admin, '/ai-assistant/documents', [
            'action' => 'add', 'mailbox_id' => $this->mailbox->id, 'urls' => "https://93.184.215.14/en/android\n",
        ])->assertRedirect(route('ai.documents'));

        return Document::where('mailbox_id', $this->mailbox->id)->first();
    }

    protected function api($token, array $data)
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$token])->postJson('/ai-assistant/api/documents', $data);
    }

    // Chunks and similarity.

    public function testChunksPackParagraphsWithOverlap()
    {
        $paragraph = str_repeat('word ', 80);
        $chunks = Documents::chunks("---\ntitle: X\n---\n".$paragraph."\n\n".$paragraph."\n\n".$paragraph, 500, 50);

        $this->assertCount(3, $chunks);
        $this->assertStringNotContainsString('title:', $chunks[0]);
        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(500 + 50 + 2, mb_strlen($chunk));
        }

        // A paragraph longer than a chunk is cut.
        $this->assertCount(3, Documents::chunks(str_repeat('x', 1200), 500, 0));
        $this->assertSame([], Documents::chunks("---\ntitle: Empty\n---\n", 500, 0));
    }

    public function testSimilarity()
    {
        $this->assertEqualsWithDelta(1.0, Documents::similarity([1, 2], [2, 4]), 0.0001);
        $this->assertSame(0.0, Documents::similarity([1, 0], [0, 1]));
        $this->assertSame(0.0, Documents::similarity([1, 0], [1, 0, 0]));
    }

    public function testExpiredEmbeddingDeadlineDoesNotStartAnotherBatch()
    {
        $calls = 0;
        Embeddings::fake(function () use (&$calls) {
            $calls++;

            return [[1.0, 0.0, 0.1]];
        });

        try {
            Documents::embed(['Android'], microtime(true) - 1);
            $this->fail('The expired deadline should stop before the embedding call.');
        } catch (\RuntimeException $e) {
            $this->assertSame('AI document deadline exceeded', $e->getMessage());
        }
        $this->assertSame(0, $calls);
    }

    public function testTitleFromFrontMatterOrHeading()
    {
        $this->assertSame('Setup', Documents::title("---\ntitle: \"Setup\"\n---\n# Other"));
        $this->assertSame('Other', Documents::title("Intro\n\n# Other\n"));
    }

    // Pages.

    public function testPageRendersForAdminsOnly()
    {
        $this->actingAs($this->admin)->get('/ai-assistant/documents')->assertStatus(200)->assertSee('Documentation API');
        $this->actingAs($this->createUser())->get('/ai-assistant/documents')->assertStatus(403);
        $this->actingAs($this->admin)->get('/app-settings/ai')->assertSee(route('ai.documents'));
    }

    public function testAddedPageIsFetchedAndIndexed()
    {
        $document = $this->addDocument("---\ntitle: Android setup\n---\nOpen the app on Android.");

        $this->assertSame(Document::STATUS_INDEXED, $document->status);
        $this->assertSame('Android setup', $document->title);
        $this->assertSame('https://93.184.215.14/ja/android', $document->localizedUrl('ja'));
        $this->assertSame(1, $document->chunks()->count());
        $this->assertEquals([1, 0, 0.1], $document->chunks()->first()->embedding);

        $this->actingAs($this->admin)->get('/ai-assistant/documents')->assertSee('Android setup');
    }

    public function testOnlyHttpUrlsCanBeAdded()
    {
        $this->postForm($this->admin, '/ai-assistant/documents', [
            'action' => 'add', 'mailbox_id' => $this->mailbox->id, 'urls' => "javascript:alert(1)\nfile:///etc/passwd",
        ])->assertSessionHasErrors('urls');

        $this->assertSame(0, Document::count());
    }

    public function testFailedFetchIsShown()
    {
        $this->fakePages([]);
        $this->postForm($this->admin, '/ai-assistant/documents', [
            'action' => 'add', 'mailbox_id' => $this->mailbox->id, 'urls' => 'https://93.184.215.14/en/missing',
        ]);

        $document = Document::first();
        $this->assertSame(Document::STATUS_FAILED, $document->status);
        $this->assertMatchesRegularExpression('/^Error occurred \(ID: [A-F0-9]{12}\)$/', $document->last_error);
        $this->actingAs($this->admin)->get('/ai-assistant/documents')->assertSee($document->last_error)->assertDontSee('HTTP 404');
    }

    public function testDisableIndexAndDelete()
    {
        $document = $this->addDocument();

        $this->postForm($this->admin, '/ai-assistant/documents', ['action' => 'toggle', 'document_id' => $document->id]);
        $this->assertFalse($document->fresh()->enabled);
        $this->postForm($this->admin, '/ai-assistant/documents', ['action' => 'toggle', 'document_id' => $document->id]);
        $this->assertTrue($document->fresh()->enabled);

        // Changed page: indexed again.
        $this->fakePages(['https://93.184.215.14/en/android.md' => Http::response("# Android\n\nInvoices on Android.")]);
        $this->postForm($this->admin, '/ai-assistant/documents', ['action' => 'index'])->assertRedirect(route('ai.documents'));
        $this->assertEquals([1, 1, 0.1], $document->chunks()->first()->embedding);

        $this->postForm($this->admin, '/ai-assistant/documents', ['action' => 'delete', 'document_id' => $document->id]);
        $this->assertNull(Document::find($document->id));
        $this->assertSame(0, \App\Ai\DocumentChunk::count());

        $this->postForm($this->admin, '/ai-assistant/documents', ['action' => 'nonsense'])->assertStatus(404);
    }

    public function testSearchFindsTheMailboxesBestChunks()
    {
        $this->addDocument();
        $other = $this->createMailbox();
        $other_document = new Document();
        $other_document->forceFill(['mailbox_id' => $other->id, 'title' => 'Other', 'source_url' => 'api://other', 'source_type' => 'api', 'content' => 'Android elsewhere'])->save();
        Documents::index($other_document);

        $results = Documents::search($this->mailbox->id, 'My Android phone', 'ja');
        $this->assertCount(1, $results);
        $this->assertSame('https://93.184.215.14/ja/android', $results[0]['url']);

        // Not similar enough.
        $this->assertSame([], Documents::search($this->mailbox->id, 'An invoice question'));
    }

    // Documentation API.

    public function testApiKeys()
    {
        $this->postForm($this->admin, '/ai-assistant/documents', ['action' => 'issue_key', 'mailbox_id' => $this->mailbox->id])
            ->assertSessionHas('ai_new_key');
        $token = session('ai_new_key')['key'];
        $this->assertStringStartsWith('fsai_', $token);
        $this->assertSame($this->mailbox->id, DocumentApiKey::findByToken($token)->mailbox_id);
        $this->assertNotSame($token, DocumentApiKey::first()->key_hash);

        $this->postForm($this->admin, '/ai-assistant/documents', ['action' => 'revoke_key', 'mailbox_id' => $this->mailbox->id]);
        $this->assertNull(DocumentApiKey::findByToken($token));
    }

    public function testApiPushesAndIndexesDocuments()
    {
        $token = DocumentApiKey::issue($this->mailbox->id);

        $this->postJson('/ai-assistant/api/documents', [])->assertStatus(401);
        $this->api('fsai_wrong', ['identifier' => 'x', 'content' => 'y'])->assertStatus(401);
        $this->api($token, ['identifier' => '', 'public_url' => 'javascript:alert(1)'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['identifier', 'content', 'public_url'], 'errors');

        $response = $this->api($token, [
            'identifier' => 'setup/android',
            'content'    => "# Android setup\n\nOpen the app on Android.",
            'public_url' => 'https://93.184.215.14/en/setup/android',
        ]);
        $response->assertStatus(201)->assertJson([
            'status'   => 'success',
            'document' => ['title' => 'Android setup', 'identifier' => 'setup/android', 'status' => 'indexed', 'chunks_count' => 1],
            'indexing' => ['status' => 'indexed'],
        ]);

        // The same again: unchanged.
        $this->api($token, ['identifier' => 'setup/android', 'content' => "# Android setup\n\nOpen the app on Android.", 'public_url' => 'https://93.184.215.14/en/setup/android'])
            ->assertStatus(200)
            ->assertJson(['indexing' => ['status' => 'skipped']]);

        // Without a public URL: private.
        $this->api($token, ['identifier' => 'internal notes', 'content' => 'Invoices are sent monthly.'])
            ->assertStatus(201)
            ->assertJson(['document' => ['source_url' => null]]);
        $this->assertSame('', Document::where('title', 'internal notes')->first()->localizedUrl('en'));
    }

    public function testFailedApiIndexingKeepsOnlyAReferenceInTheResponseAndDocument()
    {
        $token = DocumentApiKey::issue($this->mailbox->id);
        $key = 'embedding-secret-key-123';
        Option::set('aiassistant.documentation.embedding_api_key', encrypt($key));
        Option::$cache = [];
        \Log::spy();
        Embeddings::fake(function () use ($key, $token) {
            throw new \RuntimeException('Provider failed: '.$key.' Authorization: Bearer '.$token);
        });

        $response = $this->api($token, ['identifier' => 'setup/android', 'content' => 'Restart the Android app.']);

        $response->assertStatus(500)->assertJson(['status' => 'error', 'error' => ['type' => 'indexing_failed']]);
        $detail = $response->json('error.detail');
        $this->assertMatchesRegularExpression('/^Error occurred \(ID: [A-F0-9]{12}\)$/', $detail);
        $this->assertSame($detail, Document::where('source_identifier', Document::apiSourceIdentifier('setup/android'))->value('last_error'));
        $this->assertStringNotContainsString($key, $response->getContent());
        $this->assertStringNotContainsString($token, $response->getContent());
        $reference = substr($detail, -13, 12);
        \Log::shouldHaveReceived('error')->withArgs(fn ($message) => str_contains($message, '['.$reference.']') && str_contains($message, 'Provider failed: [redacted] Authorization: Bearer [redacted]') && !str_contains($message, $key) && !str_contains($message, $token))->once();
    }

    // Command.

    public function testCommandFetchesChangedPagesAgain()
    {
        $document = $this->addDocument();

        $this->fakePages(['https://93.184.215.14/en/android.md' => Http::response("# Android\n\nInvoices on Android.")]);
        $this->artisan('tallport:ai-index-documents', ['--fetch' => true])
            ->expectsOutputToContain('#'.$document->id.' Android: 1 chunks')
            ->assertExitCode(0);
        $this->assertEquals([1, 1, 0.1], $document->chunks()->first()->embedding);

        // Unchanged: nothing to do.
        $this->artisan('tallport:ai-index-documents', ['--fetch' => true])->doesntExpectOutputToContain('chunks')->assertExitCode(0);

        $this->fakePages([]);
        $this->artisan('tallport:ai-index-documents', ['--fetch' => true, '--document' => $document->id])->assertExitCode(1);
        $this->assertSame(Document::STATUS_FAILED, $document->fresh()->status);

        Option::set('aiassistant.provider', 'anthropic');
        $this->artisan('tallport:ai-index-documents')->expectsOutputToContain('nothing to index')->assertExitCode(0);
    }

    // Fetching and indexing, when it doesn't work out.

    /**
     * Only public pages are fetched, also after a redirect; a page too large or
     * empty is refused; without a title the page's address names it.
     */
    public function testFetchingPages()
    {
        $error = function ($url) {
            try {
                Documents::fetch($url);
            } catch (\Exception $e) {
                return $e->getMessage();
            }

            return null;
        };

        $this->assertSame('Only public http and https URLs can be fetched.', $error('http://127.0.0.1/en/admin'));
        $this->fakePages(['https://93.184.215.14/en/moved.md' => Http::response('', 302, ['Location' => 'http://127.0.0.1/en/secret.md'])]);
        $this->assertSame('Only public http and https URLs can be fetched.', $error('https://93.184.215.14/en/moved'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '127.0.0.1'));

        $this->fakePages([
            'https://93.184.215.14/en/huge.md'  => Http::response(str_repeat('x', Documents::MAX_DOCUMENT_BYTES + 1)),
            'https://93.184.215.14/en/empty.md' => Http::response(" \n"),
            'https://93.184.215.14/en/getting-started_guide.md' => Http::response('Just text.'),
        ]);
        $this->assertSame('Unable to fetch Markdown: larger than '.Documents::MAX_DOCUMENT_BYTES.' bytes', $error('https://93.184.215.14/en/huge'));
        $this->assertSame('Unable to fetch Markdown: empty response', $error('https://93.184.215.14/en/empty'));
        $this->assertSame('Getting Started Guide', Documents::fetch('https://93.184.215.14/en/getting-started_guide')['title']);
    }

    public function testIndexingFailuresAreKept()
    {
        $document = new Document();
        $document->forceFill(['mailbox_id' => $this->mailbox->id, 'title' => 'Empty', 'source_url' => 'api://empty', 'source_type' => 'api', 'content' => "---\ntitle: Empty\n---\n"])->save();
        try {
            Documents::index($document);
            $this->fail('Indexed nothing.');
        } catch (\Exception $e) {
            $this->assertSame('Document has no indexable content', $e->getMessage());
        }
        $this->assertSame(Document::STATUS_FAILED, $document->fresh()->status);
        $this->assertMatchesRegularExpression('/^Error occurred \(ID: [A-F0-9]{12}\)$/', $document->fresh()->last_error);

        // The provider answering with too few embeddings.
        Embeddings::fake(fn ($prompt) => [[1.0, 0.0, 0.1]]);
        $this->expectExceptionMessage('Embeddings count does not match chunk count');
        Documents::embed(['One', 'Two']);
    }

    public function testTerminalIndexingFailureDoesNotOverwriteAnIndexedDocument()
    {
        $document = new Document();
        $document->forceFill(['mailbox_id' => $this->mailbox->id, 'title' => 'Guide', 'source_url' => 'api://guide', 'source_type' => 'api', 'content' => 'Guide.'])->save();
        $job = new \App\Jobs\AiIndexDocument($document->id);

        $job->failed(new \RuntimeException('Worker timed out'));
        $this->assertSame(Document::STATUS_FAILED, $document->fresh()->status);
        $this->assertMatchesRegularExpression('/^Error occurred \(ID: [A-F0-9]{12}\)$/', $document->fresh()->last_error);

        $document->status = Document::STATUS_INDEXED;
        $document->last_error = null;
        $document->save();
        $job->failed(new \RuntimeException('A late failure'));
        $this->assertSame(Document::STATUS_INDEXED, $document->fresh()->status);
        $this->assertNull($document->fresh()->last_error);
    }

    public function testEmbeddingsNeedAProviderThatMakesThem()
    {
        Option::set('aiassistant.providers', [['id' => 'p1', 'provider' => 'anthropic', 'api_key' => encrypt('sk-ant'), 'base_url' => '']]);
        Option::set('aiassistant.embedding_provider', 'p1');
        Option::$cache = [];

        $this->assertFalse(Documents::available());
        $this->expectExceptionMessage('The embedding provider does not support embeddings');
        Documents::embed(['Android']);
    }

    /**
     * Paragraphs of only spaces are skipped; a long paragraph closes the chunk before it.
     */
    public function testChunksAroundLongParagraphs()
    {
        $chunks = Documents::chunks("Intro.\n\n   \n\n".str_repeat('y', 600)."\n\nOutro.", 500, 0);

        $this->assertSame(['Intro.', str_repeat('y', 500), str_repeat('y', 100), 'Outro.'], $chunks);
    }

    public function testSearchOrdersByScoreAndNeedsAQuestion()
    {
        $this->addDocument();
        $second = new Document();
        $second->forceFill(['mailbox_id' => $this->mailbox->id, 'title' => 'Android invoices', 'source_url' => 'api://invoices', 'source_type' => 'api', 'content' => 'Invoices in the Android app.'])->save();
        Documents::index($second);

        $this->assertSame(['Android invoices', 'Android setup'], array_column(Documents::search($this->mailbox->id, 'Android invoice'), 'title'));
        $this->assertSame(['Android setup', 'Android invoices'], array_column(Documents::search($this->mailbox->id, 'Android'), 'title'));
        $this->assertSame([], Documents::search($this->mailbox->id, '  '));
    }
}
