<?php

namespace App\Console\Commands;

use App\Search\Indexer;
use Illuminate\Console\Command;

class SearchIndex extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tallport:search-index
        {--seconds=50 : Stop after this long; the scheduler continues a minute later}
        {--rebuild : Index every conversation again (search keeps working meanwhile)}
        {--prune : Remove conversations deleted without Tallport noticing}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Index conversations for search: new and changed ones, and existing ones after installing';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        if ($this->option('rebuild')) {
            Indexer::rebuild();
            $this->info('Every conversation will be indexed again.');
        }
        if ($this->option('prune')) {
            $this->info('Removed: '.Indexer::prune());
        }

        $count = Indexer::indexStale((int) $this->option('seconds'));
        [$indexed, $total] = Indexer::progress();
        $this->info('Indexed: '.$count.'; in the index: '.$indexed.' of '.$total.' conversations');

        return 0;
    }
}
