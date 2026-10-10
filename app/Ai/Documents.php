<?php

namespace App\Ai;

use Illuminate\Support\Facades\Http;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Psr7\UriResolver;
use GuzzleHttp\Psr7\Utils;
use Laravel\Ai\Embeddings;

/**
 * Documentation for drafting replies: fetching, chunking, embedding
 * (through laravel/ai) and searching it. Embeddings are stored as JSON and
 * compared in PHP, which is fast enough for a mailbox's documentation.
 */
class Documents
{
    /**
     * Chunks embedded per request.
     */
    const EMBEDDING_BATCH = 50;

    /**
     * Chunks less similar than this to a question are left out.
     */
    const MIN_SIMILARITY = 0.2;

    const MAX_DOCUMENT_BYTES = 5000000;

    /**
     * Whether documents can be indexed and searched.
     */
    public static function available()
    {
        return Settings::embeddingsAvailable() && Settings::embeddingModel() !== '';
    }

    /**
     * A page's Markdown (its URL plus .md): content, title and hash.
     */
    public static function fetch($url, ?float $deadline = null)
    {
        $markdown_url = Document::markdownUrlFor($url);
        $url = $markdown_url;
        for ($redirects = 0; $redirects <= 5; $redirects++) {
            $connection = OutboundHttp::connectionOptions($url);
            $remaining = $deadline === null ? 30 : (int) floor($deadline - microtime(true));
            if ($remaining < 1) {
                throw new \RuntimeException('AI document deadline exceeded');
            }
            $limited = OutboundHttp::limitedResponseOptions(self::MAX_DOCUMENT_BYTES, $too_large);
            try {
                $response = Http::withOptions(\Helper::setGuzzleDefaultOptions(array_merge([
                    'timeout'         => min(30, $remaining),
                    'connect_timeout' => min(10, $remaining),
                    'allow_redirects' => false,
                ], $connection, $limited)))->setHandler(new CurlHandler())
                    ->withUserAgent('Tallport-AI-Assistant')->withHeaders(['Accept-Encoding' => 'identity'])->get($url);
            } catch (\Throwable $e) {
                if ($too_large) {
                    throw new \Exception('Unable to fetch Markdown: larger than '.self::MAX_DOCUMENT_BYTES.' bytes', 0, $e);
                }
                throw $e;
            }

            if (!in_array($response->status(), [301, 302, 303, 307, 308]) || !$response->header('Location')) {
                break;
            }
            if ($redirects === 5) {
                throw new \RuntimeException('Too many document redirects');
            }
            $url = (string) UriResolver::resolve(Utils::uriFor($url), Utils::uriFor($response->header('Location')));
        }

        if (!$response->successful()) {
            throw new \Exception('Unable to fetch Markdown: HTTP '.$response->status());
        }
        $content = $response->body();
        if (strlen($content) > self::MAX_DOCUMENT_BYTES) {
            throw new \Exception('Unable to fetch Markdown: larger than '.self::MAX_DOCUMENT_BYTES.' bytes');
        }
        if (trim($content) === '') {
            throw new \Exception('Unable to fetch Markdown: empty response');
        }

        return [
            'content' => $content,
            'title'   => self::title($content) ?: self::titleFromUrl($url),
            'hash'    => hash('sha256', $content),
        ];
    }

    /**
     * The title from the front matter, else the first heading.
     */
    public static function title($markdown)
    {
        if (preg_match('/\A---\s*\R(.*?)\R---\s*/s', $markdown, $m)
            && preg_match('/^title:\s*(.+?)\s*$/mi', $m[1], $title)
        ) {
            return trim($title[1], " \t\n\r\0\x0B\"'");
        }
        if (preg_match('/^\s*#\s+(.+?)\s*$/m', $markdown, $m)) {
            return trim($m[1]);
        }

        return '';
    }

    protected static function titleFromUrl($url)
    {
        $basename = basename((string) parse_url(Document::normalizeSourceUrl($url), PHP_URL_PATH));

        return $basename ? ucwords(str_replace(['-', '_'], ' ', $basename)) : 'Documentation';
    }

    /**
     * Fetch a URL document again: a changed one is to be indexed again.
     */
    public static function refetch(Document $document, ?float $deadline = null)
    {
        $generation = $document->content_generation;
        $source_url = $document->source_url;
        $markdown = self::fetch($document->source_url, $deadline);
        \DB::transaction(function () use ($document, $generation, $source_url, $markdown) {
            $current = Document::whereKey($document->id)->lockForUpdate()->first();
            if (!$current || !$current->enabled || $current->content_generation !== $generation || $current->source_url !== $source_url) {
                return;
            }
            if ($current->setContent($markdown['content'])) {
                $current->title = mb_substr($markdown['title'], 0, 191);
                $current->save();
            }
        });
        $document->refresh();
    }

    /**
     * The provider, endpoint, model, dimensions and chunking used to make vectors.
     */
    public static function embeddingFingerprint()
    {
        $provider = Settings::embeddingProvider();

        return hash('sha256', json_encode([
            'provider' => $provider,
            'endpoint' => Settings::embeddingBaseUrl() ?: Providers::PRESETS[$provider]['base_url'],
            'model' => Settings::embeddingModel(),
            'dimensions' => config('ai.providers.'.Providers::EMBEDDINGS.'.models.embeddings.dimensions'),
            'chunk_size' => Settings::chunkSize(),
            'chunk_overlap' => Settings::chunkOverlap(),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * Whether a document has to be (re)indexed.
     */
    public static function needsIndexing(Document $document)
    {
        return $document->status !== Document::STATUS_INDEXED
            || $document->embedding_fingerprint !== self::embeddingFingerprint()
            || !$document->chunks()->where('embedding_model', Settings::embeddingModel())->exists();
    }

    /**
     * Queue existing documents whose vectors belong to an older embedding space.
     */
    public static function queueStale()
    {
        if (!self::available()) {
            return;
        }

        $fingerprint = self::embeddingFingerprint();
        Document::where('enabled', true)->where(function ($query) use ($fingerprint) {
            $query->whereNull('embedding_fingerprint')->orWhere('embedding_fingerprint', '!=', $fingerprint);
        })->chunkById(100, function ($documents) {
            foreach ($documents as $document) {
                $document->status = Document::STATUS_PENDING;
                $document->last_error = null;
                $document->save();
                \App\Jobs\AiIndexDocument::dispatch($document->id);
            }
        });
    }

    /**
     * Chunk and embed a document's content. Returns the number of chunks.
     */
    public static function index(Document $document, $force = false, ?float $deadline = null)
    {
        $deadline = $deadline ?? microtime(true) + 180;
        $document = $document->fresh();
        if (!$document || !$document->enabled) {
            return null;
        }
        if (!$force && !self::needsIndexing($document)) {
            return $document->chunks()->count();
        }

        $generation = $document->content_generation;
        $content_hash = $document->content_hash;
        $fingerprint = self::embeddingFingerprint();
        try {
            $chunks = self::chunks((string) $document->content, Settings::chunkSize(), Settings::chunkOverlap());
            if (!$chunks) {
                throw new \Exception('Document has no indexable content');
            }
            $embeddings = self::embed($chunks, $deadline, $document->mailbox_id);
            $model = Settings::embeddingModel();

            // Workers cache options between requests; see the latest settings before commit.
            \Option::$cache = [];
            $committed = \DB::transaction(function () use ($document, $generation, $content_hash, $fingerprint, $chunks, $embeddings, $model) {
                $current = Document::whereKey($document->id)->lockForUpdate()->first();
                if (!$current || !$current->enabled || $current->content_generation !== $generation
                    || $current->content_hash !== $content_hash || self::embeddingFingerprint() !== $fingerprint) {
                    return false;
                }

                $current->chunks()->delete();
                foreach ($chunks as $i => $chunk) {
                    $current->chunks()->create([
                        'chunk_index'     => $i,
                        'content'         => $chunk,
                        'content_hash'    => hash('sha256', $chunk),
                        'token_count'     => (int) ceil(mb_strlen($chunk) / 4),
                        'embedding'       => $embeddings[$i],
                        'embedding_model' => $model,
                    ]);
                }
                $current->status = Document::STATUS_INDEXED;
                $current->embedding_fingerprint = $fingerprint;
                $current->last_indexed_at = now();
                $current->last_error = null;
                $current->save();

                return true;
            });
        } catch (\Throwable $e) {
            self::failIfCurrent($document->id, $generation, $fingerprint, $e);

            throw $e;
        }

        if (!$committed) {
            self::queueCurrent($document->id);

            return null;
        }

        return count($chunks);
    }

    /**
     * A late error must not replace a newer document's indexing state.
     */
    public static function failIfCurrent($document_id, $generation, $fingerprint, \Throwable $error, $pending_only = false)
    {
        \Option::$cache = [];
        $current = \DB::transaction(function () use ($document_id, $generation, $fingerprint, $error, $pending_only) {
            $document = Document::whereKey($document_id)->lockForUpdate()->first();
            if (!$document || !$document->enabled || ($pending_only && $document->status !== Document::STATUS_PENDING)) {
                return null;
            }
            if ($document->content_generation !== $generation || self::embeddingFingerprint() !== $fingerprint) {
                return $document->id;
            }

            $document->status = Document::STATUS_FAILED;
            $document->last_error = Errors::message($error, 'Indexing document '.$document->id.':');
            $document->save();

            return null;
        });
        if ($current) {
            self::queueCurrent($current);
        }
    }

    protected static function queueCurrent($document_id)
    {
        if (self::available() && Document::whereKey($document_id)->where('enabled', true)->exists()) {
            \App\Jobs\AiIndexDocument::dispatch($document_id);
        }
    }

    /**
     * Embeddings of texts, in batches.
     */
    public static function embed(array $texts, ?float $deadline = null, $mailbox_id = null)
    {
        $deadline = $deadline ?? microtime(true) + 120;
        if (!self::available()) {
            throw new \Exception('The embedding provider does not support embeddings');
        }
        Providers::configure();

        $embeddings = [];
        foreach (array_chunk(array_values($texts), self::EMBEDDING_BATCH) as $batch) {
            $remaining = (int) floor($deadline - microtime(true));
            if ($remaining < 1) {
                throw new \RuntimeException('AI document deadline exceeded');
            }
            [$reservation_id, $limit] = Usage::reserve($mailbox_id);
            if ($limit) {
                throw new \RuntimeException(__('This mailbox has used its AI tokens for today.'));
            }
            $response = null;
            $error = null;
            $reported = false;
            $started = hrtime(true);
            try {
                $response = Embeddings::for($batch)->cache(0)->timeout(min(120, $remaining))->generate(Providers::EMBEDDINGS, Settings::embeddingModel());
                if (count($response->embeddings) !== count($batch)) {
                    throw new \Exception('Embeddings count does not match chunk count');
                }
                array_push($embeddings, ...$response->embeddings);
            } catch (\Throwable $e) {
                $error = $e;
                throw $e;
            } finally {
                try {
                    $record = Usage::record($response, Usage::FEATURE_EMBEDDING, null, $mailbox_id, null, count($batch), [
                        'status'      => $error ? Usage::STATUS_FAILED : Usage::STATUS_OK,
                        'provider'    => Settings::embeddingProvider(),
                        'model'       => mb_substr(Settings::embeddingModel(), 0, 191),
                        'duration_ms' => (int) round((hrtime(true) - $started) / 1e6),
                        'error'       => $error ? $error->getMessage() : null,
                    ]);
                    $reported = $record && $record->input_tokens !== null;
                } finally {
                    Usage::releaseReservation($reservation_id, !$reported);
                }
            }
        }

        return $embeddings;
    }

    /**
     * Paragraphs packed into chunks of at most $size characters; the end of
     * a chunk is repeated at the start of the next ($overlap characters).
     */
    public static function chunks($markdown, $size, $overlap)
    {
        $markdown = preg_replace('/\A---\s*\R.*?\R---\s*/s', '', $markdown, 1);
        $markdown = trim(preg_replace("/\r\n?/", "\n", $markdown));
        if ($markdown === '') {
            return [];
        }
        $size = max(500, $size);
        $overlap = max(0, min($overlap, (int) floor($size / 2)));

        $chunks = [];
        $current = '';
        foreach (preg_split("/\n{2,}/", $markdown) as $paragraph) {
            $paragraph = trim($paragraph);
            if ($paragraph === '') {
                continue;
            }
            if (mb_strlen($paragraph) > $size) {
                if ($current !== '') {
                    $chunks[] = $current;
                    $current = '';
                }
                for ($offset = 0; $offset < mb_strlen($paragraph); $offset += max(1, $size - $overlap)) {
                    $chunks[] = mb_substr($paragraph, $offset, $size);
                }
                continue;
            }
            $candidate = $current === '' ? $paragraph : $current."\n\n".$paragraph;
            if (mb_strlen($candidate) <= $size) {
                $current = $candidate;
                continue;
            }
            $chunks[] = $current;
            $available_overlap = max(0, $size - mb_strlen($paragraph) - 2);
            $tail = $overlap && $available_overlap ? trim(mb_substr($current, -min($overlap, $available_overlap))) : '';
            $current = $tail !== '' ? $tail."\n\n".$paragraph : $paragraph;
        }
        if ($current !== '') {
            $chunks[] = $current;
        }

        return array_values(array_filter(array_map('trim', $chunks), 'strlen'));
    }

    /**
     * The mailbox's chunks most similar to a question, best first:
     * [score, document_id, title, url, content].
     */
    public static function search($mailbox_id, $question, $locale = Document::CANONICAL_LOCALE, $limit = null, ?float $deadline = null)
    {
        $question = trim((string) $question);
        if ($question === '') {
            return [];
        }
        $query = self::embed([$question], $deadline, $mailbox_id)[0];
        $locale = in_array($locale, Document::SUPPORTED_LOCALES) ? $locale : Document::CANONICAL_LOCALE;
        $fingerprint = self::embeddingFingerprint();
        $limit = max(1, (int) ($limit ?? Settings::retrievalLimit()));

        $results = [];
        DocumentChunk::select(['id', 'document_id', 'content', 'embedding'])
            ->with('document:id,title,source_url,localized_urls')
            ->where('embedding_model', Settings::embeddingModel())
            ->whereHas('document', function ($q) use ($mailbox_id, $fingerprint) {
                $q->where('mailbox_id', $mailbox_id)->where('enabled', true)->where('status', Document::STATUS_INDEXED)
                    ->where('embedding_fingerprint', $fingerprint);
            })
            ->orderBy('id')
            ->chunk(200, function ($chunks) use (&$results, $query, $locale, $limit) {
                foreach ($chunks as $chunk) {
                    $score = self::similarity($query, (array) $chunk->embedding);
                    if ($score >= self::MIN_SIMILARITY) {
                        if (count($results) >= $limit && $score <= $results[count($results) - 1]['score']) {
                            continue;
                        }
                        $result = [
                            'score'       => $score,
                            'document_id' => $chunk->document_id,
                            'title'       => $chunk->document->title,
                            'url'         => $chunk->document->localizedUrl($locale),
                            'content'     => $chunk->content,
                        ];
                        $position = 0;
                        while ($position < count($results) && $results[$position]['score'] >= $score) {
                            $position++;
                        }
                        array_splice($results, $position, 0, [$result]);
                        if (count($results) > $limit) {
                            array_pop($results);
                        }
                    }
                }
            });

        return $results;
    }

    /**
     * Cosine similarity; 0 for vectors of different lengths.
     */
    public static function similarity(array $a, array $b)
    {
        if (count($a) !== count($b) || !count($a)) {
            return 0.0;
        }
        $dot = $norm_a = $norm_b = 0.0;
        foreach ($a as $i => $value) {
            $dot += $value * $b[$i];
            $norm_a += $value * $value;
            $norm_b += $b[$i] * $b[$i];
        }

        return $norm_a > 0 && $norm_b > 0 ? $dot / (sqrt($norm_a) * sqrt($norm_b)) : 0.0;
    }
}
