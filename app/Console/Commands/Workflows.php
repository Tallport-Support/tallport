<?php

namespace App\Console\Commands;

use App\Workflows\Runner;
use Illuminate\Console\Command;

class Workflows extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tallport:workflows
        {--seconds=240 : Stop after this long; the scheduler continues later}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run automatic workflows whose conditions depend on time (waiting since, last reply, created)';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('Workflows run: '.Runner::processDue((int) $this->option('seconds')));

        return 0;
    }
}
