<?php

namespace App\Jobs;

use App\Ai\Document;
use App\Ai\Documents;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Fetch (URL documents) and index a document for the AI Assistant. A
 * failure is shown with the document.
 */
class AiIndexDocument implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public $document_id;

    public $fetch;

    public $force;

    public $content_generation;

    public $embedding_fingerprint;

    public $tries = 1;

    public $timeout = 240;

    public $uniqueFor = 900;

    public function __construct($document_id, $fetch = false, $force = false)
    {
        $this->onConnection(\Helper::queueConnection('ai'));
        $this->onQueue('ai');
        $this->document_id = $document_id;
        $this->fetch = $fetch;
        $this->force = $force;
        $this->content_generation = Document::whereKey($document_id)->value('content_generation');
        $this->embedding_fingerprint = Documents::embeddingFingerprint();
    }

    public function uniqueId()
    {
        return $this->document_id;
    }

    public function handle()
    {
        $deadline = microtime(true) + 180;
        $document = Document::find($this->document_id);
        if (!$document || !$document->enabled || !Documents::available()) {
            return;
        }

        if ($this->fetch && $document->source_type == Document::SOURCE_TYPE_URL) {
            try {
                Documents::refetch($document, $deadline);
            } catch (\Throwable $e) {
                Documents::failIfCurrent($document->id, $this->content_generation, $this->embedding_fingerprint, $e);

                return;
            }
        }
        try {
            Documents::index($document, $this->force, $deadline);
        } catch (\Throwable $e) {
            // Documents::index() has recorded the failure if it still applies.
        }
    }

    public function failed(\Throwable $e)
    {
        Documents::failIfCurrent($this->document_id, $this->content_generation, $this->embedding_fingerprint, $e, true);
    }
}
