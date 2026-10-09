<?php

namespace App\Jobs;

use App\Ai\Document;
use App\Ai\Documents;
use App\Ai\Errors;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Fetch (URL documents) and index a document for the AI Assistant. A
 * failure is shown with the document.
 */
class AiIndexDocument implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public $document_id;

    public $fetch;

    public $force;

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

        try {
            if ($this->fetch && $document->source_type == Document::SOURCE_TYPE_URL) {
                Documents::refetch($document, $deadline);
            }
            Documents::index($document, $this->force, $deadline);
        } catch (\Throwable $e) {
            $document->status = Document::STATUS_FAILED;
            $document->last_error = Errors::message($e, 'Indexing document '.$document->id.':');
            $document->save();
        }
    }

    public function failed(\Throwable $e)
    {
        $document = Document::find($this->document_id);
        if (!$document || $document->status != Document::STATUS_PENDING) {
            return;
        }

        $document->status = Document::STATUS_FAILED;
        $document->last_error = Errors::message($e, 'Indexing document '.$document->id.':');
        $document->save();
    }
}
