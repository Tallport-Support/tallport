<?php

namespace App\Console\Commands;

use App\Misc\JsRoutes;
use Illuminate\Console\Command;

class LarouteGenerate extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'laroute:generate
        {--p|path= : Folder of the JS file (default: config laroute.path)}
        {--f|filename= : Name of the JS file without .js (default: config laroute.filename)}
        {--namespace= : JavaScript namespace (default: config laroute.namespace)}
        {--prefix= : Prefix for the generated URLs (default: config laroute.prefix)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate the routes file for JavaScript (public/js/laroute.js)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $path = $this->option('path') ?: config('laroute.path');
        $filename = $this->option('filename') ?: config('laroute.filename');
        $file = (str_starts_with($path, '/') ? $path : base_path($path)).'/'.$filename.'.js';

        try {
            JsRoutes::generate(config('laroute.template'), $file, null, array_filter([
                'namespace' => $this->option('namespace'),
                'prefix'    => $this->option('prefix'),
            ]));
            $this->info('Created: '.$path.'/'.$filename.'.js');
        } catch (\Exception $e) {
            $this->error($e->getMessage());
        }
    }
}
