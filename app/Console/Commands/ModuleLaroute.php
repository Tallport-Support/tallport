<?php

namespace App\Console\Commands;

use App\Misc\JsRoutes;
use Illuminate\Console\Command;

class ModuleLaroute extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'freescout:module-laroute {module_alias?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate a laravel routes JS-file for a module or all modules (if module_alias is empty)';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $all = false;
        $modules = [];

        // Create a symlink for the module (or all modules)
        $module_alias = $this->argument('module_alias');
        if (!$module_alias) {
            $modules = \Module::all();

            $modules_aliases = [];
            foreach ($modules as $module) {
                $modules_aliases[] = $module->name;
            }
            if (!$modules_aliases) {
                $this->error('No modules found');

                return;
            }
            $all = true;
            // $all = $this->confirm('You have not specified a module alias, would you like to generate routes for all available modules ('.implode(', ', $modules_aliases).')?');
            // if (!$all) {
            //     return;
            // }
        }

        if ($all) {
            foreach ($modules as $module) {
                $this->generateModuleRoutes($module);
            }
        } else {
            $module = \Module::findByAlias($module_alias);
            if (!$module) {
                $this->error('Module with the specified alias not found: '.$module_alias);

                return;
            }
            $this->generateModuleRoutes($module);
        }
    }

    public function generateModuleRoutes($module)
    {
        $this->line('Module: '.$module->getName());

        $public_symlink = public_path('modules').DIRECTORY_SEPARATOR.$module->getAlias();
        if (!file_exists($public_symlink)) {
            $this->error('Public symlink ['.$public_symlink.'] not found. Run module installation command first: php artisan freescout:module-install');

            return;
        }

        $file = 'public/modules/'.$module->getAlias().'/js/laroute.js';
        try {
            JsRoutes::generate('resources/assets/js/laroute_module.js', base_path($file), $module->getAlias());
            $this->info("Created: {$file}");
        } catch (\Exception $e) {
            $this->error($e->getMessage());
        }
    }
}
