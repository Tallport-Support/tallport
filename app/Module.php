<?php
/**
 * 'active' parameter in module.json is not taken in account.
 * Module 'active' flag is taken from DB.
 */

namespace App;

use Illuminate\Database\Eloquent\Model;
use Symfony\Component\Console\Output\BufferedOutput;

class Module extends Model
{
    const IMG_DEFAULT = '/img/default-module.png';

    public $timestamps = false;

    /**
     * Modules list cached in memory.
     */
    public static $modules;

    public static function getCached()
    {
        if (!self::$modules) {
            // At this stage modules table may not exist
            try {
                self::$modules = self::all();
            } catch (\Exception $e) {
                // Do nothing
            }
        }

        return self::$modules;
    }

    public static function clearModulesCache()
    {
        self::$modules = null;
    }

    public static function isActive($alias)
    {
        $module = self::getByAlias($alias);
        if ($module) {
            return $module->active;
        } else {
            return false;
        }
    }

    public static function setActive($alias, $active, $save = true)
    {
        $module = self::getByAliasOrCreate($alias);
        $module->active = $active;
        if ($save) {
            try {
                $module->save();
            } catch (\Exception $e) {
                if (strstr($e->getMessage(), 'Integrity constraint violation')) {
                    // SQLSTATE[23000]: Integrity constraint violation.
                    self::clearModulesCache();
                    $module = self::getByAliasOrCreate($alias);
                    if ($module) {
                        $module->active = $active;
                        $module->save();
                    }
                } else {
                    throw $e;
                }
            }
        }

        return true;
    }

    /**
     * Modules whose update check failed in availableUpdates(): alias => [name, error].
     */
    public static $update_check_errors = [];

    /**
     * New versions of the installed modules, by alias, from each module's own
     * latestVersionUrl (kept for 15 minutes per Tallport version). FreeScout's directory isn't asked.
     */
    public static function availableUpdates()
    {
        $updates = [];
        self::$update_check_errors = [];

        foreach (\Module::all() as $module) {
            $url = $module->get('latestVersionUrl');
            if (self::isOfficial($module->get('authorUrl')) || !$url) {
                continue;
            }
            // Per Tallport version: an update of Tallport (when people look for module
            // updates) asks again. A failed request isn't kept (null isn't cached).
            $latest_version = \Cache::remember('module_latest_version.'.config('app.version').'.'.md5($url), now()->addMinutes(15), function () use ($url, $module) {
                try {
                    $body = trim((string) (new \GuzzleHttp\Client())->request('GET', $url, \Helper::setGuzzleDefaultOptions())->getBody());
                } catch (\Exception $e) {
                    self::$update_check_errors[$module->getAlias()] = [self::formatName($module->getName()), $e->getMessage()];

                    return null;
                }
                // It may be the module.json file.
                if (preg_match('#"version":[^"]*"([\d\.]+)"#', $body, $m)) {
                    return $m[1];
                }

                return $body;
            });

            if ($latest_version && version_compare($latest_version, (string) $module->get('version'), '>')) {
                $updates[$module->getAlias()] = ['name' => self::formatName($module->getName()), 'version' => $latest_version];
            }
        }

        return $updates;
    }

    public static function isOfficial($author_url)
    {
        return parse_url($author_url ?? '', PHP_URL_HOST) == parse_url(\Config::get('app.freescout_url') ?? '', PHP_URL_HOST);
    }

    public static function getByAliasOrCreate($alias)
    {
        $module = self::getByAlias($alias);
        if (!$module) {
            $module = new self();
            $module->alias = $alias;
        }

        return $module;
    }

    public static function normalizeAlias($alias)
    {
        return trim(strtolower($alias));
    }

    public static function getByAlias($alias)
    {
        $modules = self::getCached();
        if ($modules) {
            return self::getCached()->where('alias', $alias)->first();
        } else {
            return;
        }
    }

    /**
     * Deactivate module and update modules cache.
     */
    public static function deactiveModule($alias, $clear_app_cache = true)
    {
        self::setActive($alias, false);
        // Update modules cache
        \Module::clearCache();
        if ($clear_app_cache) {
            \Artisan::call('tallport:clear-cache');
        }
    }

    /**
     * Check missing extensions among required by module.
     *
     * @param [type] $required_extensions [description]
     *
     * @return [type] [description]
     */
    public static function getMissingExtensions($required_extensions)
    {
        $missing = [];

        $list = explode(',', $required_extensions ?? '');
        if (!is_array($list) || !count($list)) {
            return [];
        }
        foreach ($list as $ext) {
            $ext = trim($ext);
            if ($ext && !extension_loaded($ext)) {
                $missing[] = $ext;
            }
        }

        return $missing;
    }

    /**
     * Check missing modules required by the module.
     */
    public static function getMissingModules($required_modules, $modules = [])
    {
        $missing = [];

        if (!$modules) {
            $modules = \Module::all();
        }

        if (!is_array($required_modules) || !count($required_modules)) {
            return [];
        }
        foreach ($required_modules as $alias => $version) {
            $module = null;
            foreach ($modules as $module_item) {
                if ($module_item->alias == $alias) {
                    $module = $module_item;
                }
            }
            if (!$module) {
                $missing[$alias] = $version;
                continue;
            }

            if (!self::isActive($alias) || !version_compare($module->version, $version, '>=')) {
                $missing[$alias] = $version;
            }
        }

        return $missing;
    }

    public static function formatName($name)
    {
        return preg_replace("/ Module($|.*\[.*\]$)/", '$1', $name);
    }

    public static function formatModuleData($module_data)
    {
        // Add (Third-Party).
        if (self::isThirdParty($module_data) && mb_substr(trim($module_data['name']), -1) != ']') {
            $module_data['name'] = $module_data['name'].' ['.__('Third-Party').']';
        }
        return $module_data;
    }

    public static function isThirdParty($module_data)
    {
        if (\App\Module::isOfficial($module_data['detailsUrl']) 
            && $module_data['author'] != 'FreeScout'
        ) {
            return true;
        } else {
            return false;
        }
    }

    public static function getSymlinkPath($alias)
    {
        return public_path().\Module::getPublicPath($alias);
    }

    // Check and try to fix invalid or missing symlinks.
    public static function checkSymlinks($module_aliases = null)
    {
        $invalid_symlinks = [];

        if ($module_aliases === null) {
            // Get all active modules.
            foreach (\Module::all() as $module) {
                if ($module->active()) {
                    $module_aliases[] = $module->getAlias();
                }
            }
        }
        if ($module_aliases && count($module_aliases)) {
            foreach ($module_aliases as $module_alias) {
                $from = self::getSymlinkPath($module_alias);

                $create = false;

                // file_exists() also checks if symlink target exists.
                // file_exists() and is_dir() may throw "open_basedir restriction in effect".
                try {
                    if (!file_exists($from) || !is_link($from)) {
                        if (is_dir($from)) {
                            @rename($from, $from.'_'.date('YmdHis'));
                        } else {
                            @unlink($from);
                        }
                        $create = true;
                    } 
                } catch (\Exception $e) {
                    $create = true;
                }

                // Skip this check.
                // elseif (is_link($from) && readlink($symlink_path) != '') {
                //     // Symlink leads to the wrong place.
                //     $create = true;
                // }

                // Try to create the symlink.
                if ($create) {
                    $to = self::createModuleSymlink($module_alias);

                    if ($to && (!is_link($from) || is_link($to) || !file_exists($from))) {
                        $invalid_symlinks[$from] = $to;
                    }
                }
            }
        }

        return $invalid_symlinks;
    }

    // There is similar function in ModuleInstall.php
    public static function createModuleSymlink($alias)
    {
        $from = self::getSymlinkPath($alias);

        $module = \Module::findByAlias($alias);
        if (!$module) {
            return false;
        }

        $to = $module->getExtraPath('Public');

        // file_exists() may throw "open_basedir restriction in effect".
        try {
            // If module's Public is symlink.
            if (is_link($to)) {
                @unlink($to);
            }

            // Symlimk may exist but lead to the module folder in a wrong case.
            // So we need first try to remove it.
            if (!file_exists($from)) {
                @unlink($from);
            }

            if (file_exists($from)) {
                return $to;
            }

            if (!file_exists($to)) {
                // Try to create Public folder.
                try {
                    \File::makeDirectory($to, \Helper::DIR_PERMISSIONS);
                } catch (\Exception $e) {
                    // If it's a broken symlink.
                    if (is_link($to)) {
                        @unlink($to);
                    }
                }
            }

            try {
                symlink($to, $from);
            } catch (\Exception $e) {
                \Log::error('Error occurred creating ['.$from.' » '.$to.'] symlink: '.$e->getMessage());
                //return false;
            }
        } catch (\Exception $e) {
            return false;
        }

        return $to;
    }

    public static function updateModule($alias)
    {
        $result = [
            'status' => 'error',
            // Error message.
            'msg' => '',
            'msg_success' => '',
            'download_error' => false,
            // Error message with the link for downloading the module.
            'download_msg' => '',
            'output' => '',
            'module_name' => '',
        ];

        $module = \Module::findByAlias($alias);

        if (!$module) {
            $result['msg'] = __('Module not found').': '.$alias;
        }

        // Get module name.
        $name = '?';
        if ($module) {
            $name = $module->getName();
            $result['module_name'] = $name;
        }

        // Download new version.
        if (!$result['msg']) {
            // From the module's own download address (latestVersionZipUrl).
            $latest_version_zip_url = $module->latestVersionZipUrl ?? null;

            if (!empty($latest_version_zip_url)) {
                // Update the module from the provided ZIP URL
                $result = self::updateFromUrl($module, $module->latestVersionZipUrl, $result);
            } else {
                // If no download link is available, set an error message indicating the module cannot be downloaded
                $result['msg'] = __('Error occurred') . ': module not available for download';
            }
        }

        // Run post-update instructions.
        if (!$result['msg'] && !$result['download_error']) {
            $output_log = new BufferedOutput();
            \Artisan::call('tallport:module-install', ['module_alias' => $alias], $output_log);
            $result['output'] = $output_log->fetch() ?: ' ';

            $result['msg'] = __('Error occurred activating ":name" module', ['name' => $name]);

            if (session('flashes_floating') && is_array(session('flashes_floating'))) {
                // Error.
                // If there was any error, module has been deactivated via modules.register_error filter
                $result['msg'] = '';
                foreach (session('flashes_floating') as $flash) {
                    $result['msg'] .= $flash['text'].' ';
                }
            } elseif (strstr($result['output'], 'Configuration cached successfully')) {
                // Success.
                $result['status'] = 'success';
                $result['msg'] = '';
                $result['msg_success'] = __('":name" module successfully updated!', ['name' => $name]);
            } else {
                // Error.
                // Deactivate module.
                \App\Module::setActive($alias, false);
                \Artisan::call('tallport:clear-cache');
            }
        }

        return $result;
    }

    /**
     * Updates a module from a given URL.
     *
     * @param Module $module The module to be updated.
     * @param string $url The URL where the new version of the module can be downloaded.
     * @param array $result An associative array to store the result of the update operation.
     *
     * @return void
     */
    private static function updateFromUrl($module, $url, $result)
    {
        $alias = $module->alias;
        // Download module.
        $module_archive = \Module::getPath() . DIRECTORY_SEPARATOR . $alias . '.zip';

        try {
            \Helper::downloadRemoteFile($url, $module_archive);
        } catch (\Exception $e) {
            $result['msg'] = $e->getMessage();
        }

        if (!file_exists($module_archive)) {
            $result['download_error'] = true;
        } else {
            // Extract.
            try {
                $module_path = $module->getPath();

                // Sometimes by some reason Public folder becomes a symlink leading to itself.
                // It causes an error during updating process.
                // https://github.com/freescout-helpdesk/freescout/issues/2709
                $public_folder = $module_path.DIRECTORY_SEPARATOR.'Public';
                try {
                    if (is_link($public_folder)) {
                        unlink($public_folder);
                    }
                } catch (\Exception $e) {
                    // Do nothing.
                }

                \Helper::unzip($module_archive, \Module::getPath());

                // Rename the folder if the archive has been downloaded from GitHub:
                // SomeModule-master >> SomeModule.
                // https://github.com/freescout-help-desk/freescout/issues/4611
                $basename = basename($url, '.zip');

                if (file_exists($module_path.'-'.$basename)) {
                    \File::moveDirectory($module_path.'-'.$basename, $module_path, true);
                }
            } catch (\Exception $e) {
                $result['msg'] = $e->getMessage();
            }
            // Check if extracted module exists.
            \Module::clearCache();
            $module = \Module::findByAlias($alias);
            if (!$module) {
                $result['download_error'] = true;
            }
        }

        // Remove archive.
        if (file_exists($module_archive)) {
            \File::delete($module_archive);
        }

        if ($result['download_error']) {
            $result['download_msg'] = __('Error occurred downloading the module. Please :%a_being%download:%a_end% module manually and extract into :folder', [
                '%a_being%' => '<a href="' . $url . '" target="_blank">',
                '%a_end%'   => '</a>',
                'folder'    => '<strong>' . \Module::getPath() . '</strong>',
            ]);
        }

        return $result;
    }
}
