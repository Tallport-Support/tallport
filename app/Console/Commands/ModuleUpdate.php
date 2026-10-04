<?php
/**
 * php artisan tallport:module-install modulealias.
 */

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ModuleUpdate extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tallport:module-update {module_alias?}';

    /**
     * The name FreeScout used, still accepted (modules, scripts, older updaters).
     *
     * @var array
     */
    protected $aliases = ['freescout:module-update'];

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update all modules or a single module (if module_alias is set)';

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
        // We have to clear modules cache first to update modules cache
        \Artisan::call('cache:clear');

        $module_alias = $this->argument('module_alias');
        if ($module_alias && !\Module::findByAlias($module_alias)) {
            $this->error('Module with the following alias not found: '.$module_alias);

            return;
        }

        // Tallport's own modules, from their latestVersionUrl (not FreeScout's directory).
        $counter = 0;
        foreach (\App\Module::availableUpdates() as $alias => $update) {
            if ($module_alias && $alias != $module_alias) {
                continue;
            }
            $update_result = \App\Module::updateModule($alias);

            $this->info('['.$update_result['module_name'].' Module'.']');
            if ($update_result['status'] == 'success') {
                $this->line($update_result['msg_success']);
            } else {
                $msg = $update_result['msg'];
                if ($update_result['download_msg']) {
                    $msg .= ' ('.$update_result['download_msg'].')';
                }
                $this->error('ERROR: '.$msg);
            }
            if (trim($update_result['output'])) {
                $this->line(preg_replace("#\n#", "\n> ", '> '.trim($update_result['output'])));
            }
            $counter++;
        }

        if (!$counter) {
            $this->line('All modules are up-to-date');
        }

        \Artisan::call('tallport:clear-cache');
    }
}
