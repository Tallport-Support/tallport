<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;

class Update extends Command
{
    use ConfirmableTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tallport:update {--force : Force the operation to run when in production.}';

    /**
     * The name FreeScout used, still accepted (modules, scripts, older updaters).
     *
     * @var array
     */
    protected $aliases = ['freescout:update'];

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update application to the latest version from GitHub';

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
        if (!$this->confirmToProceed()) {
            return;
        }

        if (config('app.disable_updating')) {
            $this->error('Updating is disabled (APP_DISABLE_UPDATING).');

            return;
        }

        // Needs at least 128M, but never lower the limit.
        $memory_limit = ini_get('memory_limit');
        if ($memory_limit != -1 && \Helper::phpIniSizeToBytes($memory_limit) < 128 * 1024 * 1024) {
            @ini_set('memory_limit', '128M');
        }

        if (\Updater::isNewVersionAvailable(config('app.version'))) {
            $this->info('Updating... This may take several minutes');

            try {
                // Script may fail here and stop with the error:
                // PHP Fatal error:  Allowed memory size of 94371840 bytes exhausted
                \Updater::update();
            } catch (\Exception $e) {
                $this->error('Error occurred: '.$e->getMessage());

                return;
            }

            if ($this->afterAppUpdate() !== 0) {
                $this->error('Updated, but tallport:after-app-update failed. Run it again: php artisan tallport:after-app-update');
            }
        } else {
            $this->info('You have the latest version installed: '.config('app.version'));
        }
    }

    /**
     * Run tallport:after-app-update in a new process: this one still runs
     * the old code, whose autoloader may point to files the update removed.
     *
     * @return int Exit code.
     */
    protected function afterAppUpdate()
    {
        passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg(base_path('artisan')).' tallport:after-app-update', $exit_code);

        return $exit_code;
    }
}
