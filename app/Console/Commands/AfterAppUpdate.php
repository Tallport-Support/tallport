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
    protected $signature = 'freescout:after-app-update';

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

        $this->call('freescout:clear-cache');
        $this->call('migrate', ['--force' => true]);
        $this->call('queue:restart');

        \Eventy::action('command.after_app_update');
    }
}
