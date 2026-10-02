<?php

namespace App\Console\Commands;

use App\Ai\Document;
use App\Ai\Documents;
use Illuminate\Console\Command;

class AiIndexDocuments extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tallport:ai-index-documents
        {--fetch : Fetch documents added by URL again first}
        {--force : Index documents again even if unchanged}
        {--document= : Only this document (ID)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Index the AI Assistant documentation that is new or has changed';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        if (!Documents::available()) {
            $this->line('The embedding provider does not support embeddings: nothing to index.');

            return 0;
        }

        $documents = Document::where('enabled', true)->orderBy('id');
        if ($this->option('document')) {
            $documents->where('id', (int) $this->option('document'));
        }

        $failed = false;
        foreach ($documents->get() as $document) {
            try {
                if ($this->option('fetch') && $document->source_type == Document::SOURCE_TYPE_URL) {
                    Documents::refetch($document);
                }
                if (!$this->option('force') && !Documents::needsIndexing($document)) {
                    continue;
                }
                $count = Documents::index($document, true);
                $this->line('#'.$document->id.' '.$document->title.': '.$count.' chunks');
            } catch (\Throwable $e) {
                $failed = true;
                $document->status = Document::STATUS_FAILED;
                $document->last_error = mb_substr($e->getMessage(), 0, 2000);
                $document->save();
                $this->error('#'.$document->id.' '.$document->title.': '.$e->getMessage());
            }
        }

        return $failed ? 1 : 0;
    }
}
