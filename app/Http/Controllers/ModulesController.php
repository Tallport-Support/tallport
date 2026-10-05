<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
//use Nwidart\Modules\Traits\CanClearModulesCache;
use Symfony\Component\Console\Output\BufferedOutput;

class ModulesController extends Controller
{
    //use CanClearModulesCache;

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('auth');
    }

    /**
     * Modules.
     */
    public function modules(Request $request)
    {
        $installed_modules = [];
        $flashes = [];

        $flash = \Cache::get('modules_flash');
        if ($flash) {
            if (is_array($flash) && !isset($flash['text'])) {
                $flashes = $flash;
            } else {
                $flashes[] = $flash;
            }
            \Cache::forget('modules_flash');
        }

        // Get installed modules
        \Module::clearCache();
        $modules = \Module::all();
        foreach ($modules as $module) {
            $module_data = [
                'alias'                        => $module->getAlias(),
                'name'                         => $module->getName(),
                'description'                  => $module->getDescription(),
                'version'                      => $module->get('version'),
                'detailsUrl'                   => $module->get('detailsUrl'),
                'author'                       => $module->get('author'),
                'authorUrl'                    => $module->get('authorUrl'),
                'requiredAppVersion'           => $module->get('requiredAppVersion'),
                'requiredPhpExtensions'        => $module->get('requiredPhpExtensions'),
                'requiredPhpExtensionsMissing' => \App\Module::getMissingExtensions($module->get('requiredPhpExtensions')),
                'requiredModulesMissing'       => \App\Module::getMissingModules($module->get('requiredModules'), $modules),
                'img'                          => $module->get('img'),
                'active'                       => $module->active(), //\App\Module::isActive($module->getAlias()),
                'installed'                    => true,
                // Update configuration for third party modules
                'latestVersionNumberUrl'       => $module->get('latestVersionUrl'),
                'latestVersionZipUrl'          => $module->get('latestVersionZipUrl'),
                // Determined later
                'new_version'        => '',
            ];
            $module_data = \App\Module::formatModuleData($module_data);
            $installed_modules[] = $module_data;
        }

        // No need, as we update modules list on each page load
        // Clear modules cache if any module has been added or removed
        // if (count($modules) != count(Module::getCached())) {
        //     $this->clearCache();
        // }

        // New versions are checked by App\Livewire\ModuleUpdates, after the page has opened.
        // Check modules symlinks. Somestimes instead of symlinks folders with files appear.
        $invalid_symlinks = \App\Module::checkSymlinks(
            collect($installed_modules)->where('active', true)->pluck('alias')->toArray()
        );

        return view('modules/modules', [
            'installed_modules' => $installed_modules,
            'flashes'           => $flashes,
            'invalid_symlinks'  => $invalid_symlinks,
        ]);
    }

    /**
     * Ajax.
     */
    public function ajax(Request $request)
    {
        $response = [
            'status' => 'error',
            'msg'    => '', // this is error message
        ];

        switch ($request->action) {

            case 'activate':
                $alias = $request->alias;
                $module = \Module::findByAlias($alias);

                if (!$module) {
                    $response['msg'] = __('Module not found').': '.$alias;
                }

                // Module folders named as their providers expect.
                if (!$response['msg']) {
                    // Check folder names for custom modules o avoid errors after activation:
                    // Class "Modules\CustomModule\Providers\CustomModuleServiceProvider" not found.
                    try {
                        $old_path = '';
                        $correct_path = '';
                        foreach (\Module::getScanPaths() as $key => $path) {
                            $manifests = \Module::getFiles()->glob("{$path}/module.json");

                            is_array($manifests) || $manifests = [];

                            // Determine correct module folder name from providers in module.json.
                            // Each module must have at least on "provider" specified.
                            foreach ($manifests as $manifest) {
                                $manifest_json = \App\Modules\Json::make($manifest);
                                if ($manifest_json->get('alias') != $alias) {
                                    continue;
                                }

                                $providers = $manifest_json->get('providers');
                                $correct_folder_name = $providers[0] ?? '';
                                $correct_folder_name = preg_replace('#^Modules\\\\([^\\\\]+)\\\\.*#', '$1', $correct_folder_name);
                                if (!$correct_folder_name) {
                                    break;
                                }
                                // Rename module's folder into correct name.
                                $old_path = str_replace('/module.json', '', $manifest);
                                $correct_path = \Module::getPath().'/'.$correct_folder_name;

                                if (\File::exists($old_path) && !\File::exists($correct_path)) {
                                    \File::move($old_path, $correct_path);
                                     // Re-scan and re-cache modules.
                                    \Module::scan();
                                }
                                break;
                            }
                        }
                    } catch (\Exception $e) {
                        if ($old_path && $correct_path) {
                            $response['msg'] = __('Rename ":old_path" into ":new_path"', [
                                'old_path' => str_replace(\Module::getPath(), '/Modules', $old_path),
                                'new_path' => str_replace(\Module::getPath(), '/Modules', $correct_path),
                            ]);
                        } else {
                            \Helper::logException($e, '[Modules] Error occured checking module folder name on activation');
                        }
                    }
                
                }

                if (!$response['msg']) {
                    \App\Module::setActive($alias, true);

                    $user_locale = app()->getLocale();

                    $outputLog = new BufferedOutput();
                    \Artisan::call('tallport:module-install', ['module_alias' => $alias], $outputLog);
                    $output = $outputLog->fetch();

                    // Get module name
                    $name = '?';
                    if ($module) {
                        $name = $module->getName();
                    }

                    // After clearing cache the locale may be not set.
                    \Helper::setUserLocale($user_locale);

                    $type = 'danger';
                    $msg = __('Error occurred activating ":name" module', ['name' => $name]);
                    if (session('flashes_floating') && is_array(session('flashes_floating'))) {
                        // If there was any error, module has been deactivated via modules.register_error filter
                        $msg = '';
                        foreach (session('flashes_floating') as $flash) {
                            $msg .= $flash['text'].' ';
                        }
                    } elseif (strstr($output, 'Configuration cached successfully')) {
                        $type = 'success';
                        $msg = __('":name" module successfully activated!', ['name' => $name]);
                    } else {
                        // Deactivate the module.
                        \App\Module::setActive($alias, false);
                        \Artisan::call('tallport:clear-cache');
                    }

                    // Check public folder.
                    if ($module && file_exists($module->getPath().DIRECTORY_SEPARATOR.'Public')) {
                        $symlink_path = public_path().\Module::getPublicPath($alias);
                        if (!file_exists($symlink_path)) {
                            $type = 'danger';
                            $msg = 'Error occurred creating a module symlink ('.$symlink_path.'). Please check folder permissions.';
                            \App\Module::setActive($alias, false);
                            \Artisan::call('tallport:clear-cache');
                        }
                    }

                    if ($type == 'success') {
                        // Migrate again, in case migration did not work in the moment the module was activated.
                        \Artisan::call('migrate', ['--force' => true]);
                    }

                    // \Session::flash does not work after BufferedOutput
                    $flash = [
                        'text'      => '<strong>'.$msg.'</strong><pre class="margin-top">'.$output.'</pre>',
                        'unescaped' => true,
                        'type'      => $type,
                    ];
                    \Cache::forever('modules_flash', $flash);
                    $response['status'] = 'success';
                }

                break;

            case 'deactivate':
                $alias = $request->alias;
                \App\Module::setActive($alias, false);

                $user_locale = app()->getLocale();

                $outputLog = new BufferedOutput();
                \Artisan::call('tallport:clear-cache', [], $outputLog);
                $output = $outputLog->fetch();

                // Get module name
                $module = \Module::findByAlias($alias);
                $name = '?';
                if ($module) {
                    $name = $module->getName();
                }

                // After clearing cache the locale may be not set.
                \Helper::setUserLocale($user_locale);

                $type = 'danger';
                $msg = __('Error occurred deactivating :name module', ['name' => $name]);
                if (strstr($output, 'Configuration cached successfully')) {
                    $type = 'success';
                    $msg = __('":name" module successfully Deactivated!', ['name' => $name]);
                }

                // \Session::flash does not work after BufferedOutput
                $flash = [
                    'text'      => '<strong>'.$msg.'</strong><pre class="margin-top">'.$output.'</pre>',
                    'unescaped' => true,
                    'type'      => $type,
                ];
                \Cache::forever('modules_flash', $flash);
                $response['status'] = 'success';
                break;

            case 'delete':
                $alias = $request->alias;

                $module = \Module::findByAlias($alias);

                if ($module) {

                    // Deactivate first: an active module without files breaks the app.
                    \App\Module::deactiveModule($alias);
                    $module->delete();
                    \Session::flash('flash_success_floating', __('Module deleted'));
                    $response['status'] = 'success';
                } else {
                    $response['msg'] = __('Module not found').': '.$alias;
                }
                break;

            case 'update':
                $update_result = \App\Module::updateModule($request->alias);

                if ($update_result['download_error']) {
                    $response['reload'] = true;

                    if ($update_result['msg']) {
                        \Session::flash('flash_error_floating', $update_result['msg']);
                    }

                    if ($update_result['download_msg']) {
                        \Session::flash('flash_error_unescaped', $update_result['download_msg']);
                    }
                }

                // Install updated module.
                if ($update_result['output'] || $update_result['status']) {

                    $type = 'danger';
                    $msg = $update_result['msg'];

                    if ($update_result['status'] == 'success') {
                        $type = 'success';
                        $msg = $update_result['msg_success'];
                    }

                    // \Session::flash does not work after BufferedOutput
                    $flash = [
                        'text'      => '<strong>'.$msg.'</strong><pre class="margin-top">'.$update_result['output'].'</pre>',
                        'unescaped' => true,
                        'type'      => $type,
                    ];
                    \Cache::forever('modules_flash', $flash);
                    $response['status'] = 'success';
                }

                break;

            case 'update_all':
                $update_all_flashes = [];

                foreach ($request->aliases as $alias) {
                    $update_result = \App\Module::updateModule($alias);

                    $type = 'danger';
                    $msg = $update_result['msg'];

                    if ($update_result['status'] == 'success') {
                        $type = 'success';
                        $msg = $update_result['msg_success'];
                    } elseif ($update_result['download_msg']) {
                        $msg .= '<br/>'.$update_result['download_msg'];
                    }

                    $text = '<strong>'.$update_result['module_name'].':</strong> '.$msg;
                    if (trim($update_result['output'])) {
                        $text .= '<pre class="margin-top">'.$update_result['output'].'</pre>';
                    }

                    // \Session::flash does not work after BufferedOutput
                    $update_all_flashes[] = [
                        'text'      => $text,
                        'unescaped' => true,
                        'type'      => $type,
                    ];
                }
                if ($update_all_flashes) {
                    \Cache::forever('modules_flash', $update_all_flashes);
                }
                $response['status'] = 'success';

                break;

            default:
                $response['msg'] = 'Unknown action';
                break;
        }

        if ($response['status'] == 'error' && empty($response['msg'])) {
            $response['msg'] = 'Unknown error occurred';
        }

        return \Response::json($response);
    }
}
