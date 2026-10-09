<?php

namespace App\Ai;

use Illuminate\Support\Facades\Http;
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
        if (!Document::isHttpUrl($markdown_url) || !\Helper::checkUrlIpAndHost($markdown_url)) {
            throw new \Exception(__('Only public http and https URLs can be fetched.'));
        }

        $remaining = $deadline === null ? 30 : (int) floor($deadline - microtime(true));
        if ($remaining < 1) {
            throw new \RuntimeException('AI document deadline exceeded');
        }
        $response = Http::withOptions(\Helper::setGuzzleDefaultOptions([
            'timeout'         => min(30, $remaining),
            'connect_timeout' => min(10, $remaining),
            'allow_redirects' => [
                'max'         => 5,
                'protocols'   => ['http', 'https'],
                'on_redirect' => function ($request, $response, $uri) {
                    if (!\Helper::checkUrlIpAndHost((string) $uri)) {
                        throw new \Exception(__('Only public http and https URLs can be fetched.'));
                    }
                },
            ],
        ]))->withUserAgent('Tallport-AI-Assistant')->get($markdown_url);

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
        $markdown = self::fetch($document->source_url, $deadline);
        if ($markdown['hash'] !== $document->content_hash) {
            $document->title = mb_substr($markdown['title'], 0, 191);
            $document->content = $markdown['content'];
            $document->content_hash = $markdown['hash'];
            $document->status = Document::STATUS_PENDING;
            $document->save();
        }
    }

    /**
     * Whether a document has to be (re)indexed.
     */
    public static function needsIndexing(Document $document)
    {
        return $document->status !== Document::STATUS_INDEXED
            || !$document->chunks()->where('embedding_model', Settings::embeddingModel())->exists();
    }

    /**
     * Chunk and embed a document's content. Returns the number of chunks.
     */
    public static function index(Document $document, $force = false, ?float $deadline = null)
    {
        $deadline = $deadline ?? microtime(true) + 180;
        if (!$force && !self::needsIndexing($document)) {
            return $document->chunks()->count();
        }

        try {
            $chunks = self::chunks((string) $document->content, Settings::chunkSize(), Settings::chunkOverlap());
            if (!$chunks) {
                throw new \Exception('Document has no indexable content');
            }
            $embeddings = self::embed($chunks, $deadline);
            $model = Settings::embeddingModel();

            \DB::transaction(function () use ($document, $chunks, $embeddings, $model) {
                $document->chunks()->delete();
                foreach ($chunks as $i => $chunk) {
                    $document->chunks()->create([
                        'chunk_index'     => $i,
                        'content'         => $chunk,
                        'content_hash'    => hash('sha256', $chunk),
                        'token_count'     => (int) ceil(mb_strlen($chunk) / 4),
                        'embedding'       => $embeddings[$i],
                        'embedding_model' => $model,
                    ]);
                }
                $document->status = Document::STATUS_INDEXED;
                $document->last_indexed_at = now();
                $document->last_error = null;
                $document->save();
            });
        } catch (\Throwable $e) {
            $document->status = Document::STATUS_FAILED;
            $document->last_error = Errors::message($e, 'Indexing document '.$document->id.':');
            $document->save();

            throw $e;
        }

        return count($chunks);
    }

    /**
     * Embeddings of texts, in batches.
     */
    public static function embed(array $texts, ?float $deadline = null)
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
            $response = Embeddings::for($batch)->timeout(min(120, $remaining))->generate(Providers::EMBEDDINGS, Settings::embeddingModel());
            if (count($response->embeddings) !== count($batch)) {
                throw new \Exception('Embeddings count does not match chunk count');
            }
            array_push($embeddings, ...$response->embeddings);
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
            $tail = $overlap ? trim(mb_substr($current, -$overlap)) : '';
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
        $query = self::embed([$question], $deadline)[0];
        $locale = in_array($locale, Document::SUPPORTED_LOCALES) ? $locale : Document::CANONICAL_LOCALE;

        $results = [];
        DocumentChunk::with('document')
            ->where('embedding_model', Settings::embeddingModel())
            ->whereHas('document', function ($q) use ($mailbox_id) {
                $q->where('mailbox_id', $mailbox_id)->where('enabled', true)->where('status', Document::STATUS_INDEXED);
            })
            ->orderBy('id')
            ->chunk(200, function ($chunks) use (&$results, $query, $locale) {
                foreach ($chunks as $chunk) {
                    $score = self::similarity($query, (array) $chunk->embedding);
                    if ($score >= self::MIN_SIMILARITY) {
                        $results[] = [
                            'score'       => $score,
                            'document_id' => $chunk->document_id,
                            'title'       => $chunk->document->title,
                            'url'         => $chunk->document->localizedUrl($locale),
                            'content'     => $chunk->content,
                        ];
                    }
                }
            });

        usort($results, function ($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        return array_slice($results, 0, $limit ?: Settings::retrievalLimit());
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
