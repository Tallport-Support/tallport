<?php

namespace App\Console\Commands;

use App\Incoming\ParserComparison;
use App\Incoming\RawSources;
use Illuminate\Console\Command;

class CompareParsers extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tallport:compare-parsers
        {--limit=0 : Compare at most this many of the newest emails (0 = all)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Compare how the current and the new mail parser read the kept incoming emails (prints field names and thread IDs only)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $files = glob(storage_path('app/'.RawSources::FOLDER.'/*.eml')) ?: [];
        usort($files, function ($a, $b) {
            return filemtime($b) <=> filemtime($a);
        });
        if ((int) $this->option('limit') > 0) {
            $files = array_slice($files, 0, (int) $this->option('limit'));
        }

        $differences = [];
        foreach ($files as $file) {
            foreach (ParserComparison::compareRaw(file_get_contents($file)) as $field) {
                $differences[$field][] = basename($file, '.eml');
            }
        }
        ksort($differences);

        $this->line('Emails compared: '.count($files));
        if (!$differences) {
            $this->info('No differences.');

            return 0;
        }
        foreach ($differences as $field => $threads) {
            $this->line($field.': '.count($threads).' (threads '.implode(', ', array_slice($threads, 0, 20)).(count($threads) > 20 ? ', ...' : '').')');
        }

        return 0;
    }
}
