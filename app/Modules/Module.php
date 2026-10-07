<?php

namespace App\Modules;

use Illuminate\Container\Container;
use Nwidart\Modules\Json;

/**
 * A FreeScout module (nwidart's module, with FreeScout's additions):
 * - its active flag is stored in the modules table, not in module.json;
 * - a module whose registration fails is passed to the
 *   modules.register_error filter, which deactivates it;
 * - module.json is read from the modules cache when possible;
 * - it remembers the path it was scanned from (Modules may be a symlink).
 */
class Module extends \Nwidart\Modules\Laravel\Module
{
    /**
     * The module path as it has been scanned, before realpath() resolved it.
     * When the Modules folder is a symlink leading outside of the application
     * folder, this is the only path which is still relative to base_path().
     *
     * @var string
     */
    protected $scanned_path;

    /**
     * The constructor.
     *
     * @param  Container  $app
     * @param  string  $name
     * @param  string  $path
     */
    public function __construct(Container $app, $name, $path)
    {
        parent::__construct($app, $name, $path);
        $this->scanned_path = $path;
    }

    /**
     * Get the path the module has been scanned from, with symlinks not resolved.
     *
     * @return string
     */
    public function getScannedPath()
    {
        return $this->scanned_path ?: $this->path;
    }

    /**
     * Get json contents from the cache, setting the active flag from the
     * modules table.
     *
     * @param  string  $file
     * @return Json
     */
    public function json($file = null) : Json
    {
        if ($file === null) {
            $file = 'module.json';
        }

        return array_get($this->moduleJson, $file, function () use ($file) {
            // nwidart's per-file cache doesn't work:
            // https://github.com/nWidart/laravel-modules/issues/659
            $cachedManifestsArray = $this->app['cache']->get($this->app['config']->get('modules.cache.key'));

            if ($cachedManifestsArray && count($cachedManifestsArray)) {
                foreach ($cachedManifestsArray as $manifest) {
                    if (!empty($manifest['name']) && $manifest['name'] == $this->getName()) {
                        return $this->moduleJson[$file] = new \App\Modules\Json($this->getPath().'/'.$file, $this->app['files'], $manifest);
                    }
                }
            }

            $json = new \App\Modules\Json($this->getPath().'/'.$file, $this->app['files']);
            $json->set('active', (int) \App\Module::isActive($json->get('alias')));

            return $this->moduleJson[$file] = $json;
        });
    }

    /**
     * Register the service providers from this module.
     */
    public function registerProviders()
    {
        try {
            parent::registerProviders();
        } catch (\Throwable $e) {
            $this->registerError($e);
        }
    }

    /**
     * Register the files from this module.
     */
    protected function registerFiles()
    {
        try {
            parent::registerFiles();
        } catch (\Throwable $e) {
            $this->registerError($e);
        }
    }

    /**
     * Let the modules.register_error filter handle a failed registration
     * (AppServiceProvider deactivates the module); rethrow if it doesn't.
     *
     * @param  \Throwable  $e
     */
    protected function registerError($e)
    {
        $e = \Eventy::filter('modules.register_error', $e, $this);
        if ($e) {
            throw $e;
        }
    }

    /**
     * Set active state for current module (in the modules table).
     *
     * @param  bool  $active
     */
    public function setActive($active)
    {
        \App\Module::setActive($this->getAlias(), $active);
    }

    /**
     * Check if module is official.
     *
     * @return bool
     */
    public function isOfficial()
    {
        return \App\Module::isOfficial($this->get('authorUrl'));
    }

}
