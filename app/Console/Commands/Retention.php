<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Settings » Retention: expires and deletes conversations, customers and logs
 * (App\Retention\Retention). Daily; --sweep-files weekly.
 */
class Retention extends Command
{
    protected $signature = 'tallport:retention
                            {--dry-run : Only count what would be removed}
                            {--sweep-files : Remove stored files no record points to}';

    protected $description = 'Remove what the retention settings no longer keep';

    public function handle()
    {
        $dry_run = (bool) $this->option('dry-run');
        if ($this->option('sweep-files')) {
            $this->line(($dry_run ? 'Files to remove: ' : 'Files removed: ').\App\Retention\Retention::sweepFiles($dry_run));

            return;
        }
        if (!$dry_run && !\App\Retention\Retention::isEnabled()) {
            $this->line('Retention is off (Settings » Retention): logs only.');
        }
        foreach (\App\Retention\Retention::run($dry_run) as $step => $count) {
            $this->line($step.': '.(is_array($count) ? json_encode($count) : $count));
        }
    }
}
