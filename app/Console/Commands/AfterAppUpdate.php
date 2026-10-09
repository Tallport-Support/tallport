<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class AfterAppUpdate extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tallport:after-app-update';

    /**
     * The name FreeScout used, still accepted (modules, scripts, older updaters).
     *
     * @var array
     */
    protected $aliases = ['freescout:after-app-update'];

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run commands after application has been updated';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        // .env in the standard syntax (once; afterwards nothing changes).
        $env_path = $this->laravel->environmentFilePath();
        if (is_writable($env_path)) {
            $changed = \App\Misc\EnvFile::standardize($env_path);
            if ($changed) {
                $this->info('.env: '.$changed.' line(s) rewritten in the standard syntax; the original is saved next to it as .env.backup-*');
            }
        }

        $this->call('tallport:clear-cache');
        $this->call('migrate', ['--force' => true]);
        if (\App\Job::moveUnprefixedRedisJobs()) {
            $this->info('Redis: queued jobs moved to the prefixed keys');
        }
        $this->call('queue:restart');

        \App\Ai\Documents::queueStale();

        // System Status says for a while that stopped commands are expected to restart.
        \Option::set('app_updated_at', time());

        \Eventy::action('command.after_app_update');
    }
}
