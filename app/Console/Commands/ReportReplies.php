<?php

namespace App\Console\Commands;

use App\Reports\Replies;
use Illuminate\Console\Command;

class ReportReplies extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tallport:report-replies
        {--seconds=50 : Stop after this long; the scheduler continues a minute later}
        {--rebuild : Do every conversation again}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Record agents\' replies and response times for reports: new conversations, and existing ones after installing';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        if ($this->option('rebuild')) {
            \Option::set(Replies::CURSOR_OPTION, 0);
        }
        $count = Replies::updateNext((int) $this->option('seconds'));
        $this->info('Conversations done: '.$count);

        return 0;
    }
}
